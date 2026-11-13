import { ConfigurationException } from './errors.js';

/** OpenAPI `ReferenceNumber`: string of exactly 36 characters; we additionally restrict it to `[A-Za-z0-9-]`. */
const REFERENCE_NUMBER = /^[A-Za-z0-9-]{36}$/;

/** OpenAPI `KsefNumber` pattern (verbatim). */
const KSEF_NUMBER =
  /^([1-9](\d[1-9]|[1-9]\d)\d{7})-(20[2-9][0-9]|2[1-9]\d{2}|[3-9]\d{3})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])-([0-9A-F]{6})-?([0-9A-F]{6})-([0-9A-F]{2})$/;

/** True when `value` looks like a KSeF reference number. */
export function isReferenceNumber(value: unknown): value is string {
  return typeof value === 'string' && REFERENCE_NUMBER.test(value);
}

/** Validates a reference number used as a path segment; returns it unchanged (it needs no escaping). */
export function refSeg(value: string): string {
  if (!REFERENCE_NUMBER.test(value)) {
    throw new ConfigurationException('Invalid reference number (expected 36 characters of A-Z a-z 0-9 -)');
  }
  return value;
}

/** Validates a KSeF number used as a path segment; returns it unchanged. */
export function ksefSeg(value: string): string {
  if (!KSEF_NUMBER.test(value) || value.length < 35 || value.length > 36) {
    throw new ConfigurationException('Invalid KSeF number');
  }
  return value;
}
