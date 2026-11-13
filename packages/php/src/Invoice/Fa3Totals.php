<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Invoice;

/**
 * FA(3) totals per rate bucket (P_13_n net, P_14_n VAT) and gross P_15.
 *
 * Rounding policy (documented decision, XSD/official guidance are silent):
 *  - line net (P_11)  = round_half_up(P_8B x P_9A, 2)  unless an explicit net value is supplied;
 *  - bucket net P_13_n = sum of rounded line nets in that bucket;
 *  - bucket VAT P_14_n = round_half_up(P_13_n x rate / 100, 2)   (VAT per bucket, never per line);
 *  - P_15 = sum(P_13_n) + sum(P_14_n).
 */
final class Fa3Totals implements \JsonSerializable
{
    /**
     * @param array<string, array{rate: VatRate, net: Money, vat: ?Money}> $buckets keyed by bucket suffix
     *        ("1", "2", "6_1", ...)
     * @param list<Money> $lineNets
     */
    private function __construct(
        public readonly array $buckets,
        public readonly Money $gross,
        public readonly array $lineNets,
    ) {
    }

    /** @param list<array{net: Money, rate: VatRate}> $lines */
    public static function calculate(array $lines): self
    {
        $buckets = [];
        $lineNets = [];
        foreach ($lines as $l) {
            $lineNets[] = $l['net'];
            $b = $l['rate']->bucket();
            if (!isset($buckets[$b])) {
                $buckets[$b] = ['rate' => $l['rate'], 'net' => Money::of(0), 'vat' => null];
            } elseif ($buckets[$b]['rate'] !== $l['rate']) {
                throw new InvoiceBuilderException(sprintf(
                    'Rates "%s" and "%s" share summary field P_13_%s; they cannot be mixed on one invoice.',
                    $buckets[$b]['rate']->value,
                    $l['rate']->value,
                    $b,
                ));
            }
            $buckets[$b]['net'] = $buckets[$b]['net']->add($l['net']);
        }
        uksort($buckets, static fn (string $a, string $b): int => self::order($a) <=> self::order($b));
        $gross = Money::of(0);
        foreach ($buckets as $b => $data) {
            $pct = $data['rate']->percent();
            if ($pct !== null) {
                $vat = Money::of($data['net']->decimal()->mul($pct)->mul('0.01'));
                $buckets[$b]['vat'] = $vat;
                $gross = $gross->add($vat);
            }
            $gross = $gross->add($data['net']);
        }
        return new self($buckets, $gross, $lineNets);
    }

    public function net(): Money
    {
        $s = Money::of(0);
        foreach ($this->buckets as $d) {
            $s = $s->add($d['net']);
        }
        return $s;
    }

    public function vat(): Money
    {
        $s = Money::of(0);
        foreach ($this->buckets as $d) {
            if ($d['vat'] !== null) {
                $s = $s->add($d['vat']);
            }
        }
        return $s;
    }

    /**
     * Language-neutral shape used by fixtures/*.expected.totals.json.
     *
     * @return array{lineNets: list<string>, fields: array<string, string>}
     */
    public function toArray(): array
    {
        $fields = [];
        foreach ($this->buckets as $b => $d) {
            $fields['P_13_' . $b] = $d['net']->toString();
            if ($d['vat'] !== null) {
                $fields['P_14_' . $b] = $d['vat']->toString();
            }
        }
        $fields['P_15'] = $this->gross->toString();
        return [
            'lineNets' => array_map(static fn (Money $m): string => $m->toString(), $this->lineNets),
            'fields' => $fields,
        ];
    }

    /** @return array{lineNets: list<string>, fields: array<string, string>} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function order(string $b): int
    {
        return match ($b) {
            '1' => 1, '2' => 2, '3' => 3, '4' => 4, '6_1' => 6, '6_2' => 7, '6_3' => 8,
            '7' => 9, '8' => 10, '9' => 11, '10' => 12, default => 99,
        };
    }
}
