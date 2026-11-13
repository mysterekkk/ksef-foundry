import { mkdtempSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:http';
import type { AddressInfo } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { DOMParser } from '@xmldom/xmldom';
import { describe, expect, it } from 'vitest';
import { Fa3Address, Fa3InvoiceBuilder, InvoiceBuilderException, InvoiceValidationException, XmlValidator } from '../../src/invoice/index.js';
import { REPO_ROOT, readText, validXml } from './helpers.js';

const validator = new XmlValidator();
const NS = 'http://crd.gov.pl/wzor/2025/06/25/13775/';

describe('XML security', () => {
  it('billion laughs is rejected before parsing with bounded memory and time', async () => {
    let dtd = '<!ENTITY l0 "ha">';
    for (let i = 1; i <= 12; i++) dtd += `<!ENTITY l${i} "${`&l${i - 1};`.repeat(10)}">`;
    const xml = validXml().replace('<Faktura', `<!DOCTYPE Faktura [${dtd}]>\n<Faktura`).replace('Usługa programistyczna', '&l12;');
    const before = process.memoryUsage().rss;
    const t = performance.now();
    const r = await validator.validateFa3(xml);
    const elapsed = performance.now() - t;
    expect(r.codes()).toEqual(['doctype']);
    expect(elapsed).toBeLessThan(2000);
    expect(process.memoryUsage().rss - before).toBeLessThan(64 * 1024 * 1024);
  });

  it('XXE with network, file and php/expect entities is rejected before parsing', async () => {
    for (const uri of ['http://127.0.0.1:9/x', 'file:///etc/passwd', 'php://filter/convert.base64-encode/resource=/etc/passwd', 'expect://id']) {
      const xml = validXml().replace('<Faktura', `<!DOCTYPE Faktura [<!ENTITY x SYSTEM "${uri}">]>\n<Faktura`).replace('Usługa programistyczna', '&x;');
      expect((await validator.validateFa3(xml)).codes(), uri).toEqual(['doctype']);
    }
    const dtd = validXml().replace('<Faktura', '<!DOCTYPE Faktura SYSTEM "http://127.0.0.1:9/evil.dtd">\n<Faktura');
    expect((await validator.validateFa3(dtd)).codes()).toEqual(['doctype']);
  });

  it('validation never opens a socket: schema import and xsi:schemaLocation to a live local listener', async () => {
    let hits = 0;
    const server = createServer((_req, res) => {
      hits++;
      res.end('<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"/>');
    });
    await new Promise<void>((ok) => server.listen(0, '127.0.0.1', ok));
    const port = (server.address() as AddressInfo).port;
    try {
      // (a) a schema that imports over http from the local listener
      const dir = mkdtempSync(join(tmpdir(), 'ksef-xsd-'));
      const main = join(dir, 'main.xsd');
      writeFileSync(
        main,
        `<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:import namespace="urn:x" schemaLocation="http://127.0.0.1:${port}/x.xsd"/><xs:element name="a" type="xs:string"/></xs:schema>`,
      );
      const r = await new XmlValidator({ schemaPath: main }).validateFa3('<?xml version="1.0" encoding="UTF-8"?><a>x</a>');
      expect(hits).toBe(0);
      expect(r.codes()).not.toContain('doctype');
      // (b) a document whose xsi:schemaLocation points at the listener, validated with the bundled schema
      const xml = validXml().replace(
        `<Faktura xmlns="${NS}"`,
        `<Faktura xmlns="${NS}" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="${NS} http://127.0.0.1:${port}/y.xsd"`,
      );
      const r2 = await validator.validateFa3(xml);
      expect(hits).toBe(0);
      expect(r2.isValid(), r2.summary()).toBe(true);
    } finally {
      await new Promise<void>((ok) => server.close(() => ok()));
    }
  });

  it('negative control: the official schema with remote imports is NOT silently accepted (engine refuses, no fetch)', async () => {
    const official = `${REPO_ROOT}/schemas/xsd/FA/schemat_FA3_v1-0E.xsd`;
    const r = await new XmlValidator({ schemaPath: official }).validateFa3(validXml());
    expect(r.isValid()).toBe(false);
    expect(r.codes()).toEqual(['engine']);
  });

  it('bundled resolved schema contains no http(s) schemaLocation', () => {
    for (const f of ['schemat_FA3_v1-0E.xsd', 'bazowe/StrukturyDanych_v10-0E.xsd', 'bazowe/ElementarneTypyDanych_v10-0E.xsd', 'bazowe/KodyKrajow_v10-0E.xsd']) {
      expect(readText(`${XmlValidator.bundledSchemaDir()}/${f}`), f).not.toMatch(/schemaLocation="https?:\/\//);
    }
  });

  it('size limit is configurable', async () => {
    expect((await new XmlValidator({ maxBytes: 1000 }).validateFa3(validXml())).codes()).toEqual(['size']);
    expect((await new XmlValidator({ maxBytes: 100000 }).validateFa3(validXml())).isValid()).toBe(true);
    expect(() => new XmlValidator({ maxBytes: 0 })).toThrow(RangeError);
  });

  it('assertValid throws InvoiceValidationException with issues', async () => {
    const err = await validator.assertValid('<?xml version="1.0" encoding="UTF-8"?><a/>').catch((e: unknown) => e);
    expect(err).toBeInstanceOf(InvoiceValidationException);
    const e = err as InvoiceValidationException;
    expect(e.issues.length).toBeGreaterThan(0);
    expect(e.issues[0]?.code).toBe('xsd');
    expect(e.errors.length).toBe(e.issues.length);
  });

  it('malformed and empty inputs are rejected', async () => {
    for (const x of ['', '   ', '<a>', 'plain text', '<?xml version="1.0"?>\n<a></b>']) {
      expect((await validator.validateFa3(x)).isValid(), JSON.stringify(x)).toBe(false);
    }
    const r = await validator.validateFa3('<a>');
    expect(r.codes()).toEqual(['xml']);
    expect(r.errors()[0]?.line).toBe(1);
  });

  it('rejects BOM / UTF-16 / lone surrogate string / invalid UTF-8 bytes', async () => {
    expect((await validator.validateFa3(`\uFEFF${validXml()}`)).codes()).toContain('bom');
    expect((await validator.validateFa3(Buffer.from(validXml(), 'utf16le'))).codes()).toContain('encoding');
    expect((await validator.validateFa3(`<a>\uD800</a>`)).codes()).toEqual(['utf8']);
    expect((await validator.validateFa3(Buffer.from([0x3c, 0x61, 0x3e, 0xf3, 0x3c, 0x2f, 0x61, 0x3e]))).codes()).toEqual(['utf8']);
  });

  it('rejects processing instructions, also outside the root, but not the XML declaration', async () => {
    const inside = validXml().replace('<Naglowek>', '<Naglowek><?php echo 1; ?>');
    expect((await validator.validateFa3(inside)).codes()).toEqual(['processing-instruction']);
    const after = `${validXml().trimEnd()}\n<?pi x?>`;
    expect((await validator.validateFa3(after)).codes()).toEqual(['processing-instruction']);
    const inComment = validXml().replace('<Naglowek>', '<Naglowek><!-- <?php x ?> -->');
    expect((await validator.validateFa3(inComment)).isValid()).toBe(true);
  });

  it('validator rejects forbidden ranges, allows boundary characters', async () => {
    const xml = validXml();
    for (const ch of ['\u007F', '\u0084', '\u0086', '\u009F', '﷐', '﷯', '\u{1FFFE}', '\u{10FFFF}', '￾']) {
      const r = await validator.validateFa3(xml.replace('Usługa programistyczna', `a${ch}b`));
      expect(r.codes(), ch.codePointAt(0)?.toString(16)).toContain('forbidden-char');
    }
    for (const ch of ['\u0085', ' ', '﷏', 'ﷰ']) {
      const r = await validator.validateFa3(xml.replace('Usługa programistyczna', `a${ch}b`));
      expect(r.codes(), ch.codePointAt(0)?.toString(16)).not.toContain('forbidden-char');
    }
  });

  it('a 1 MB invoice validates quickly with bounded WASM memory', async () => {
    const b = Fa3InvoiceBuilder.create()
      .seller('5260250274', 'S', new Fa3Address('a'))
      .buyerNoId('B', new Fa3Address('b'))
      .number('BIG/1')
      .issueDate('2026-01-15')
      .createdAt('2026-01-15T10:00:00Z')
      .withoutSelfValidation();
    for (let i = 1; i <= 4600; i++) b.addLine(`Pozycja numer ${i} ąęł`, '1.5', '10.10', i % 2 === 0 ? '23' : '8', 'szt.');
    const xml = await b.build();
    expect(Buffer.byteLength(xml)).toBeGreaterThan(1_000_000);
    const t = performance.now();
    const r = await validator.validateFa3(xml);
    const elapsed = performance.now() - t;
    expect(r.isValid(), r.summary()).toBe(true);
    expect(elapsed).toBeLessThan(10_000);
  });
});

describe('injection', () => {
  const hostile = ['x]]>y', '</P_7><P_7>injected', '<![CDATA[boom]]>', 'a & b < c > d " e \' f', '&amp; &lt;script&gt;', '<?php echo 1; ?>', '<!-- c -->', 'line1\nline2\tTab', '日本語 ąęłóżźćń 😀'];

  const buildWith = async (s: string): Promise<string> =>
    Fa3InvoiceBuilder.create()
      .seller('5260250274', 'Seller', new Fa3Address('ul. A 1'))
      .buyerNoId(s, new Fa3Address('ul. B 2'))
      .number('INJ/1')
      .issueDate('2026-01-15')
      .createdAt('2026-01-15T10:00:00Z')
      .addLine(s, 1, '10.00', '23')
      .additionalInfo('k', s)
      .build();

  const structure = (xml: string): string[] => {
    const doc = new DOMParser().parseFromString(xml, 'text/xml');
    return Array.from(doc.getElementsByTagName('*')).map((e) => e.localName ?? '');
  };

  it('hostile strings are escaped and never change the structure', async () => {
    const baseline = structure(await buildWith('plain'));
    for (const s of hostile) {
      const xml = await buildWith(s);
      expect(structure(xml), JSON.stringify(s)).toEqual(baseline);
      const doc = new DOMParser().parseFromString(xml, 'text/xml');
      const expected = s.replace(/[ \t\r\n]+/g, ' ').replace(/^ +| +$/g, '');
      const text = (tag: string): string => doc.getElementsByTagName(tag)[0]?.textContent ?? '';
      expect(text('P_7')).toBe(expected);
      expect(text('Wartosc')).toBe(expected);
      expect(doc.getElementsByTagName('Nazwa')[1]?.textContent).toBe(expected);
      expect((await validator.validateFa3(xml)).isValid()).toBe(true);
    }
  });

  it('forbidden or broken strings are rejected before any XML', async () => {
    const bad = ['a\0b', 'a\x01b', 'a\x1Fb', 'lone\uD800surrogate', 'lone\uDC00surrogate', 'a\u0090b', 'a﷐b', 'a\u{1FFFE}b', 'a￿b', ''];
    for (const s of bad) await expect(buildWith(s), JSON.stringify(s)).rejects.toThrow(InvoiceBuilderException);
    await expect(buildWith('a\u0085b')).resolves.toContain('a\u0085b'); // U+0085 is allowed (not in the KSeF list)
  });
});

describe('processing instruction position', () => {
  it('reports the line of the PI like PHP does', async () => {
    const r = await new XmlValidator().validateFa3(validXml().replace('<Naglowek>', '<Naglowek><?php echo 1; ?>'));
    expect(r.errors()[0]?.line).toBe(3);
  });
});
