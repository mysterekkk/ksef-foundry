import { InvoiceBuilderException } from './errors.js';

/** Values accepted wherever an exact decimal is expected. Non-integer `number`s are always rejected. */
export type DecimalInput = string | bigint | number | Decimal;

const DECIMAL_RE = /^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/;

function pow10(n: number): bigint {
  return 10n ** BigInt(n);
}

/** PHP `get_debug_type()` look-alike for error messages. */
export function debugType(v: unknown): string {
  if (v === null || v === undefined) return 'null';
  if (Array.isArray(v)) return 'array';
  switch (typeof v) {
    case 'string':
      return 'string';
    case 'number':
      return Number.isInteger(v) ? 'int' : 'float';
    case 'bigint':
      return 'int';
    case 'boolean':
      return 'bool';
    default:
      return typeof v;
  }
}

/** Printable-ASCII-only, max 40 chars (non-ASCII bytes become '?', one per UTF-8 byte, as in PHP). */
function clip(s: string): string {
  let out = '';
  for (const b of Buffer.from(s, 'utf8')) out += b >= 0x20 && b <= 0x7e ? String.fromCharCode(b) : '?';
  return out.slice(0, 40);
}

/**
 * Immutable exact decimal on `bigint` scaled integers. Floats are never accepted.
 * Rounding is half-up away from zero (commercial rounding). Scale handling mirrors the PHP bcmath class:
 * add/sub keep max(scale), mul sums scales, `round()` never increases the scale.
 */
export class Decimal {
  readonly #unscaled: bigint;
  readonly #scale: number;

  private constructor(unscaled: bigint, scale: number) {
    this.#unscaled = unscaled;
    this.#scale = scale;
    Object.freeze(this);
  }

  static of(value: DecimalInput): Decimal {
    if (value instanceof Decimal) return value;
    if (typeof value === 'bigint') return new Decimal(value, 0);
    if (typeof value === 'number') {
      if (!Number.isInteger(value)) {
        throw new InvoiceBuilderException('Floats are not accepted for decimal values; pass a string, bigint or integer.');
      }
      if (!Number.isSafeInteger(value)) {
        throw new InvoiceBuilderException('Integer is outside the safe range (2^53-1); pass a string or bigint.');
      }
      return new Decimal(BigInt(value), 0);
    }
    if (typeof value !== 'string') {
      throw new InvoiceBuilderException(`Decimal must be string|bigint|integer|Decimal, got ${debugType(value)}.`);
    }
    if (!DECIMAL_RE.test(value)) {
      throw new InvoiceBuilderException(`Invalid decimal string "${clip(value)}" (expected -?digits[.digits], no spaces, no exponent).`);
    }
    const dot = value.indexOf('.');
    if (dot < 0) return new Decimal(BigInt(value), 0);
    const scale = value.length - dot - 1;
    const negative = value.startsWith('-');
    const digits = value.slice(negative ? 1 : 0, dot) + value.slice(dot + 1);
    const u = BigInt(digits);
    return new Decimal(negative ? -u : u, scale);
  }

