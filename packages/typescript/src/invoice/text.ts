import { debugType } from './decimal.js';
import { InvoiceBuilderException } from './errors.js';

/**
 * Any character KSeF/XML 1.0 forbid: C0 controls except TAB/LF/CR, U+007F-0084, U+0086-009F,
 * U+FDD0-FDEF and the last two code points of every plane (U+FFFE/FFFF, U+1FFFE .. U+10FFFF).
 * (U+0085 is deliberately allowed, exactly as in the PHP implementation.)
 */
export const FORBIDDEN_CHARS_RE: RegExp = (() => {
  let planes = '';
  for (let i = 1; i <= 16; i++) {
    planes += `\\u{${((i << 16) | 0xfffe).toString(16)}}\\u{${((i << 16) | 0xffff).toString(16)}}`;
  }
  return new RegExp(
    `[\\u{0}-\\u{8}\\u{B}\\u{C}\\u{E}-\\u{1F}\\u{7F}-\\u{84}\\u{86}-\\u{9F}\\u{FDD0}-\\u{FDEF}\\u{FFFE}\\u{FFFF}${planes}]`,
    'u',
  );
})();

const LONE_SURROGATE_RE = /[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/;

/** Upper-case hex code point, at least 4 digits (PHP `U+%04X`). */
export function hexCodePoint(ch: string): string {
  return (ch.codePointAt(0) ?? 0).toString(16).toUpperCase().padStart(4, '0');
}

/** Number of Unicode code points. */
function charLength(s: string): number {
  let n = 0;
  for (let i = 0; i < s.length; i++) {
    const c = s.charCodeAt(i);
    if (c >= 0xd800 && c <= 0xdbff && i + 1 < s.length) i++;
    n++;
  }
  return n;
}

/** True for dates PHP's `checkdate()` accepts (year 1..32767). */
function checkdate(month: number, day: number, year: number): boolean {
  if (year < 1 || year > 32767 || month < 1 || month > 12 || day < 1) return false;
  const leap = (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0;
  const days = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][month - 1] ?? 0;
  return day <= days;
}

/**
 * Input sanitising for FA(3) fields. Strings are either accepted (and normalised exactly as
 * the XSD whitespace facet would) or rejected; they are never silently altered otherwise.
 *
 * @internal
 */
export const Fa3Text = {
  /** XSD xsd:token semantics: collapse whitespace, trim; length counted in characters. */
  token(v: unknown, max: number, field: string, min = 1): string {
    const s = raw(v, field)
      .replace(/[ \t\r\n]+/g, ' ')
      .replace(/^ +| +$/g, '');
    length(s, min, max, field);
    return s;
  },

  /** XSD xsd:string semantics (newlines kept). */
  text(v: unknown, max: number, field: string, min = 1): string {
    const s = raw(v, field);
    length(s, min, max, field);
    return s;
  },

  date(v: unknown, field: string, min: string, max: string): string {
    const m = typeof v === 'string' ? /^(\d{4})-(\d{2})-(\d{2})$/.exec(v) : null;
    if (typeof v !== 'string' || m === null || !checkdate(Number(m[2]), Number(m[3]), Number(m[1]))) {
      throw new InvoiceBuilderException(`${field} must be a real date in YYYY-MM-DD format.`);
    }
    if (v < min || v > max) {
      throw new InvoiceBuilderException(`${field} ${v} is outside the XSD range ${min}..${max}.`);
    }
    return v;
  },

  checkdate,
} as const;

function raw(v: unknown, field: string): string {
  if (typeof v !== 'string') {
    throw new InvoiceBuilderException(`${field} must be a string, got ${debugType(v)}.`);
  }
  if (LONE_SURROGATE_RE.test(v)) {
    throw new InvoiceBuilderException(`${field} is not valid UTF-8.`);
  }
  const m = FORBIDDEN_CHARS_RE.exec(v);
  if (m !== null) {
    throw new InvoiceBuilderException(`${field} contains forbidden character U+${hexCodePoint(m[0])} (not allowed in XML 1.0 / KSeF).`);
  }
  return v;
}

function length(s: string, min: number, max: number, field: string): void {
  const n = charLength(s);
  if (n < min) throw new InvoiceBuilderException(`${field} must not be empty.`);
  if (n > max) throw new InvoiceBuilderException(`${field} is ${n} characters; XSD maximum is ${max}.`);
}
