import { Decimal } from './decimal.js';
import { InvoiceBuilderException } from './errors.js';

/**
 * FA(3) P_12 (XSD TStawkaPodatku) tokens and their summary bucket (P_13_n / P_14_n).
 *
 * 23/22 -> P_13_1/P_14_1 ; 8/7 -> P_13_2/P_14_2 ; 5 -> P_13_3/P_14_3 ;
 * 4/3 (taxi flat rate) -> P_13_4/P_14_4 ; "0 KR" -> P_13_6_1 ; "0 WDT" -> P_13_6_2 ;
 * "0 EX" -> P_13_6_3 ; zw -> P_13_7 ; "np I" -> P_13_8 ; "np II" -> P_13_9 ; oo -> P_13_10.
 *
 * Instances are singletons: compare with `===`.
 */
export class VatRate {
  static readonly R23 = new VatRate('23', '1', '23');
  static readonly R22 = new VatRate('22', '1', '22');
  static readonly R8 = new VatRate('8', '2', '8');
  static readonly R7 = new VatRate('7', '2', '7');
  static readonly R5 = new VatRate('5', '3', '5');
  static readonly R4 = new VatRate('4', '4', '4', false);
  static readonly R3 = new VatRate('3', '4', '3', false);
  static readonly ZERO_KR = new VatRate('0 KR', '6_1', null);
  static readonly ZERO_WDT = new VatRate('0 WDT', '6_2', null);
  static readonly ZERO_EX = new VatRate('0 EX', '6_3', null);
  static readonly ZW = new VatRate('zw', '7', null);
  static readonly OO = new VatRate('oo', '10', null, false);
  static readonly NP_I = new VatRate('np I', '8', null);
  static readonly NP_II = new VatRate('np II', '9', null);

  private constructor(
    /** The P_12 token. */
    readonly value: string,
    private readonly bucketSuffix: string,
    private readonly pct: string | null,
    private readonly supported: boolean = true,
  ) {}

  static cases(): readonly VatRate[] {
    return [
      VatRate.R23, VatRate.R22, VatRate.R8, VatRate.R7, VatRate.R5, VatRate.R4, VatRate.R3,
      VatRate.ZERO_KR, VatRate.ZERO_WDT, VatRate.ZERO_EX, VatRate.ZW, VatRate.OO, VatRate.NP_I, VatRate.NP_II,
    ];
  }

  static tryFrom(token: string): VatRate | null {
    return VatRate.cases().find((c) => c.value === token) ?? null;
  }

  /** Bucket suffix used in P_13_<suffix> (and P_14_<suffix> when `hasVatAmount()`). */
  bucket(): string {
    return this.bucketSuffix;
  }

  percent(): Decimal | null {
    return this.pct === null ? null : Decimal.of(this.pct);
  }

  /** True for rates that have a P_14_n VAT amount. */
  hasVatAmount(): boolean {
    return this.pct !== null;
  }

  /** Whether the builder offers this rate (others are rejected, see docs/dev/fa3-scope.md). */
  isSupported(): boolean {
    return this.supported;
  }

  static parse(token: string): VatRate {
    const r = typeof token === 'string' ? VatRate.tryFrom(token) : null;
    if (r === null) {
      throw new InvoiceBuilderException(
        `Unknown VAT rate token "${token}". Allowed P_12 tokens: ${VatRate.cases().map((c) => c.value).join(', ')}.`,
      );
    }
    if (!r.isSupported()) {
      throw new InvoiceBuilderException(
        `VAT rate "${token}" is a valid P_12 token but is not supported by this builder (see docs/dev/fa3-scope.md).`,
      );
    }
    return r;
  }
}
