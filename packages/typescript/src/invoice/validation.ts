export type ValidationLevel = 'warning' | 'error' | 'fatal';

/**
 * Stable machine-readable rule ids: pre-parse checks ("size", "bom", "encoding", "utf8", "forbidden-char",
 * "doctype", "processing-instruction"), "xml" (not well-formed), "xsd" (schema violation).
 * `engine` is TS-only: the WASM validator itself failed (e.g. out of its bounded memory); never a "valid" verdict.
 */
export type ValidationCode =
  | 'size'
  | 'bom'
  | 'encoding'
  | 'utf8'
  | 'forbidden-char'
  | 'doctype'
  | 'processing-instruction'
  | 'xml'
  | 'xsd'
  | 'engine';

/** One validation finding. `line`/`column` are 0 when not applicable. */
export class ValidationIssue {
  static readonly LEVEL_WARNING = 'warning' as const;
  static readonly LEVEL_ERROR = 'error' as const;
  static readonly LEVEL_FATAL = 'fatal' as const;

  constructor(
    readonly line: number,
    readonly column: number,
    readonly level: ValidationLevel,
    readonly message: string,
    readonly code: ValidationCode = 'xsd',
  ) {}

  toJSON(): { line: number; column: number; level: ValidationLevel; message: string; code: ValidationCode } {
    return { line: this.line, column: this.column, level: this.level, message: this.message, code: this.code };
  }
}

export class ValidationResult {
  readonly issues: readonly ValidationIssue[];

  constructor(issues: readonly ValidationIssue[] = []) {
    this.issues = issues;
  }

  isValid(): boolean {
    return this.issues.every((i) => i.level === 'warning');
  }

  errors(): ValidationIssue[] {
    return this.issues.filter((i) => i.level !== 'warning');
  }

  /** Distinct rule codes of the errors. */
  codes(): ValidationCode[] {
    return [...new Set(this.errors().map((i) => i.code))];
  }

  summary(max = 5): string {
    const errs = this.errors();
    const parts = errs.slice(0, max).map((i) => `[${i.code}] line ${i.line}: ${i.message}`);
    const more = errs.length - parts.length;
    return parts.join('; ') + (more > 0 ? ` (+${more} more)` : '');
  }
}
