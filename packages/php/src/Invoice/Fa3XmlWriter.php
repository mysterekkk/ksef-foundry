<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Invoice;

/**
 * Serialises an already validated builder model into FA(3) XML (element order = XSD sequence order).
 * All text goes through DOMDocument::createTextNode, so escaping is done by libxml.
 *
 * @internal
 * @phpstan-type Addr array{country: string, line1: string, line2: ?string}
 * @phpstan-type Seller array{nip: string, name: string, address: Addr}
 * @phpstan-type Buyer array{
 *     kind: 'nip'|'eu'|'foreign'|'none', nip: ?string, euPrefix: ?string, euNumber: ?string,
 *     country: ?string, taxId: ?string, name: string, address: Addr
 * }
 * @phpstan-type Line array{name: string, unit: ?string, qty: Decimal, price: Decimal, net: Money, rate: VatRate}
 * @phpstan-type Exemption array{type: 'statute'|'directive'|'other', text: string}
 * @phpstan-type Account array{number: string, swift: ?string, bankName: ?string, description: ?string}
 * @phpstan-type Payment array{paidDate: ?string, dueDates: list<string>, method: ?int, accounts: list<Account>}
 * @phpstan-type Model array{
 *     seller: Seller, buyer: Buyer, number: string, issueDate: string, issuePlace: ?string,
 *     saleDate: ?string, createdAt: string, systemInfo: ?string, lines: list<Line>,
 *     exemption: ?Exemption, payment: ?Payment, info: list<array{string, string}>, footer: ?string
 * }
 */
final class Fa3XmlWriter
{
    public const NS = 'http://crd.gov.pl/wzor/2025/06/25/13775/';

    private \DOMDocument $doc;

    /** @param Model $m validated model from Fa3InvoiceBuilder */
    public static function write(array $m, Fa3Totals $t): string
    {
        $w = new self();
        return $w->render($m, $t);
    }

