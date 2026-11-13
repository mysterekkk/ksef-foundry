import { Fa3Address } from './address.js';
import { Fa3InvoiceBuilder } from './builder.js';
import { Decimal } from './decimal.js';
import type { DecimalInput } from './decimal.js';
import { InvoiceBuilderException } from './errors.js';
import type { ExemptionType } from './xml-writer.js';

type Obj = Readonly<Record<string, unknown>>;

const isObj = (v: unknown): v is Obj => typeof v === 'object' && v !== null && !Array.isArray(v);
const isSet = (v: unknown): boolean => v !== undefined && v !== null;

function keys(a: Obj, allowed: readonly string[], path: string): void {
  const bad = Object.keys(a).filter((k) => !allowed.includes(k));
  if (bad.length > 0) throw new InvoiceBuilderException(`${path}: unknown key(s): ${bad.join(', ')}`);
}

function arr(a: Obj, k: string, path: string): Obj {
  const v = a[k];
  if (!isObj(v)) throw new InvoiceBuilderException(`${path}.${k} is required (object).`);
  return v;
}

function str(a: Obj, k: string, path: string): string {
  const v = a[k];
  if (typeof v !== 'string') throw new InvoiceBuilderException(`${path}.${k} is required (string).`);
  return v;
}

function optStr(a: Obj, k: string, path: string): string | null {
  const v = a[k];
  if (!isSet(v)) return null;
  if (typeof v !== 'string') throw new InvoiceBuilderException(`${path}.${k} must be a string.`);
  return v;
}

function list(a: Obj, k: string, path: string): readonly unknown[] {
  const v = a[k];
  if (!isSet(v)) return [];
  if (!Array.isArray(v)) throw new InvoiceBuilderException(`${path}.${k} must be a list.`);
  return v as readonly unknown[];
}

function dec(v: unknown, path: string): Decimal {
  if (typeof v === 'number' && !Number.isInteger(v)) {
    throw new InvoiceBuilderException(`${path} must be a JSON string (floats are rejected), e.g. "33.33".`);
  }
  return Decimal.of(v as DecimalInput);
}

/**
 * Loads the language-neutral invoice model (fixtures/fa3/README.md) into `Fa3InvoiceBuilder`.
 * Unknown keys are rejected (never silently ignored). Decimals must be JSON strings or safe integers.
 *
 * @internal
 */
