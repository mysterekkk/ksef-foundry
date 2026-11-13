import type { Decimal, Money } from './decimal.js';
import type { Fa3Totals } from './totals.js';
import type { VatRate } from './vat-rate.js';

export const FA3_NS = 'http://crd.gov.pl/wzor/2025/06/25/13775/';

export interface Addr {
  readonly country: string;
  readonly line1: string;
  readonly line2: string | null;
}

export type Buyer =
  | { kind: 'nip'; nip: string; name: string; address: Addr }
  | { kind: 'eu'; euPrefix: string; euNumber: string; name: string; address: Addr }
  | { kind: 'foreign'; country: string | null; taxId: string; name: string; address: Addr }
  | { kind: 'none'; name: string; address: Addr };

export interface Fa3Line {
  readonly name: string;
  readonly unit: string | null;
  readonly qty: Decimal;
  readonly price: Decimal;
  readonly net: Money;
  readonly rate: VatRate;
}

export interface BankAccount {
  readonly number: string;
  readonly swift: string | null;
  readonly bankName: string | null;
  readonly description: string | null;
}

export interface Payment {
  paidDate: string | null;
  dueDates: string[];
  method: number | null;
  accounts: BankAccount[];
}

export type ExemptionType = 'statute' | 'directive' | 'other';

/** The normalized, validated model produced by the builder. */
export interface Fa3Model {
  seller: { nip: string; name: string; address: Addr } | null;
  buyer: Buyer | null;
  number: string | null;
  issueDate: string | null;
  issuePlace: string | null;
  saleDate: string | null;
  createdAt: string | null;
  systemInfo: string | null;
  lines: Fa3Line[];
  exemption: { type: ExemptionType; text: string } | null;
  payment: Payment | null;
  info: [string, string][];
  footer: string | null;
}

interface XNode {
  readonly name: string;
  readonly text: string | null;
  readonly attrs: readonly (readonly [string, string])[];
  readonly children: XNode[];
}

/** libxml2 xmlEscapeContent: & < > and CR. Everything else (incl. quotes, non-ASCII) is written raw in UTF-8. */
function escText(s: string): string {
  return s.replace(/[&<>\r]/g, (c) => (c === '&' ? '&amp;' : c === '<' ? '&lt;' : c === '>' ? '&gt;' : '&#13;'));
}

