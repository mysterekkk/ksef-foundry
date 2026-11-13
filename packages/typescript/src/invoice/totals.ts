import { Money } from './decimal.js';
import { InvoiceBuilderException } from './errors.js';
import type { VatRate } from './vat-rate.js';

export interface Fa3Bucket {
  readonly rate: VatRate;
  readonly net: Money;
  readonly vat: Money | null;
}

export interface Fa3TotalsShape {
  readonly lineNets: string[];
  readonly fields: Record<string, string>;
}

const BUCKET_ORDER: Readonly<Record<string, number>> = {
  '1': 1, '2': 2, '3': 3, '4': 4, '6_1': 6, '6_2': 7, '6_3': 8, '7': 9, '8': 10, '9': 11, '10': 12,
};

/**
 * FA(3) totals per rate bucket (P_13_n net, P_14_n VAT) and gross P_15.
 *
 * Rounding policy (documented decision, XSD/official guidance are silent):
 *  - line net (P_11)  = round_half_up(P_8B x P_9A, 2)  unless an explicit net value is supplied;
 *  - bucket net P_13_n = sum of rounded line nets in that bucket;
 *  - bucket VAT P_14_n = round_half_up(P_13_n x rate / 100, 2)   (VAT per bucket, never per line);
 *  - P_15 = sum(P_13_n) + sum(P_14_n).
 */
export class Fa3Totals {
  private constructor(
    /** Keyed by bucket suffix ("1","2","6_1",...), iteration order = XSD element order. */
    readonly buckets: ReadonlyMap<string, Fa3Bucket>,
    readonly gross: Money,
    readonly lineNets: readonly Money[],
  ) {}

  static calculate(lines: readonly { readonly net: Money; readonly rate: VatRate }[]): Fa3Totals {
    const nets = new Map<string, { rate: VatRate; net: Money }>();
    const lineNets: Money[] = [];
    for (const l of lines) {
      lineNets.push(l.net);
      const b = l.rate.bucket();
      const cur = nets.get(b);
      if (cur === undefined) {
        nets.set(b, { rate: l.rate, net: Money.of(0).add(l.net) });
      } else if (cur.rate !== l.rate) {
        throw new InvoiceBuilderException(
          `Rates "${cur.rate.value}" and "${l.rate.value}" share summary field P_13_${b}; they cannot be mixed on one invoice.`,
        );
      } else {
        cur.net = cur.net.add(l.net);
      }
    }
    const sorted = [...nets.entries()].sort(([a], [b]) => (BUCKET_ORDER[a] ?? 99) - (BUCKET_ORDER[b] ?? 99));
    const buckets = new Map<string, Fa3Bucket>();
    let gross = Money.of(0);
    for (const [b, data] of sorted) {
      const pct = data.rate.percent();
      let vat: Money | null = null;
      if (pct !== null) {
        vat = Money.of(data.net.decimal().mul(pct).mul('0.01'));
        gross = gross.add(vat);
      }
      gross = gross.add(data.net);
      buckets.set(b, { rate: data.rate, net: data.net, vat });
    }
    return new Fa3Totals(buckets, gross, lineNets);
  }

  net(): Money {
    let s = Money.of(0);
    for (const d of this.buckets.values()) s = s.add(d.net);
    return s;
  }

  vat(): Money {
    let s = Money.of(0);
    for (const d of this.buckets.values()) if (d.vat !== null) s = s.add(d.vat);
    return s;
  }

  /** Language-neutral shape used by fixtures/fa3/*.expected.totals.json. */
  toArray(): Fa3TotalsShape {
    const fields: Record<string, string> = {};
    for (const [b, d] of this.buckets) {
      fields[`P_13_${b}`] = d.net.toString();
      if (d.vat !== null) fields[`P_14_${b}`] = d.vat.toString();
    }
    fields['P_15'] = this.gross.toString();
    return { lineNets: this.lineNets.map((m) => m.toString()), fields };
  }

  toJSON(): Fa3TotalsShape {
    return this.toArray();
  }
}
