import { describe, expect, it } from 'vitest';
import { Fa3Address, Fa3InvoiceBuilder, InvoiceBuilderException, XmlValidator } from '../../src/invoice/index.js';
import { FIXTURES_DIR, readText, validCases } from './helpers.js';

const base = (): Fa3InvoiceBuilder =>
  Fa3InvoiceBuilder.create()
    .seller('5260250274', 'Seller', new Fa3Address('ul. A 1'))
    .buyerNip('1234563218', 'Buyer', new Fa3Address('ul. B 2'))
    .number('FV/1')
    .issueDate('2026-01-15')
    .createdAt('2026-01-15T10:00:00Z');

const rejects = (f: () => unknown, fragment: string): void => {
  expect(f).toThrow(InvoiceBuilderException);
  expect(f).toThrow(fragment);
};
const rejectsAsync = async (f: () => Promise<unknown>, fragment: string): Promise<void> => {
  await expect(f()).rejects.toThrow(InvoiceBuilderException);
  await expect(f()).rejects.toThrow(fragment);
};

describe('Fa3InvoiceBuilder', () => {
  it('demands missing pieces before any XML', async () => {
    await rejectsAsync(() => Fa3InvoiceBuilder.create().build(), 'Required field');
    await rejectsAsync(() => base().build(), 'At least one line');
    await rejectsAsync(
      () =>
        Fa3InvoiceBuilder.create()
          .seller('5260250274', 'S', new Fa3Address('a'))
          .buyerNoId('B', new Fa3Address('b'))
          .number('1')
          .issueDate('2026-01-15')
          .addLine('x', 1, '1', '23')
          .build(),
      'createdAt',
    );
  });

  it('rejects unsupported variants', () => {
    rejects(() => base().currency('EUR'), 'not supported');
    for (const r of ['oo', '4', '3']) rejects(() => base().addLine('x', 1, '1', r), 'not supported');
    rejects(() => base().addLine('x', 1, '1', '24'), 'Unknown VAT rate');
    rejects(() => base().addLine('x', 1, '-1', '23'), '>= 0');
    rejects(() => base().addLine('x', 0, '1', '23'), '> 0');
    rejects(() => Fa3InvoiceBuilder.fromModel({ rodzajFaktury: 'KOR' }), 'unknown key');
  });

  it('enforces conditional rules', async () => {
    await rejectsAsync(() => base().addLine('x', 1, '1', 'zw').build(), 'exemptionBasis');
    await rejectsAsync(() => base().addLine('x', 1, '1', '23').exemptionBasis('art. 43').build(), 'no line uses');
    await rejectsAsync(() => base().addLine('x', 1, '1', '0 WDT').build(), 'EU VAT buyer');
    const xml = await base().addLine('x', 1, '1', 'zw').exemptionBasis('art. 43 ust. 1').build();
    expect(xml).toContain('<P_19>1</P_19>');
    expect(xml).toContain('<P_19A>art. 43 ust. 1</P_19A>');
  });

  it('validates fields', () => {
    const b = base();
    const a = new Fa3Address('a');
    rejects(() => b.seller('123456789', 'S', a), 'TNrNIP');
    rejects(() => b.seller('0012345678', 'S', a), 'TNrNIP');
    rejects(() => b.issueDate('2026-02-30'), 'real date');
    rejects(() => b.issueDate('15-01-2026'), 'YYYY-MM-DD');
    rejects(() => b.issueDate('2051-01-01'), 'outside');
    rejects(() => b.number('a'.repeat(257)), '257 characters');
    rejects(() => b.number('   '), 'empty');
    rejects(() => b.createdAt('2025-08-31T23:59:59Z'), 'outside');
    rejects(() => b.createdAt('2026-01-15 10:00:00'), 'UTC');
    rejects(() => b.createdAt(new Date(NaN)), 'UTC');
    rejects(() => b.buyerEu('XX', '1', 'n', a), 'EU VAT prefix');
    rejects(() => b.buyerEu('DE', 'abc', 'n', a), 'euVatNumber');
    rejects(() => b.paymentMethod(8), '1..7');
    rejects(() => b.bankAccount('123'), '10..34');
    rejects(() => b.addLine('x', '1.1234567', '1', '23'), 'TIlosci');
    rejects(() => b.addLine('x', '1', '1.123456789', '23'), 'TKwotowy2');
    rejects(() => b.addLine('x', 1, '1', '23', null, '1.001'), 'fractional');
  });

  it('fromModel/fromJson are strict and reject floats', () => {
    const m = JSON.parse(readText(`${FIXTURES_DIR}/01-single-line-23.input.json`)) as { lines: Record<string, unknown>[] };
    const line = m.lines[0] as Record<string, unknown>;
    line['quantity'] = 1.5;
    rejects(() => Fa3InvoiceBuilder.fromModel(m), 'floats are rejected');
    rejects(() => Fa3InvoiceBuilder.fromJson(JSON.stringify(m)), 'floats are rejected');
    line['quantity'] = '1';
    line['extra'] = 1;
    rejects(() => Fa3InvoiceBuilder.fromModel(m), 'unknown key');
    rejects(() => Fa3InvoiceBuilder.fromJson('{'), 'Invalid JSON');
    rejects(() => Fa3InvoiceBuilder.fromModel([]), 'object');
  });

  it('collapses whitespace in token fields like the XSD does', async () => {
    const xml = await base().addLine('  a \t\n  b  ', 1, '1', '23').build();
    expect(xml).toContain('<P_7>a b</P_7>');
  });

  it('sample() is valid, unique and dated today (UTC)', async () => {
    const xml = await Fa3InvoiceBuilder.sampleXml('5260250274', `LIVE/${Math.random().toString(16).slice(2, 10)}`);
    expect((await new XmlValidator().validateFa3(xml)).isValid()).toBe(true);
    expect(xml).toContain(`<P_1>${new Date().toISOString().slice(0, 10)}</P_1>`);
    const fixed = await Fa3InvoiceBuilder.sampleXml('5260250274', 'X/1', new Date('2026-03-01T12:00:00+02:00'));
    expect(fixed).toContain('<DataWytworzeniaFa>2026-03-01T10:00:00Z</DataWytworzeniaFa>');
    expect(fixed).toContain('<Termin>2026-03-15</Termin>');
  });

  it('NIP checksum helper', () => {
    expect(Fa3InvoiceBuilder.isValidNipChecksum('5260250274')).toBe(true);
    expect(Fa3InvoiceBuilder.isValidNipChecksum('1234563218')).toBe(true);
    expect(Fa3InvoiceBuilder.isValidNipChecksum('1234563219')).toBe(false);
    expect(Fa3InvoiceBuilder.isValidNipChecksum('123')).toBe(false);
  });

  it('all fixture NIPs have valid checksums', () => {
    for (const n of validCases()) {
      const m = JSON.parse(readText(`${FIXTURES_DIR}/${n}.input.json`)) as { seller: { nip: string }; buyer: { nip?: string } };
      expect(Fa3InvoiceBuilder.isValidNipChecksum(m.seller.nip), n).toBe(true);
      if (m.buyer.nip !== undefined) expect(Fa3InvoiceBuilder.isValidNipChecksum(m.buyer.nip), n).toBe(true);
    }
  });

  it('buildUnvalidated is sync and equals build()', async () => {
    const b = base().addLine('x', 1, '1', '23');
    expect(b.buildUnvalidated()).toBe(await b.build());
  });
});
