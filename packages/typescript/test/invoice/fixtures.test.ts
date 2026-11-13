import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { DOMParser } from '@xmldom/xmldom';
import { describe, expect, it } from 'vitest';
import { Fa3InvoiceBuilder, XmlValidator } from '../../src/invoice/index.js';
import { FIXTURES_DIR, REPO_ROOT, invalidCases, readBytes, readText, validCases } from './helpers.js';

const validator = new XmlValidator();

describe('FA(3) fixtures', () => {
  it('has at least 8 valid and 8 invalid fixtures', () => {
    expect(validCases().length).toBeGreaterThanOrEqual(8);
    expect(invalidCases().length).toBeGreaterThanOrEqual(8);
  });

  describe.each(validCases())('valid %s', (name) => {
    it('expected XML is valid against the real XSD', async () => {
      const r = await validator.validateFa3(readBytes(`${FIXTURES_DIR}/${name}.expected.xml`));
      expect(r.isValid(), r.summary()).toBe(true);
    });

    it('builder output is byte-identical to PHP and totals match', async () => {
      const b = Fa3InvoiceBuilder.fromJson(readText(`${FIXTURES_DIR}/${name}.input.json`));
      const xml = await b.build();
      const expected = readBytes(`${FIXTURES_DIR}/${name}.expected.xml`);
      expect(Buffer.from(xml, 'utf8').equals(expected)).toBe(true);
      const totals = readText(`${FIXTURES_DIR}/${name}.expected.totals.json`);
      // JSON text equality also pins key order (PHP assertSame on arrays is order sensitive)
      expect(JSON.stringify(b.totals().toArray())).toBe(JSON.stringify(JSON.parse(totals)));
    });

    it('totals.json agrees with the P_13/P_14/P_15 elements in the XML', () => {
      const doc = new DOMParser().parseFromString(readText(`${FIXTURES_DIR}/${name}.expected.xml`), 'text/xml');
      const fa = doc.getElementsByTagName('Fa')[0];
      const totals = JSON.parse(readText(`${FIXTURES_DIR}/${name}.expected.totals.json`)) as { fields: Record<string, string> };
      const found: Record<string, string> = {};
      for (let n = fa?.firstChild ?? null; n !== null; n = n.nextSibling) {
        if (n.nodeType === 1 && /^(P_13|P_14|P_15)/.test(n.nodeName)) found[n.nodeName] = n.textContent ?? '';
      }
      expect(found).toEqual(totals.fields);
    });
  });

  describe.each(invalidCases())('invalid %s', (name) => {
    it('fails for the documented reason', async () => {
      const bytes = readBytes(`${FIXTURES_DIR}/invalid/${name}.xml`);
      const spec = readText(`${FIXTURES_DIR}/invalid/${name}.expected-errors.txt`);
      const code = /^code=([a-z0-9-]+)\./m.exec(spec)?.[1];
      expect(code).toBeDefined();
      const r = await validator.validateFa3(bytes);
      expect(r.isValid(), `${name} must be rejected`).toBe(false);
      expect(r.codes(), r.summary()).toContain(code);
      const contains = /^contains=(.+)$/m.exec(spec)?.[1];
      if (contains !== undefined) expect(r.summary(50)).toContain(contains);
      expect(r.errors().length).toBeGreaterThan(0);
    });
  });

  it('oversize rule (3_000_001 byte comment after the prolog)', async () => {
    const xml = readText(`${FIXTURES_DIR}/01-single-line-23.expected.xml`);
    const pos = xml.indexOf('?>') + 2;
    const big = `${xml.slice(0, pos)}<!--${'a'.repeat(3_000_001)}-->${xml.slice(pos)}`;
    expect((await validator.validateFa3(big)).codes()).toEqual(['size']);
    const ok = await new XmlValidator({ maxBytes: big.length + 10 }).validateFa3(big);
    expect(ok.isValid(), ok.summary()).toBe(true);
  });

  describe('XSD bundle', () => {
    const files = ['schemat_FA3_v1-0E.xsd', 'bazowe/StrukturyDanych_v10-0E.xsd', 'bazowe/ElementarneTypyDanych_v10-0E.xsd', 'bazowe/KodyKrajow_v10-0E.xsd', 'NOTICE.txt', 'SHA256SUMS.txt'];
    const sha = (p: string): string => createHash('sha256').update(readFileSync(p)).digest('hex');

    it('is byte-identical to schemas/xsd/FA/resolved', () => {
      for (const f of files) {
        expect(sha(`${XmlValidator.bundledSchemaDir()}/${f}`), f).toBe(sha(`${REPO_ROOT}/schemas/xsd/FA/resolved/${f}`));
      }
    });

    it('matches the recorded resolved sha256 sums and has no remote schemaLocation', () => {
      const sums = readText(`${XmlValidator.bundledSchemaDir()}/SHA256SUMS.txt`);
      for (const f of files.slice(0, 4)) {
        const expected = new RegExp(`^resolved\\s+([0-9a-f]{64})\\s+${f.replace(/[.\\/]/g, '\\$&')}$`, 'm').exec(sums)?.[1];
        expect(sha(`${XmlValidator.bundledSchemaDir()}/${f}`), f).toBe(expected);
        expect(readText(`${XmlValidator.bundledSchemaDir()}/${f}`)).not.toMatch(/schemaLocation="https?:\/\//);
      }
    });

    it('resolved copies differ from the official ones only in schemaLocation', () => {
      const norm = (s: string): string => s.replace(/schemaLocation="[^"]*"/g, 'schemaLocation=""');
      for (const f of files.slice(0, 4)) {
        const src = readText(`${REPO_ROOT}/schemas/xsd/FA/${f}`);
        const res = readText(`${REPO_ROOT}/schemas/xsd/FA/resolved/${f}`);
        expect(norm(res), f).toBe(norm(src));
        expect(res).not.toMatch(/schemaLocation="https?:\/\//);
      }
    });
  });
});
