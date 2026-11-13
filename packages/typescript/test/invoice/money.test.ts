import { describe, expect, it } from 'vitest';
import { Decimal, Fa3InvoiceBuilder, InvoiceBuilderException, Money, VatRate } from '../../src/invoice/index.js';

describe('Decimal / Money', () => {
  it.each([
    ['2.345', 2, '2.35'],
    ['2.344999', 2, '2.34'],
    ['2.30', 2, '2.30'],
    ['-2.345', 2, '-2.35'],
    ['-0.001', 2, '0.00'],
    ['0.005', 2, '0.01'],
    ['0.004', 2, '0.00'],
    ['99.995', 2, '100.00'],
    ['7', 2, '7.00'],
    ['1.0000005', 6, '1.000001'],
    ['0', 0, '0'],
    ['-0.5', 0, '-1'],
  ])('round half up %s @%i => %s', (input, scale, out) => {
    expect(Decimal.of(input).toFixed(scale)).toBe(out);
  });

  it('rejects floats, NaN, unsafe integers and non-numeric types', () => {
    for (const bad of [1.5, 0.1, NaN, Infinity, Number.MAX_SAFE_INTEGER + 2, true, null, undefined, {}]) {
      expect(() => Decimal.of(bad as never), typeof bad).toThrow(InvoiceBuilderException);
    }
    expect(() => Money.of(0.1)).toThrow(InvoiceBuilderException);
    expect(() => Fa3InvoiceBuilder.create().addLine('x', 1.5, '1', '23')).toThrow(InvoiceBuilderException);
    expect(() => Fa3InvoiceBuilder.create().addLine('x', 1, 9.99, '23')).toThrow(InvoiceBuilderException);
  });

  it('accepts bigint and safe integers', () => {
    expect(Decimal.of(12n).toString()).toBe('12');
    expect(Decimal.of(-7).toFixed(2)).toBe('-7.00');
    expect(Money.of(5).toString()).toBe('5.00');
  });

  it.each(['', ' 1', '1,5', '1e3', '01', '.5', '1.', '+1', '--1', 'NaN', '1\n', '0x10'])('rejects malformed decimal %j', (s) => {
    expect(() => Decimal.of(s)).toThrow(InvoiceBuilderException);
  });

  it('Money.exact rejects sub-grosz precision', () => {
    expect(Money.exact('1.2').toString()).toBe('1.20');
    expect(() => Money.exact('1.001')).toThrow(InvoiceBuilderException);
  });

  it('arithmetic is exact', () => {
    expect(Decimal.of('0.1').add('0.2').toString()).toBe('0.3');
    expect(Decimal.of('0.333').mul('100').toString()).toBe('33.3');
    expect(Decimal.of('0.1').sub('0.2').toString()).toBe('-0.1');
    expect(Decimal.of('0.1').compare('0.10001')).toBe(-1);
    expect(Decimal.of('-0.00').toString()).toBe('0');
    expect(Decimal.of('12.3400').significantScale()).toBe(2);
    expect(Decimal.of('0.5').integerDigits()).toBe(0);
    expect(Decimal.of('-123.4').integerDigits()).toBe(3);
    expect(Decimal.of('99999999999999999999.99').add('0.01').toString()).toBe('100000000000000000000');
  });

  it('rate to bucket mapping and unsupported rates', () => {
    const map: Record<string, string> = { '23': '1', '22': '1', '8': '2', '7': '2', '5': '3', '0 KR': '6_1', '0 WDT': '6_2', '0 EX': '6_3', zw: '7', 'np I': '8', 'np II': '9' };
    for (const [tok, bucket] of Object.entries(map)) expect(VatRate.parse(tok).bucket(), tok).toBe(bucket);
    for (const tok of ['4', '3', 'oo']) expect(() => VatRate.parse(tok)).toThrow(/not supported/);
    expect(() => VatRate.parse('24')).toThrow(/Unknown VAT rate/);
  });

  it('mixing rates of one bucket is rejected', () => {
    const b = Fa3InvoiceBuilder.create().addLine('a', 1, '10', '23').addLine('b', 1, '10', '22');
    expect(() => b.totals()).toThrow(InvoiceBuilderException);
  });

  /** Mulberry32 PRNG: deterministic, no dependencies. */
  function prng(seed: number): () => number {
    let a = seed;
    return () => {
      a = (a + 0x6d2b79f5) | 0;
      let t = Math.imul(a ^ (a >>> 15), 1 | a);
      t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }

  it('property: 1500 random invoices match an integer-grosze oracle', () => {
    const rnd = prng(20261113);
    const ri = (lo: number, hi: number): number => lo + Math.floor(rnd() * (hi - lo + 1));
    const toks = ['23', '8', '5', '0 KR', 'zw', 'np I'] as const;
    const pct: Record<string, number | null> = { '23': 23, '8': 8, '5': 5, '0 KR': null, zw: null, 'np I': null };
    const bucketOf: Record<string, string> = { '23': '1', '8': '2', '5': '3', '0 KR': '6_1', zw: '7', 'np I': '8' };
    const fmt = (c: number): string => `${Math.floor(c / 100)}.${String(c % 100).padStart(2, '0')}`;
    for (let iter = 0; iter < 1500; iter++) {
      const b = Fa3InvoiceBuilder.create();
      const sumNet = new Map<string, number>();
      const n = ri(1, 25);
      for (let i = 0; i < n; i++) {
        const qMilli = ri(1, 20000);
        const pCents = ri(0, 500000);
        const tok = toks[ri(0, toks.length - 1)] as (typeof toks)[number];
        const q = `${Math.floor(qMilli / 1000)}.${String(qMilli % 1000).padStart(3, '0')}`;
        b.addLine(`L${i}`, q, fmt(pCents), tok);
        const netCents = Math.floor((2 * qMilli * pCents + 1000) / 2000);
        const bk = bucketOf[tok] as string;
        sumNet.set(bk, (sumNet.get(bk) ?? 0) + netCents);
      }
      const t = b.totals();
      let gross = 0;
      const expected: Record<string, string> = {};
      for (const [bk, net] of sumNet) {
        const tok = Object.keys(bucketOf).find((k) => bucketOf[k] === bk) as string;
        expected[`P_13_${bk}`] = fmt(net);
        gross += net;
        const p = pct[tok];
        if (p !== null && p !== undefined) {
          const vat = Math.floor((2 * net * p + 100) / 200);
          expected[`P_14_${bk}`] = fmt(vat);
          gross += vat;
        }
      }
      expected['P_15'] = fmt(gross);
      const got = t.toArray().fields;
      expect(Object.fromEntries(Object.entries(got).sort()), `iteration ${iter}`).toEqual(Object.fromEntries(Object.entries(expected).sort()));
      expect(t.gross.equals(t.net().add(t.vat()))).toBe(true);
    }
  });
});