export const Fa3ModelLoader = {
  load(model: unknown): Fa3InvoiceBuilder {
    if (!isObj(model)) throw new InvoiceBuilderException('model must be an object.');
    const m = model;
    keys(m, ['seller', 'buyer', 'number', 'issueDate', 'issuePlace', 'saleDate', 'currency', 'createdAt', 'systemInfo', 'lines', 'exemptionBasis', 'payment', 'additionalInfo', 'footer'], 'model');
    const b = Fa3InvoiceBuilder.create();

    const s = arr(m, 'seller', 'seller');
    keys(s, ['nip', 'name', 'address'], 'seller');
    b.seller(str(s, 'nip', 'seller'), str(s, 'name', 'seller'), Fa3Address.fromModel(arr(s, 'address', 'seller'), 'seller.address'));

    const by = arr(m, 'buyer', 'buyer');
    keys(by, ['idType', 'nip', 'euPrefix', 'euVatNumber', 'countryCode', 'taxId', 'name', 'address'], 'buyer');
    const addr = Fa3Address.fromModel(arr(by, 'address', 'buyer'), 'buyer.address');
    const name = str(by, 'name', 'buyer');
    const type = str(by, 'idType', 'buyer');
    const allowed: Readonly<Record<string, readonly string[]>> = { nip: ['nip'], eu: ['euPrefix', 'euVatNumber'], foreign: ['countryCode', 'taxId'], none: [] };
    const allowedForType = Object.hasOwn(allowed, type) ? allowed[type] : undefined;
    if (allowedForType === undefined) throw new InvoiceBuilderException('buyer.idType must be nip|eu|foreign|none.');
    for (const k of ['nip', 'euPrefix', 'euVatNumber', 'countryCode', 'taxId']) {
      if (isSet(by[k]) && !allowedForType.includes(k)) {
        throw new InvoiceBuilderException(`buyer.${k} is not allowed with idType "${type}".`);
      }
    }
    switch (type) {
      case 'nip':
        b.buyerNip(str(by, 'nip', 'buyer'), name, addr);
        break;
      case 'eu':
        b.buyerEu(str(by, 'euPrefix', 'buyer'), str(by, 'euVatNumber', 'buyer'), name, addr);
        break;
      case 'foreign':
        b.buyerForeign(optStr(by, 'countryCode', 'buyer'), str(by, 'taxId', 'buyer'), name, addr);
        break;
      default:
        b.buyerNoId(name, addr);
    }

    b.number(str(m, 'number', 'model')).issueDate(str(m, 'issueDate', 'model')).createdAt(str(m, 'createdAt', 'model'));
    let v: string | null;
    if ((v = optStr(m, 'issuePlace', 'model')) !== null) b.issuePlace(v);
    if ((v = optStr(m, 'saleDate', 'model')) !== null) b.saleDate(v);
    if ((v = optStr(m, 'currency', 'model')) !== null) b.currency(v);
    if ((v = optStr(m, 'systemInfo', 'model')) !== null) b.systemInfo(v);
    if ((v = optStr(m, 'footer', 'model')) !== null) b.footer(v);

    const lines = m['lines'];
    if (!Array.isArray(lines) || lines.length === 0) throw new InvoiceBuilderException('model.lines must be a non-empty list.');
    (lines as readonly unknown[]).forEach((l, i) => {
      const p = `lines[${i + 1}]`;
      if (!isObj(l)) throw new InvoiceBuilderException(`${p} must be an object.`);
      keys(l, ['name', 'unit', 'quantity', 'unitNetPrice', 'netValue', 'vatRate'], p);
      for (const k of ['quantity', 'unitNetPrice']) {
        if (!isSet(l[k])) throw new InvoiceBuilderException(`${p}.${k} is required.`);
      }
      b.addLine(
        str(l, 'name', p),
        dec(l['quantity'], `${p}.quantity`),
        dec(l['unitNetPrice'], `${p}.unitNetPrice`),
        str(l, 'vatRate', p),
        optStr(l, 'unit', p),
        isSet(l['netValue']) ? dec(l['netValue'], `${p}.netValue`) : null,
      );
    });

    if (isSet(m['exemptionBasis'])) {
      const e = arr(m, 'exemptionBasis', 'model');
      keys(e, ['type', 'text'], 'exemptionBasis');
      b.exemptionBasis(str(e, 'text', 'exemptionBasis'), (optStr(e, 'type', 'exemptionBasis') ?? 'statute') as ExemptionType);
    }
    if (isSet(m['payment'])) {
      const p = arr(m, 'payment', 'model');
      keys(p, ['method', 'paidDate', 'dueDates', 'bankAccounts'], 'payment');
      if (isSet(p['method'])) {
        if (typeof p['method'] !== 'number' || !Number.isInteger(p['method'])) {
          throw new InvoiceBuilderException('payment.method must be an integer 1..7.');
        }
        b.paymentMethod(p['method']);
      }
      if ((v = optStr(p, 'paidDate', 'payment')) !== null) b.paid(v);
      for (const d of list(p, 'dueDates', 'payment')) {
        if (typeof d !== 'string') throw new InvoiceBuilderException('payment.dueDates must be strings.');
        b.dueDate(d);
      }
      for (const a of list(p, 'bankAccounts', 'payment')) {
        if (!isObj(a)) throw new InvoiceBuilderException('payment.bankAccounts[] must be objects.');
        keys(a, ['number', 'swift', 'bankName', 'description'], 'bankAccount');
        b.bankAccount(str(a, 'number', 'bankAccount'), optStr(a, 'swift', 'bankAccount'), optStr(a, 'bankName', 'bankAccount'), optStr(a, 'description', 'bankAccount'));
      }
    }
    for (const kv of list(m, 'additionalInfo', 'model')) {
      if (!isObj(kv)) throw new InvoiceBuilderException('additionalInfo[] must be objects.');
      keys(kv, ['key', 'value'], 'additionalInfo');
      b.additionalInfo(str(kv, 'key', 'additionalInfo'), str(kv, 'value', 'additionalInfo'));
    }
    return b;
  },
} as const;