  #align(o: Decimal): [bigint, bigint, number] {
    const s = Math.max(this.#scale, o.#scale);
    return [this.#unscaled * pow10(s - this.#scale), o.#unscaled * pow10(s - o.#scale), s];
  }

  add(o: DecimalInput): Decimal {
    const [a, b, s] = this.#align(Decimal.of(o));
    return new Decimal(a + b, s);
  }

  sub(o: DecimalInput): Decimal {
    const [a, b, s] = this.#align(Decimal.of(o));
    return new Decimal(a - b, s);
  }

  /** Exact product (scale = sum of scales). */
  mul(o: DecimalInput): Decimal {
    const d = Decimal.of(o);
    return new Decimal(this.#unscaled * d.#unscaled, this.#scale + d.#scale);
  }

  compare(o: DecimalInput): -1 | 0 | 1 {
    const [a, b] = this.#align(Decimal.of(o));
    return a < b ? -1 : a > b ? 1 : 0;
  }

  equals(o: DecimalInput): boolean {
    return this.compare(o) === 0;
  }

  isZero(): boolean {
    return this.#unscaled === 0n;
  }

  isNegative(): boolean {
    return this.#unscaled < 0n;
  }

  scale(): number {
    return this.#scale;
  }

  /** Number of fractional digits after dropping trailing zeros. */
  significantScale(): number {
    let u = this.#unscaled < 0n ? -this.#unscaled : this.#unscaled;
    if (u === 0n) return 0;
    let s = this.#scale;
    while (s > 0 && u % 10n === 0n) {
      u /= 10n;
      s--;
    }
    return s;
  }

  /** Digits in the integer part (0 for |x| < 1). */
  integerDigits(): number {
    const abs = this.#unscaled < 0n ? -this.#unscaled : this.#unscaled;
    const int = abs / pow10(this.#scale);
    return int === 0n ? 0 : int.toString().length;
  }

  /** Half-up (away from zero) rounding to `scale` fractional digits; never increases the scale. */
  round(scale: number): Decimal {
    if (!Number.isInteger(scale) || scale < 0) throw new RangeError('scale must be an integer >= 0');
    if (this.#scale <= scale) return this;
    const div = pow10(this.#scale - scale);
    const neg = this.#unscaled < 0n;
    const abs = neg ? -this.#unscaled : this.#unscaled;
    let q = abs / div;
    if ((abs % div) * 2n >= div) q += 1n;
    return new Decimal(neg ? -q : q, scale);
  }

  /** Rounded and zero-padded to exactly `scale` fractional digits (e.g. "12.30"). */
  toFixed(scale: number): string {
    const r = this.round(scale);
    return Decimal.#format(r.#unscaled, r.#scale, scale);
  }

  /** Canonical string without insignificant trailing zeros. */
  toString(): string {
    return Decimal.#format(this.#unscaled, this.#scale, this.significantScale());
  }

  toJSON(): string {
    return this.toString();
  }

  /** Renders with exactly `outScale` fractional digits (zero padded; trailing zero digits may be dropped). */
  static #format(unscaled: bigint, scale: number, outScale: number): string {
    const neg = unscaled < 0n;
    let digits = (neg ? -unscaled : unscaled).toString();
    if (outScale < scale) {
      digits = digits.slice(0, digits.length - (scale - outScale)) || '0';
      scale = outScale;
    }
    if (digits.length <= scale) digits = '0'.repeat(scale - digits.length + 1) + digits;
    const int = digits.slice(0, digits.length - scale);
    const frac = digits.slice(digits.length - scale) + '0'.repeat(outScale - scale);
    const body = outScale === 0 ? int : `${int}.${frac}`;
    return neg ? `-${body}` : body;
  }
}

/**
 * Amount with exactly 2 fractional digits (XSD TKwotowy: fractionDigits 2).
 * Construction rounds half-up; use `Money.exact()` to reject sub-grosz precision.
 */
export class Money {
  readonly #amount: Decimal;

  private constructor(amount: Decimal) {
    this.#amount = amount;
    Object.freeze(this);
  }

  static of(v: DecimalInput): Money {
    return new Money(Decimal.of(v).round(2));
  }

  static exact(v: DecimalInput): Money {
    const d = Decimal.of(v);
    if (d.significantScale() > 2) {
      throw new InvoiceBuilderException(`Amount "${d.toString()}" has more than 2 fractional digits.`);
    }
    return new Money(d.round(2));
  }

  decimal(): Decimal {
    return this.#amount;
  }

  add(o: Money): Money {
    return new Money(this.#amount.add(o.#amount));
  }

  sub(o: Money): Money {
    return new Money(this.#amount.sub(o.#amount));
  }

  equals(o: Money): boolean {
    return this.#amount.equals(o.#amount);
  }

  toString(): string {
    return this.#amount.toFixed(2);
  }

  toJSON(): string {
    return this.toString();
  }
}