/** libxml2 attribute escaping: & < > " TAB LF CR. */
function escAttr(s: string): string {
  return s.replace(/[&<>"\t\n\r]/g, (c) =>
    c === '&' ? '&amp;' : c === '<' ? '&lt;' : c === '>' ? '&gt;' : c === '"' ? '&quot;' : c === '\t' ? '&#9;' : c === '\n' ? '&#10;' : '&#13;',
  );
}

function render(n: XNode, depth: number, out: string[]): void {
  const pad = '  '.repeat(depth);
  const attrs = n.attrs.map(([k, v]) => ` ${k}="${escAttr(v)}"`).join('');
  if (n.children.length > 0) {
    out.push(`${pad}<${n.name}${attrs}>\n`);
    for (const c of n.children) render(c, depth + 1, out);
    out.push(`${pad}</${n.name}>\n`);
  } else if (n.text !== null) {
    out.push(`${pad}<${n.name}${attrs}>${escText(n.text)}</${n.name}>\n`);
  } else {
    out.push(`${pad}<${n.name}${attrs}/>\n`);
  }
}

/**
 * Serialises an already validated builder model into FA(3) XML (element order = XSD sequence order).
 * Output is byte-identical to libxml2's `DOMDocument::saveXML()` with `formatOutput = true`
 * (what the PHP writer produces): XML declaration, LF, 2-space indent, trailing LF.
 *
 * @internal
 */
export function writeFa3Xml(m: Fa3Model, t: Fa3Totals): string {
  const el = (parent: XNode, name: string, text: string | null = null, attrs: readonly (readonly [string, string])[] = []): XNode => {
    const e: XNode = { name, text, attrs, children: [] };
    parent.children.push(e);
    return e;
  };
  const address = (parent: XNode, a: Addr): void => {
    const e = el(parent, 'Adres');
    el(e, 'KodKraju', a.country);
    el(e, 'AdresL1', a.line1);
    if (a.line2 !== null) el(e, 'AdresL2', a.line2);
  };
  const need = <T>(v: T | null, what: string): T => {
    if (v === null) throw new Error(`internal: ${what} missing in validated model`);
    return v;
  };

  const root: XNode = { name: 'Faktura', text: null, attrs: [['xmlns', FA3_NS]], children: [] };

  const h = el(root, 'Naglowek');
  el(h, 'KodFormularza', 'FA', [['kodSystemowy', 'FA (3)'], ['wersjaSchemy', '1-0E']]);
  el(h, 'WariantFormularza', '3');
  el(h, 'DataWytworzeniaFa', need(m.createdAt, 'createdAt'));
  if (m.systemInfo !== null) el(h, 'SystemInfo', m.systemInfo);

  const seller = need(m.seller, 'seller');
  const p1 = el(root, 'Podmiot1');
  const d1 = el(p1, 'DaneIdentyfikacyjne');
  el(d1, 'NIP', seller.nip);
  el(d1, 'Nazwa', seller.name);
  address(p1, seller.address);

  const b = need(m.buyer, 'buyer');
  const p2 = el(root, 'Podmiot2');
  const d2 = el(p2, 'DaneIdentyfikacyjne');
  switch (b.kind) {
    case 'nip':
      el(d2, 'NIP', b.nip);
      break;
    case 'eu':
      el(d2, 'KodUE', b.euPrefix);
      el(d2, 'NrVatUE', b.euNumber);
      break;
    case 'foreign':
      if (b.country !== null) el(d2, 'KodKraju', b.country);
      el(d2, 'NrID', b.taxId);
      break;
    case 'none':
      el(d2, 'BrakID', '1');
      break;
  }
  el(d2, 'Nazwa', b.name);
  address(p2, b.address);
  el(p2, 'JST', '2');
  el(p2, 'GV', '2');

  const fa = el(root, 'Fa');
  el(fa, 'KodWaluty', 'PLN');
  el(fa, 'P_1', need(m.issueDate, 'issueDate'));
  if (m.issuePlace !== null) el(fa, 'P_1M', m.issuePlace);
  el(fa, 'P_2', need(m.number, 'number'));
  if (m.saleDate !== null) el(fa, 'P_6', m.saleDate);
  for (const [suffix, bk] of t.buckets) {
    el(fa, `P_13_${suffix}`, bk.net.toString());
    if (bk.vat !== null) el(fa, `P_14_${suffix}`, bk.vat.toString());
  }
  el(fa, 'P_15', t.gross.toString());

  const adn = el(fa, 'Adnotacje');
  el(adn, 'P_16', '2');
  el(adn, 'P_17', '2');
  el(adn, 'P_18', '2');
  el(adn, 'P_18A', '2');
  const zw = el(adn, 'Zwolnienie');
  if (m.exemption !== null) {
    el(zw, 'P_19', '1');
    const tag = { statute: 'P_19A', directive: 'P_19B', other: 'P_19C' }[m.exemption.type];
    el(zw, tag, m.exemption.text);
  } else {
    el(zw, 'P_19N', '1');
  }
  el(el(adn, 'NoweSrodkiTransportu'), 'P_22N', '1');
  el(adn, 'P_23', '2');
  el(el(adn, 'PMarzy'), 'P_PMarzyN', '1');

  el(fa, 'RodzajFaktury', 'VAT');

  for (const [k, v] of m.info) {
    const o = el(fa, 'DodatkowyOpis');
    el(o, 'Klucz', k);
    el(o, 'Wartosc', v);
  }

  m.lines.forEach((l, i) => {
    const r = el(fa, 'FaWiersz');
    el(r, 'NrWierszaFa', String(i + 1));
    el(r, 'P_7', l.name);
    if (l.unit !== null) el(r, 'P_8A', l.unit);
    el(r, 'P_8B', l.qty.toString());
    el(r, 'P_9A', l.price.toFixed(Math.max(2, l.price.significantScale())));
    el(r, 'P_11', l.net.toString());
    el(r, 'P_12', l.rate.value);
  });

  const pay = m.payment;
  if (pay !== null) {
    const p = el(fa, 'Platnosc');
    if (pay.paidDate !== null) {
      el(p, 'Zaplacono', '1');
      el(p, 'DataZaplaty', pay.paidDate);
    }
    for (const dd of pay.dueDates) el(el(p, 'TerminPlatnosci'), 'Termin', dd);
    if (pay.method !== null) el(p, 'FormaPlatnosci', String(pay.method));
    for (const a of pay.accounts) {
      const rb = el(p, 'RachunekBankowy');
      el(rb, 'NrRB', a.number);
      if (a.swift !== null) el(rb, 'SWIFT', a.swift);
      if (a.bankName !== null) el(rb, 'NazwaBanku', a.bankName);
      if (a.description !== null) el(rb, 'OpisRachunku', a.description);
    }
  }

  if (m.footer !== null) {
    el(el(el(root, 'Stopka'), 'Informacje'), 'StopkaFaktury', m.footer);
  }

  const out: string[] = ['<?xml version="1.0" encoding="UTF-8"?>\n'];
  render(root, 0, out);
  return out.join('');
}
