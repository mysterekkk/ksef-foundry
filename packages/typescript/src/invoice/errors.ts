import { KsefException, XmlValidationException } from '../errors.js';
import type { XmlValidationIssue } from '../errors.js';
import type { ValidationIssue } from './validation.js';

/**
 * Thrown by the FA(3) builder BEFORE any XML is produced when the model is incomplete,
 * outside the supported variant, or violates an XSD constraint the builder checks up front.
 * (PHP: `InvoiceBuilderException`.)
 */
export class InvoiceBuilderException extends KsefException {
  constructor(message: string, options?: { cause?: unknown }) {
    super(message, { ...options, category: 'invoice_builder' });
  }
}

/**
 * Raised by `XmlValidator.assertValid()` / `Fa3InvoiceBuilder.build()` when the XML is not valid FA(3).
 * It is an `XmlValidationException` (SDK-wide handlers keep working) and additionally exposes the
 * full-fidelity issues (including the `fatal` level and the stable rule `code`).
 * (PHP: `InvoiceValidationException`, `issues()`.)
 */
export class InvoiceValidationException extends XmlValidationException {
  readonly issues: readonly ValidationIssue[];

  constructor(message: string, issues: readonly ValidationIssue[] = []) {
    super(message, issues.map(toBaseIssue));
    this.issues = issues;
  }
}

function toBaseIssue(i: ValidationIssue): XmlValidationIssue {
  return { line: i.line, column: i.column, message: i.message, level: i.level, code: i.code };
}
