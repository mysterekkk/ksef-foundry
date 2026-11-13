import { readdirSync, readFileSync, statSync } from 'node:fs';
import { basename, dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { DOMParser } from '@xmldom/xmldom';
import { validateXML } from 'xmllint-wasm';
import { ConfigurationException } from '../errors.js';
import { InvoiceValidationException } from './errors.js';
import { FORBIDDEN_CHARS_RE, hexCodePoint } from './text.js';
import { ValidationIssue, ValidationResult } from './validation.js';

export const DEFAULT_MAX_BYTES = 3_000_000;
export const SCHEMA_FILE = 'schemat_FA3_v1-0E.xsd';

/** WASM linear memory bounds (64 KiB pages): start 16 MiB, hard cap 128 MiB; OOM => `engine` issue, never "valid". */
const INITIAL_PAGES = 256;
const MAX_PAGES = 2048;
const DOC_NAME = 'doc.xml';

export interface XmlValidatorOptions {
  /** Maximum document size in bytes (default 3_000_000). */
  readonly maxBytes?: number;
  /** Alternative main XSD; every `.xsd` below its directory is preloaded so relative imports resolve offline. */
  readonly schemaPath?: string;
}

interface SchemaBundle {
  readonly schema: { fileName: string; contents: Uint8Array };
  readonly preload: { fileName: string; contents: Uint8Array }[];
}

const bundleCache = new Map<string, SchemaBundle>();

function collectXsd(root: string, dir: string, out: string[], depth = 0): void {
  if (depth > 4) return;
  for (const e of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, e.name);
    if (e.isDirectory()) collectXsd(root, p, out, depth + 1);
    else if (e.isFile() && e.name.toLowerCase().endsWith('.xsd') && statSync(p).size < 5_000_000) out.push(p);
  }
}

function loadBundle(schemaPath: string): SchemaBundle {
  const key = resolve(schemaPath);
  const hit = bundleCache.get(key);
  if (hit !== undefined) return hit;
  const root = dirname(key);
  const files: string[] = [];
  collectXsd(root, root, files);
  if (files.length > 200) throw new ConfigurationException(`Too many .xsd files below ${root} (max 200).`);
  const name = (p: string): string => relative(root, p).split(/[\\/]/).join('/');
  const bundle: SchemaBundle = {
    schema: { fileName: basename(key), contents: readFileSync(key) },
    preload: files.filter((f) => resolve(f) !== key).map((f) => ({ fileName: name(f), contents: readFileSync(f) })),
  };
  bundleCache.set(key, bundle);
  return bundle;
}

/** Byte offset -> 1-based line and byte column (identical to the PHP `position()`). */
function position(text: string, index: number): [number, number] {
  const before = text.slice(0, index);
  let line = 1;
  let lastNl = -1;
  for (let i = before.indexOf('\n'); i >= 0; i = before.indexOf('\n', i + 1)) {
    line++;
    lastNl = i;
  }
  const lastNlBytes = lastNl < 0 ? -1 : Buffer.byteLength(before.slice(0, lastNl + 1)) - 1;
  return [line, Buffer.byteLength(before) - lastNlBytes];
}

