import { BadRequestException, Injectable, type PipeTransform } from '@nestjs/common';

/**
 * KSeF number: OpenAPI `KsefNumber` pattern (NIP, date, 12 hex, check byte; 35 or 36 characters),
 * e.g. `5265877635-20261113-0100AAAAAAAA-1B`.
 */
const KSEF_NUMBER =
  /^([1-9](\d[1-9]|[1-9]\d)\d{7})-(20[2-9][0-9]|2[1-9]\d{2}|[3-9]\d{3})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])-([0-9A-F]{6})-?([0-9A-F]{6})-([0-9A-F]{2})$/;
/** Session / invoice / UPO reference numbers: exactly 36 characters of [A-Za-z0-9-] (OpenAPI `ReferenceNumber`). */
const REFERENCE_NUMBER = /^[A-Za-z0-9-]{36}$/;

function check(value: unknown, pattern: RegExp, what: string): string {
  if (typeof value !== 'string' || value.length > 36 || !pattern.test(value)) throw new BadRequestException(`Invalid ${what}`);
  return value;
}

/** Rejects anything that is not a well-formed KSeF number, so a caller cannot steer the SDK's request paths. */
@Injectable()
export class KsefNumberPipe implements PipeTransform<unknown, string> {
  transform(value: unknown): string {
    return check(value, KSEF_NUMBER, 'KSeF number');
  }
}

/** Rejects anything that is not a plausible session/invoice/UPO reference number. */
@Injectable()
export class ReferenceNumberPipe implements PipeTransform<unknown, string> {
  transform(value: unknown): string {
    return check(value, REFERENCE_NUMBER, 'reference number');
  }
}
