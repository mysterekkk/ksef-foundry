import { InvoiceBuilderException } from './errors.js';

/** FA(3) TAdres: KodKraju + AdresL1 (+ AdresL2). Free-form lines, max 512 chars each. */
export class Fa3Address {
  readonly line1: string;
  readonly line2: string | null;
  readonly country: string;

  constructor(line1: string, line2: string | null = null, country = 'PL') {
    this.line1 = line1;
    this.line2 = line2;
    this.country = country;
  }

  /** @param a keys: line1, line2?, country? (unknown keys are rejected) */
  static fromModel(a: Readonly<Record<string, unknown>>, path = 'address'): Fa3Address {
    const unknown = Object.keys(a).filter((k) => !['line1', 'line2', 'country'].includes(k));
    if (unknown.length > 0) {
      throw new InvoiceBuilderException(`${path}: unknown key(s): ${unknown.join(', ')}`);
    }
    const line1 = a['line1'];
    if (typeof line1 !== 'string') {
      throw new InvoiceBuilderException(`${path}.line1 is required (string).`);
    }
    for (const k of ['line2', 'country']) {
      const v = a[k];
      if (v !== undefined && v !== null && typeof v !== 'string') {
        throw new InvoiceBuilderException(`${path}.${k} must be a string.`);
      }
    }
    const l2 = a['line2'];
    const c = a['country'];
    return new Fa3Address(line1, typeof l2 === 'string' ? l2 : null, typeof c === 'string' ? c : 'PL');
  }
}