const LONE_SURROGATE_RE = /[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/;
const utf8Strict = new TextDecoder('utf-8', { fatal: true, ignoreBOM: true });

/**
 * Validates invoice XML against the official FA(3) XSD (bundled, resolved to local paths,
 * see resources/xsd/FA3) using libxml2 compiled to WebAssembly (`xmllint-wasm`).
 * The WASM module has no network or file-system access; entities are never expanded.
 *
 * KSeF technical rules enforced before the XSD (same order and codes as PHP): size limit, empty input,
 * UTF-8 without BOM, no UTF-16, prolog encoding must be UTF-8, no DOCTYPE/ENTITY, no forbidden Unicode
 * characters, then (after parsing) no processing instructions and no forbidden characters via character references.
 */
export class XmlValidator {
  static readonly DEFAULT_MAX_BYTES = DEFAULT_MAX_BYTES;
  static readonly SCHEMA_FILE = SCHEMA_FILE;

  readonly #maxBytes: number;
  readonly #bundle: SchemaBundle;

  constructor(options: XmlValidatorOptions = {}) {
    const maxBytes = options.maxBytes ?? DEFAULT_MAX_BYTES;
    if (!Number.isInteger(maxBytes) || maxBytes < 1) throw new RangeError('maxBytes must be an integer >= 1');
    this.#maxBytes = maxBytes;
    const path = options.schemaPath ?? XmlValidator.bundledSchemaPath();
    try {
      this.#bundle = loadBundle(path);
    } catch (e) {
      throw new ConfigurationException(`FA(3) XSD bundle not found or unreadable at ${path}`, { cause: e });
    }
  }

  /** Directory of the XSD bundle shipped with the package. */
  static bundledSchemaDir(): string {
    return fileURLToPath(new URL('../../resources/xsd/FA3', import.meta.url));
  }

  static bundledSchemaPath(): string {
    return join(XmlValidator.bundledSchemaDir(), SCHEMA_FILE);
  }

  /** @param xml document text, or raw bytes (preferred when the encoding must be checked) */
  async validateFa3(xml: string | Uint8Array): Promise<ValidationResult> {
    const pre = this.#preChecks(xml);
    if (pre.length > 0) return new ValidationResult(pre);
    const text = typeof xml === 'string' ? xml : utf8Strict.decode(xml);
    const bytes = typeof xml === 'string' ? Buffer.from(xml, 'utf8') : xml;

    let raw: string;
    try {
      const r = await validateXML({
        xml: [{ fileName: DOC_NAME, contents: bytes }],
        schema: this.#bundle.schema,
        preload: this.#bundle.preload,
        initialMemoryPages: INITIAL_PAGES,
        maxMemoryPages: MAX_PAGES,
        modifyArguments: (a) => ['--nonet', ...a],
      });
      raw = r.rawOutput;
    } catch (e) {
      const msg = e instanceof Error ? e.message : String(e);
      return new ValidationResult([new ValidationIssue(0, 0, 'fatal', `Validation engine failed: ${msg.split('\n')[0] ?? msg}`, 'engine')]);
    }

    const parsed = parseXmllint(raw);
    if (parsed.xml.length > 0) return new ValidationResult(parsed.xml);
    if (parsed.engine.length > 0) return new ValidationResult(parsed.engine);

    // Post-parse checks (the document is known to be well-formed here).
    const doc = new DOMParser({ errorHandler: () => undefined }).parseFromString(text, 'text/xml');
    const pis = findProcessingInstructions(doc, text);
    if (pis.length > 0) return new ValidationResult(pis);
    const decoded = (doc.documentElement as Element | null)?.textContent ?? '';
    const fm = FORBIDDEN_CHARS_RE.exec(decoded);
    if (fm !== null) {
      return new ValidationResult([
        new ValidationIssue(0, 0, 'fatal', `Forbidden character U+${hexCodePoint(fm[0])} (via character reference).`, 'forbidden-char'),
      ]);
    }
    if (parsed.xsd.length === 0 && /\bfails to validate\b/.test(raw)) {
      parsed.xsd.push(new ValidationIssue(0, 0, 'error', 'Schema validation failed.', 'xsd'));
    }
    return new ValidationResult(parsed.xsd);
  }

  /** @throws InvoiceValidationException */
  async assertValid(xml: string | Uint8Array): Promise<void> {
    const r = await this.validateFa3(xml);
    if (!r.isValid()) throw new InvoiceValidationException(`FA(3) XML is invalid: ${r.summary()}`, r.issues);
  }

  #preChecks(input: string | Uint8Array): ValidationIssue[] {
    let bytes: Uint8Array;
    if (typeof input === 'string') {
      if (LONE_SURROGATE_RE.test(input)) {
        return [new ValidationIssue(0, 0, 'fatal', 'Document is not valid UTF-8.', 'utf8')];
      }
      bytes = Buffer.from(input, 'utf8');
    } else {
      bytes = input;
    }
    const len = bytes.length;
    if (len > this.#maxBytes) {
      return [new ValidationIssue(0, 0, 'fatal', `Document is ${len} bytes; limit is ${this.#maxBytes}.`, 'size')];
    }
    if (len === 0) return [new ValidationIssue(0, 0, 'fatal', 'Document is empty.', 'xml')];

    const issues: ValidationIssue[] = [];
    const b0 = bytes[0];
    const b1 = bytes[1];
    if (b0 === 0xef && b1 === 0xbb && bytes[2] === 0xbf) {
      issues.push(new ValidationIssue(1, 1, 'fatal', 'UTF-8 BOM (EF BB BF) is not allowed.', 'bom'));
    } else if (
      (b0 === 0xfe && b1 === 0xff) || (b0 === 0xff && b1 === 0xfe) || (b0 === 0x00 && b1 === 0x3c) || (b0 === 0x3c && b1 === 0x00)
    ) {
      issues.push(new ValidationIssue(1, 1, 'fatal', 'UTF-16 content is not allowed; UTF-8 required.', 'encoding'));
    }
    let text: string;
    try {
      text = utf8Strict.decode(bytes);
    } catch {
      issues.push(new ValidationIssue(0, 0, 'fatal', 'Document is not valid UTF-8.', 'utf8'));
      return issues;
    }
    const enc = /^\uFEFF?<\?xml[ \t\r\n\v\f][^?]*?encoding[ \t\r\n\v\f]*=[ \t\r\n\v\f]*(["'])([\s\S]*?)\1/.exec(text);
    if (enc !== null && (enc[2] ?? '').toLowerCase() !== 'utf-8') {
      issues.push(new ValidationIssue(1, 1, 'fatal', `Prolog declares encoding "${enc[2] ?? ''}"; only UTF-8 is allowed.`, 'encoding'));
    }
    const dt = /<!(?:DOCTYPE|ENTITY|ELEMENT|ATTLIST|NOTATION)/i.exec(text);
    if (dt !== null) {
      const [l, c] = position(text, dt.index);
      issues.push(new ValidationIssue(l, c, 'fatal', 'DOCTYPE / entity declarations are not allowed.', 'doctype'));
    }
    const fc = FORBIDDEN_CHARS_RE.exec(text);
    if (fc !== null) {
      const [l, c] = position(text, fc.index);
      issues.push(new ValidationIssue(l, c, 'fatal', `Forbidden character U+${hexCodePoint(fc[0])}.`, 'forbidden-char'));
    }
    return issues;
  }
}

interface Parsed {
  xml: ValidationIssue[];
  xsd: ValidationIssue[];
  engine: ValidationIssue[];
}

/**
 * Splits xmllint's stderr into issues. Lines look like `doc.xml:LINE: <kind> : <message>`; parser errors are followed
 * by a source excerpt and a caret line (used to derive the column). Schema-validity errors carry no column (0), as in libxml/PHP.
 */
function parseXmllint(raw: string): Parsed {
  const out: Parsed = { xml: [], xsd: [], engine: [] };
  const lines = raw.split('\n');
  const head = /^(?:doc\.xml|[^\s:]+\.xsd):(\d+): (?:element [^:]*: )?(.*?) (error|warning) : (.*)$/;
  for (let i = 0; i < lines.length; i++) {
    const m = head.exec(lines[i] ?? '');
    if (m === null) continue;
    const line = Number(m[1]);
    const kind = m[2] ?? '';
    const isWarning = m[3] === 'warning';
    const message = (m[4] ?? '').trim();
    if (kind === 'Schemas validity') {
      out.xsd.push(new ValidationIssue(line, 0, isWarning ? 'warning' : 'error', message, 'xsd'));
    } else if (kind.startsWith('Schemas parser')) {
      out.engine.push(new ValidationIssue(line, 0, 'fatal', `Schema could not be compiled: ${message}`, 'engine'));
    } else if (!isWarning) {
      let column = 0;
      for (let j = i + 1; j < Math.min(i + 4, lines.length); j++) {
        const caret = /^(\s*)\^\s*$/.exec(lines[j] ?? '');
        if (caret !== null) {
          column = (caret[1] ?? '').length + 1;
          break;
        }
      }
      out.xml.push(new ValidationIssue(line, column, 'fatal', message, 'xml'));
    }
  }
  return out;
}

interface XNodeLike {
  readonly nodeType: number;
  readonly lineNumber?: number;
  readonly childNodes: ArrayLike<XNodeLike>;
}

/** Line numbers of processing instructions in a well-formed document (skips comments, CDATA and the XML declaration). */
function piLines(text: string): number[] {
  const lines: number[] = [];
  const re = /<!--[\s\S]*?-->|<!\[CDATA\[[\s\S]*?\]\]>|<\?([^\s?>]*)/g;
  for (let m = re.exec(text); m !== null; m = re.exec(text)) {
    if (m[1] === undefined || (m.index === 0 && m[1].toLowerCase() === 'xml')) continue;
    let line = 1;
    for (let i = text.indexOf('\n'); i >= 0 && i < m.index; i = text.indexOf('\n', i + 1)) line++;
    lines.push(line);
  }
  return lines;
}

function findProcessingInstructions(doc: XNodeLike, text: string): ValidationIssue[] {
  const out: ValidationIssue[] = [];
  const lines = piLines(text);
  const walk = (n: XNodeLike, top: boolean): void => {
    for (let i = 0; i < n.childNodes.length; i++) {
      const c = n.childNodes[i];
      if (c === undefined) continue;
      if (c.nodeType === 7) {
        const target = (c as unknown as { target?: string }).target ?? '';
        // the XML declaration is not a processing instruction (xmldom reports it as one)
        if (!(top && i === 0 && target.toLowerCase() === 'xml')) {
          out.push(new ValidationIssue(lines[out.length] ?? 0, 0, 'fatal', 'Processing instructions are not allowed.', 'processing-instruction'));
        }
      } else if (c.nodeType === 1) {
        walk(c, false);
      }
    }
  };
  walk(doc, true);
  return out;
}