    /** @param Model $m */
    private function render(array $m, Fa3Totals $t): string
    {
        $this->doc = new \DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = true;
        $root = $this->doc->createElementNS(self::NS, 'Faktura');
        $this->doc->appendChild($root);

        $h = $this->el($root, 'Naglowek');
        $kf = $this->el($h, 'KodFormularza', 'FA');
        $kf->setAttribute('kodSystemowy', 'FA (3)');
        $kf->setAttribute('wersjaSchemy', '1-0E');
        $this->el($h, 'WariantFormularza', '3');
        $this->el($h, 'DataWytworzeniaFa', $m['createdAt']);
        if ($m['systemInfo'] !== null) {
            $this->el($h, 'SystemInfo', $m['systemInfo']);
        }

        $p1 = $this->el($root, 'Podmiot1');
        $d = $this->el($p1, 'DaneIdentyfikacyjne');
        $this->el($d, 'NIP', $m['seller']['nip']);
        $this->el($d, 'Nazwa', $m['seller']['name']);
        $this->address($p1, 'Adres', $m['seller']['address']);

        $b = $m['buyer'];
        $p2 = $this->el($root, 'Podmiot2');
        $d = $this->el($p2, 'DaneIdentyfikacyjne');
        switch ($b['kind']) {
            case 'nip':
                $this->el($d, 'NIP', $b['nip']);
                break;
            case 'eu':
                $this->el($d, 'KodUE', $b['euPrefix']);
                $this->el($d, 'NrVatUE', $b['euNumber']);
                break;
            case 'foreign':
                if ($b['country'] !== null) {
                    $this->el($d, 'KodKraju', $b['country']);
                }
                $this->el($d, 'NrID', $b['taxId']);
                break;
            default:
                $this->el($d, 'BrakID', '1');
        }
        $this->el($d, 'Nazwa', $b['name']);
        $this->address($p2, 'Adres', $b['address']);
        $this->el($p2, 'JST', '2');
        $this->el($p2, 'GV', '2');

        $fa = $this->el($root, 'Fa');
        $this->el($fa, 'KodWaluty', 'PLN');
        $this->el($fa, 'P_1', $m['issueDate']);
        if ($m['issuePlace'] !== null) {
            $this->el($fa, 'P_1M', $m['issuePlace']);
        }
        $this->el($fa, 'P_2', $m['number']);
        if ($m['saleDate'] !== null) {
            $this->el($fa, 'P_6', $m['saleDate']);
        }
        foreach ($t->buckets as $suffix => $bk) {
            $this->el($fa, 'P_13_' . $suffix, $bk['net']->toString());
            if ($bk['vat'] !== null) {
                $this->el($fa, 'P_14_' . $suffix, $bk['vat']->toString());
            }
        }
        $this->el($fa, 'P_15', $t->gross->toString());

        $adn = $this->el($fa, 'Adnotacje');
        $this->el($adn, 'P_16', '2');
        $this->el($adn, 'P_17', '2');
        $this->el($adn, 'P_18', '2');
        $this->el($adn, 'P_18A', '2');
        $zw = $this->el($adn, 'Zwolnienie');
        if ($m['exemption'] !== null) {
            $this->el($zw, 'P_19', '1');
            $tag = ['statute' => 'P_19A', 'directive' => 'P_19B', 'other' => 'P_19C'][$m['exemption']['type']];
            $this->el($zw, $tag, $m['exemption']['text']);
        } else {
            $this->el($zw, 'P_19N', '1');
        }
        $nst = $this->el($adn, 'NoweSrodkiTransportu');
        $this->el($nst, 'P_22N', '1');
        $this->el($adn, 'P_23', '2');
        $pm = $this->el($adn, 'PMarzy');
        $this->el($pm, 'P_PMarzyN', '1');

        $this->el($fa, 'RodzajFaktury', 'VAT');

        foreach ($m['info'] as [$k, $v]) {
            $o = $this->el($fa, 'DodatkowyOpis');
            $this->el($o, 'Klucz', $k);
            $this->el($o, 'Wartosc', $v);
        }

        foreach ($m['lines'] as $i => $l) {
            $r = $this->el($fa, 'FaWiersz');
            $this->el($r, 'NrWierszaFa', (string) ($i + 1));
            $this->el($r, 'P_7', $l['name']);
            if ($l['unit'] !== null) {
                $this->el($r, 'P_8A', $l['unit']);
            }
            $this->el($r, 'P_8B', $l['qty']->toString());
            $this->el($r, 'P_9A', $l['price']->toFixed(max(2, $l['price']->significantScale())));
            $this->el($r, 'P_11', $l['net']->toString());
            $this->el($r, 'P_12', $l['rate']->value);
        }

        $pay = $m['payment'];
        if ($pay !== null) {
            $p = $this->el($fa, 'Platnosc');
            if ($pay['paidDate'] !== null) {
                $this->el($p, 'Zaplacono', '1');
                $this->el($p, 'DataZaplaty', $pay['paidDate']);
            }
            foreach ($pay['dueDates'] as $dd) {
                $tp = $this->el($p, 'TerminPlatnosci');
                $this->el($tp, 'Termin', $dd);
            }
            if ($pay['method'] !== null) {
                $this->el($p, 'FormaPlatnosci', (string) $pay['method']);
            }
            foreach ($pay['accounts'] as $a) {
                $rb = $this->el($p, 'RachunekBankowy');
                $this->el($rb, 'NrRB', $a['number']);
                if ($a['swift'] !== null) {
                    $this->el($rb, 'SWIFT', $a['swift']);
                }
                if ($a['bankName'] !== null) {
                    $this->el($rb, 'NazwaBanku', $a['bankName']);
                }
                if ($a['description'] !== null) {
                    $this->el($rb, 'OpisRachunku', $a['description']);
                }
            }
        }

        if ($m['footer'] !== null) {
            $s = $this->el($root, 'Stopka');
            $inf = $this->el($s, 'Informacje');
            $this->el($inf, 'StopkaFaktury', $m['footer']);
        }

        $xml = $this->doc->saveXML();
        if ($xml === false) {
            throw new \RuntimeException('Failed to serialise FA(3) document.');
        }
        return $xml;
    }

    /** @param Addr $a */
    private function address(\DOMElement $parent, string $name, array $a): void
    {
        $e = $this->el($parent, $name);
        $this->el($e, 'KodKraju', $a['country']);
        $this->el($e, 'AdresL1', $a['line1']);
        if ($a['line2'] !== null) {
            $this->el($e, 'AdresL2', $a['line2']);
        }
    }

    private function el(\DOMElement $parent, string $name, ?string $text = null): \DOMElement
    {
        $e = $this->doc->createElementNS(self::NS, $name);
        if ($text !== null) {
            $e->appendChild($this->doc->createTextNode($text));
        }
        $parent->appendChild($e);
        return $e;
    }
}
