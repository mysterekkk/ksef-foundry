import { Fa3Address } from './address.js';
import { Decimal, Money } from './decimal.js';
import type { DecimalInput } from './decimal.js';
import { InvoiceBuilderException } from './errors.js';
import { Fa3ModelLoader } from './model-loader.js';
import { Fa3Text } from './text.js';
import { Fa3Totals } from './totals.js';
import { VatRate } from './vat-rate.js';
import { XmlValidator } from './xml-validator.js';
import { writeFa3Xml } from './xml-writer.js';
import type { Addr, Buyer, ExemptionType, Fa3Model, Payment } from './xml-writer.js';

const EU = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'EL', 'HR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'XI'];

/**
 * Fluent builder for the SUPPORTED FA(3) variant: standard VAT invoice (RodzajFaktury = VAT),
 * PLN only, domestic rates 23/22/8/7/5, "0 KR", "0 EX", "0 WDT" (EU buyer only), zw, "np I", "np II".
 * Everything else is rejected with `InvoiceBuilderException` (see docs/dev/fa3-scope.md).
 *
 * Money inputs are string|bigint|safe-integer|Decimal, never float.
 * `build()` is async because the XSD self-check runs in a WASM worker; `buildUnvalidated()` is sync.
 */
export class Fa3InvoiceBuilder {
  readonly #m: Fa3Model = {
    seller: null, buyer: null, number: null, issueDate: null, issuePlace: null, saleDate: null,
    createdAt: null, systemInfo: null, lines: [], exemption: null, payment: null, info: [], footer: null,
  };
  #selfValidate = true;

  static create(): Fa3InvoiceBuilder {
    return new Fa3InvoiceBuilder();
  }

  /** Disable the final XSD self-check inside build() (not recommended). */
  withoutSelfValidation(): this {
    this.#selfValidate = false;
    return this;
  }

  seller(nip: string, name: string, address: Fa3Address): this {
    this.#m.seller = { nip: nipOf(nip, 'seller.nip'), name: Fa3Text.token(name, 512, 'seller.name'), address: addr(address, 'seller.address') };
    return this;
  }

  buyerNip(nip: string, name: string, address: Fa3Address): this {
    return this.#setBuyer(name, address, (n, a) => ({ kind: 'nip', nip: nipOf(nip, 'buyer.nip'), name: n, address: a }));
  }

  /** EU VAT buyer: `prefix` is one of TKodyKrajowUE (e.g. "DE", note "EL" for Greece), `vatNumber` up to 12 chars [0-9A-Z+*]. */
  buyerEu(prefix: string, vatNumber: string, name: string, address: Fa3Address): this {
    if (!EU.includes(prefix)) {
      throw new InvoiceBuilderException(`buyer.euPrefix "${prefix}" is not an EU VAT prefix allowed by the XSD (TKodyKrajowUE).`);
    }
    if (!/^[0-9A-Z+*]{1,12}$/.test(vatNumber)) {
      throw new InvoiceBuilderException('buyer.euVatNumber must match [0-9A-Z+*]{1,12} (without the country prefix).');
    }
    return this.#setBuyer(name, address, (n, a) => ({ kind: 'eu', euPrefix: prefix, euNumber: vatNumber, name: n, address: a }));
  }

  /** Non-EU / other tax id (NrID, 1..50 chars) with optional issuing country code. */
  buyerForeign(countryCode: string | null, taxId: string, name: string, address: Fa3Address): this {
    if (countryCode !== null && !/^[A-Z]{2}$/.test(countryCode)) {
      throw new InvoiceBuilderException('buyer.countryCode must be a 2-letter upper-case code.');
    }
    const id = Fa3Text.token(taxId, 50, 'buyer.taxId');
    return this.#setBuyer(name, address, (n, a) => ({ kind: 'foreign', country: countryCode, taxId: id, name: n, address: a }));
  }

  /** Buyer without any tax identifier (BrakID = 1). */
  buyerNoId(name: string, address: Fa3Address): this {
    return this.#setBuyer(name, address, (n, a) => ({ kind: 'none', name: n, address: a }));
  }

  number(invoiceNumber: string): this {
    this.#m.number = Fa3Text.token(invoiceNumber, 256, 'number (P_2)');
    return this;
  }

  issueDate(date: string): this {
    this.#m.issueDate = Fa3Text.date(date, 'issueDate (P_1)', '2006-01-01', '2050-01-01');
    return this;
  }

  issuePlace(place: string): this {
    this.#m.issuePlace = Fa3Text.token(place, 256, 'issuePlace (P_1M)');
    return this;
  }

  /** P_6: common date of supply, when it differs from the issue date. */
  saleDate(date: string): this {
    this.#m.saleDate = Fa3Text.date(date, 'saleDate (P_6)', '2006-01-01', '2050-01-01');
    return this;
  }

  /** Only "PLN" is supported. */
  currency(code: string): this {
    if (code !== 'PLN') {
      throw new InvoiceBuilderException(
        `Currency "${code}" is not supported: foreign-currency invoices need P_14_nW/KursWalutyZ semantics that this builder does not implement. Only PLN.`,
      );
    }
    return this;
  }

  /** DataWytworzeniaFa, UTC, format YYYY-MM-DDTHH:MM:SSZ (XSD minimum 2025-09-01). */
  createdAt(at: string | Date): this {
    let s: string;
    if (at instanceof Date) {
      if (Number.isNaN(at.getTime())) throw new InvoiceBuilderException('createdAt must be a real UTC timestamp YYYY-MM-DDTHH:MM:SSZ.');
      s = utcStamp(at);
    } else {
      s = at;
    }
    const x = typeof s === 'string' ? /^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):[0-5]\d:[0-5]\dZ$/.exec(s) : null;
    if (x === null || !Fa3Text.checkdate(Number(x[2]), Number(x[3]), Number(x[1]))) {
      throw new InvoiceBuilderException('createdAt must be a real UTC timestamp YYYY-MM-DDTHH:MM:SSZ.');
    }
    if (s < '2025-09-01T00:00:00Z' || s > '2050-01-01T23:59:59Z') {
      throw new InvoiceBuilderException('createdAt is outside the XSD range 2025-09-01T00:00:00Z..2050-01-01T23:59:59Z.');
    }
    this.#m.createdAt = s;
    return this;
  }

  systemInfo(name: string): this {
    this.#m.systemInfo = Fa3Text.token(name, 256, 'systemInfo');
    return this;
  }

  /**
   * @param quantity P_8B, > 0, max 6 fractional digits
   * @param unitNetPrice P_9A, >= 0, max 8 fractional digits
   * @param netValue explicit P_11 (2 digits); default round_half_up(quantity x price, 2)
   */
  addLine(
    name: string,
    quantity: DecimalInput,
    unitNetPrice: DecimalInput,
    vatRate: string | VatRate,
    unit: string | null = null,
    netValue: DecimalInput | null = null,
  ): this {
    const n = this.#m.lines.length + 1;
    if (n > 10000) throw new InvoiceBuilderException('FaWiersz is limited to 10000 lines.');
    const f = `lines[${n}]`;
    const qty = Decimal.of(quantity);
    const price = Decimal.of(unitNetPrice);
    if (qty.isNegative() || qty.isZero()) throw new InvoiceBuilderException(`${f}.quantity must be > 0.`);
    if (price.isNegative()) {
      throw new InvoiceBuilderException(`${f}.unitNetPrice must be >= 0 (corrections/discount invoices are unsupported).`);
    }
    if (qty.significantScale() > 6 || qty.integerDigits() > 16) {
      throw new InvoiceBuilderException(`${f}.quantity exceeds TIlosci (max 16 integer + 6 fractional digits).`);
    }
    if (price.significantScale() > 8 || price.integerDigits() > 14) {
      throw new InvoiceBuilderException(`${f}.unitNetPrice exceeds TKwotowy2 (max 14 integer + 8 fractional digits).`);
    }
    let net: Money;
    if (netValue !== null) {
      const nv = Decimal.of(netValue);
      if (nv.isNegative() || nv.significantScale() > 2) {
        throw new InvoiceBuilderException(`${f}.netValue must be >= 0 with at most 2 fractional digits.`);
      }
      net = Money.exact(nv);
    } else {
      net = Money.of(qty.mul(price));
    }
    if (net.decimal().integerDigits() > 16) throw new InvoiceBuilderException(`${f} net value exceeds 16 integer digits.`);
    const rate = vatRate instanceof VatRate ? vatRate : VatRate.parse(vatRate);
    if (!rate.isSupported()) throw new InvoiceBuilderException(`VAT rate "${rate.value}" is not supported.`);
    this.#m.lines.push({
      name: Fa3Text.token(name, 512, `${f}.name (P_7)`),
      unit: unit === null ? null : Fa3Text.token(unit, 256, `${f}.unit (P_8A)`),
      qty, price, net, rate,
    });
    return this;
  }

  /** Legal basis for exempt ("zw") lines: required when any line uses "zw". P_19A (act) / P_19B (directive) / P_19C (other). */
  exemptionBasis(text: string, type: ExemptionType = 'statute'): this {
    if (!['statute', 'directive', 'other'].includes(type)) {
      throw new InvoiceBuilderException('exemptionBasis type must be statute|directive|other.');
    }
    this.#m.exemption = { type, text: Fa3Text.token(text, 256, 'exemptionBasis') };
    return this;
  }

  /** FormaPlatnosci 1 cash, 2 card, 3 voucher, 4 cheque, 5 credit, 6 transfer, 7 mobile. */
  paymentMethod(method: number): this {
    if (!Number.isInteger(method) || method < 1 || method > 7) {
      throw new InvoiceBuilderException('paymentMethod must be 1..7 (TFormaPlatnosci).');
    }
    this.#pay().method = method;
    return this;
  }

  /** Marks the invoice as paid (Zaplacono=1, DataZaplaty). */
  paid(date: string): this {
    const d = Fa3Text.date(date, 'paidDate', '2016-07-01', '2050-01-01');
    this.#pay().paidDate = d;
    return this;
  }

  /** TerminPlatnosci/Termin (max 100). */
  dueDate(date: string): this {
    const p = this.#pay();
    if (p.dueDates.length >= 100) throw new InvoiceBuilderException('At most 100 due dates.');
    p.dueDates.push(Fa3Text.date(date, 'dueDate', '2016-07-01', '2050-01-01'));
    return this;
  }

  bankAccount(number: string, swift: string | null = null, bankName: string | null = null, description: string | null = null): this {
    const p = this.#pay();
    if (p.accounts.length >= 100) throw new InvoiceBuilderException('At most 100 bank accounts.');
    if (!/^[0-9A-Za-z]{10,34}$/.test(number)) {
      throw new InvoiceBuilderException('bankAccount.number must be 10..34 alphanumeric characters without spaces.');
    }
    if (swift !== null && !/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/.test(swift)) {
      throw new InvoiceBuilderException('bankAccount.swift must be a valid SWIFT/BIC (8 or 11 chars).');
    }
    p.accounts.push({
      number, swift,
      bankName: bankName === null ? null : Fa3Text.token(bankName, 256, 'bankAccount.bankName'),
      description: description === null ? null : Fa3Text.token(description, 256, 'bankAccount.description'),
    });
    return this;
  }

  /** DodatkowyOpis key/value note (both 1..256 chars). */
  additionalInfo(key: string, value: string): this {
    if (this.#m.info.length >= 10000) throw new InvoiceBuilderException('At most 10000 DodatkowyOpis entries.');
    this.#m.info.push([Fa3Text.token(key, 256, 'additionalInfo.key'), Fa3Text.token(value, 256, 'additionalInfo.value')]);
    return this;
  }

  /** Stopka/Informacje/StopkaFaktury, 1..3500 chars. */
  footer(text: string): this {
    this.#m.footer = Fa3Text.text(text, 3500, 'footer');
    return this;
  }

  /** Exact totals (P_13_n, P_14_n, P_15) for the lines added so far. */
  totals(): Fa3Totals {
    if (this.#m.lines.length === 0) throw new InvoiceBuilderException('At least one line is required.');
    return Fa3Totals.calculate(this.#m.lines.map((l) => ({ net: l.net, rate: l.rate })));
  }

  /** Renders the XML WITHOUT the XSD check (sync). Model completeness is still enforced. */
  buildUnvalidated(): string {
    this.#assertComplete();
    const totals = this.totals();
    if (totals.gross.decimal().integerDigits() > 16) throw new InvoiceBuilderException('Gross amount exceeds 16 integer digits.');
    return writeFa3Xml(this.#m, totals);
  }

  /**
   * @throws InvoiceBuilderException before any XML is produced;
   *         InvoiceValidationException if the XSD self-check fails
   */
  async build(): Promise<string> {
    const xml = this.buildUnvalidated();
    if (this.#selfValidate) await new XmlValidator().assertValid(xml);
    return xml;
  }

  /** @param model language-neutral model, see fixtures/fa3/README.md */
  static fromModel(model: unknown): Fa3InvoiceBuilder {
    return Fa3ModelLoader.load(model);
  }

  /** Parses the language-neutral JSON text (see fixtures/fa3/README.md). JSON floats are rejected by the loader. */
  static fromJson(json: string): Fa3InvoiceBuilder {
    let model: unknown;
    try {
      model = JSON.parse(json);
    } catch (e) {
      throw new InvoiceBuilderException(`Invalid JSON: ${e instanceof Error ? e.message : String(e)}`, { cause: e });
    }
    return Fa3ModelLoader.load(model);
  }

  /**
   * A complete, schema-valid synthetic invoice for live TEST runs: buyer without tax id,
   * one 23% and one 8% line, issue date = today (UTC) so that P_1 is never later than receipt.
   */
  static sample(sellerNip: string, invoiceNumber: string, now?: Date): Fa3InvoiceBuilder {
    const at = now ?? new Date();
    const due = new Date(at.getTime() + 14 * 86_400_000);
    return Fa3InvoiceBuilder.create()
      .seller(sellerNip, 'KSeF Foundry Test Seller', new Fa3Address('ul. Testowa 1', '00-001 Warszawa'))
      .buyerNoId('Test Buyer', new Fa3Address('ul. Probna 2', '00-002 Warszawa'))
      .number(invoiceNumber)
      .issueDate(utcStamp(at).slice(0, 10))
      .createdAt(at)
      .systemInfo('KSeF Foundry')
      .addLine('Usluga testowa', 1, '100.00', '23', 'szt.')
      .addLine('Towar testowy', 2, '10.50', '8', 'szt.')
      .paymentMethod(6)
      .dueDate(utcStamp(due).slice(0, 10));
  }

  /** Same as `sample()` but returns the (self-validated) XML string. */
  static sampleXml(sellerNip: string, invoiceNumber: string, now?: Date): Promise<string> {
    return Fa3InvoiceBuilder.sample(sellerNip, invoiceNumber, now).build();
  }

  /** NIP control-sum check (KSeF PROD enforces it; XSD does not). */
  static isValidNipChecksum(nip: string): boolean {
    if (!/^\d{10}$/.test(nip)) return false;
    const w = [6, 5, 7, 2, 3, 4, 5, 6, 7];
    let s = 0;
    for (let i = 0; i < 9; i++) s += (w[i] ?? 0) * Number(nip[i]);
    return s % 11 === Number(nip[9]);
  }

  /** Normalized validated model (internal use by the writer/tests). */
  model(): Readonly<Fa3Model> {
    return this.#m;
  }

  #assertComplete(): void {
    for (const k of ['seller', 'buyer', 'number', 'issueDate', 'createdAt'] as const) {
      if (this.#m[k] === null) throw new InvoiceBuilderException(`Required field "${k}" is missing.`);
    }
    if (this.#m.lines.length === 0) throw new InvoiceBuilderException('At least one line is required.');
    const hasZw = this.#m.lines.some((l) => l.rate === VatRate.ZW);
    const hasWdt = this.#m.lines.some((l) => l.rate === VatRate.ZERO_WDT);
    if (hasZw && this.#m.exemption === null) {
      throw new InvoiceBuilderException('Lines with rate "zw" require exemptionBasis() (P_19A/P_19B/P_19C).');
    }
    if (!hasZw && this.#m.exemption !== null) {
      throw new InvoiceBuilderException('exemptionBasis() given but no line uses rate "zw".');
    }
    if (hasWdt && this.#m.buyer?.kind !== 'eu') {
      throw new InvoiceBuilderException('Rate "0 WDT" requires an EU VAT buyer (buyerEu()).');
    }
    const p = this.#m.payment;
    if (p !== null && p.paidDate === null && p.method === null && p.dueDates.length === 0 && p.accounts.length === 0) {
      this.#m.payment = null;
    }
  }

  #setBuyer(name: string, address: Fa3Address, make: (name: string, a: Addr) => Buyer): this {
    this.#m.buyer = make(Fa3Text.token(name, 512, 'buyer.name'), addr(address, 'buyer.address'));
    return this;
  }

  #pay(): Payment {
    this.#m.payment ??= { paidDate: null, dueDates: [], method: null, accounts: [] };
    return this.#m.payment;
  }
}

function utcStamp(d: Date): string {
  return `${d.toISOString().slice(0, 19)}Z`;
}

function nipOf(nip: string, field: string): string {
  if (typeof nip !== 'string' || !/^[1-9]((\d[1-9])|([1-9]\d))\d{7}$/.test(nip)) {
    throw new InvoiceBuilderException(`${field} must be 10 digits matching the XSD TNrNIP pattern (no dashes, no PL prefix).`);
  }
  return nip;
}

function addr(a: Fa3Address, field: string): Addr {
  if (!/^[A-Z]{2}$/.test(a.country)) {
    throw new InvoiceBuilderException(`${field}.country must be a 2-letter upper-case ISO code.`);
  }
  return {
    country: a.country,
    line1: Fa3Text.token(a.line1, 512, `${field}.line1`),
    line2: a.line2 === null ? null : Fa3Text.token(a.line2, 512, `${field}.line2`),
  };
}
