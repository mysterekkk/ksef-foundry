// npm pack -> install the tarball into a temp project -> ESM import + CJS require(esm) smoke tests
// -> publint + @arethetypeswrong/cli on the real tarball. Never publishes anything.
import { spawnSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const sh = process.platform === 'win32';
function run(cmd, args, cwd, opts = {}) {
  const r = spawnSync(cmd, args, { cwd, encoding: 'utf8', shell: sh, ...opts });
  if (r.status !== 0) {
    console.error(`FAILED: ${cmd} ${args.join(' ')}\n${r.stdout}\n${r.stderr}`);
    process.exit(1);
  }
  return r.stdout;
}

run('npm', ['run', 'build'], root);
const tmp = mkdtempSync(join(tmpdir(), 'ksef-verify-pack-'));
try {
  const packDir = join(tmp, 'pack');
  mkdirSync(packDir);
  run('npm', ['pack', '--pack-destination', packDir], root);
  const tgz = readdirSync(packDir).find((f) => f.endsWith('.tgz'));
  if (tgz === undefined) throw new Error('no tarball produced');
  const tarball = join(packDir, tgz);
  console.log(`packed: ${tgz}`);
  const listing = JSON.parse(run('npm', ['pack', '--dry-run', '--json'], root));
  const files = listing[0].files.map((f) => f.path).filter((p) => !p.endsWith('.map'));
  console.log(`${String(files.length)} files in tarball (without maps), e.g.: ${files.slice(0, 6).join(', ')}`);
  const xsd = files.filter((p) => p.startsWith('resources/xsd/FA3/') && p.endsWith('.xsd'));
  if (!xsd.includes('resources/xsd/FA3/schemat_FA3_v1-0E.xsd') || xsd.length < 3) throw new Error(`XSD bundle missing from tarball: ${xsd.join(',')}`);
  console.log(`XSD bundle in tarball: ${xsd.join(', ')}`);
  if (files.some((p) => p.startsWith('test/') || p.startsWith('src/') || p.endsWith('.pem'))) throw new Error('unexpected files in tarball');

  const app = join(tmp, 'app');
  mkdirSync(app);
  writeFileSync(join(app, 'package.json'), JSON.stringify({ name: 'smoke', version: '1.0.0', private: true }));
  run('npm', ['install', '--no-audit', '--no-fund', tarball, '@peculiar/x509', 'reflect-metadata'], app);

  const esm = `
import { KsefClient, Environment, SensitiveString, KsefTokenCredentials, Context, Crypto, TestCertificateFactory, generateTestNip } from '@luroweb/ksef-foundry';
const c = KsefClient.create({ credentials: new KsefTokenCredentials('x'.repeat(10), Context.nip(generateTestNip())) });
if (c.environment !== Environment.TEST) throw new Error('default env is not TEST');
if (String(new SensitiveString('s')) !== '[REDACTED]') throw new Error('redaction');
if (Crypto.sha256Base64('a') !== 'ypeBEsobvcr6wjGzmiPcTaeG7/gUfE5yuYB3ha/uSLs=') throw new Error('sha');
const cert = await TestCertificateFactory.createPersonal({ serialNumberPrefix: 'TINPL', identifier: generateTestNip() });
if (!cert.certificatePem.includes('BEGIN CERTIFICATE')) throw new Error('cert');
console.log('ESM import OK');
`;
  writeFileSync(join(app, 'smoke.mjs'), esm);
  console.log(run('node', ['smoke.mjs'], app).trim());

  const validation = `
import { Fa3InvoiceBuilder, XmlValidator, InvoiceValidationException, generateTestNip } from '@luroweb/ksef-foundry';
import { existsSync } from 'node:fs';
if (!existsSync(XmlValidator.bundledSchemaPath())) throw new Error('bundled XSD missing in installed package: ' + XmlValidator.bundledSchemaPath());
const nip = generateTestNip();
const xml = await Fa3InvoiceBuilder.sampleXml(nip, 'FV/PACK/1'); // build() self-validates against the XSD
const v = new XmlValidator();
const ok = await v.validateFa3(xml);
if (!ok.isValid()) throw new Error('valid sample reported invalid: ' + ok.summary());
const bad = await v.validateFa3(xml.replace('<P_2>FV/PACK/1</P_2>', '<P_2></P_2>'));
if (bad.isValid() || !bad.codes().includes('xsd')) throw new Error('invalid document reported valid');
const doctype = await v.validateFa3('<?xml version="1.0"?><!DOCTYPE a [<!ENTITY x "y">]><a/>');
if (doctype.isValid() || !doctype.codes().includes('doctype')) throw new Error('DOCTYPE not rejected');
let thrown = false;
try { await v.assertValid('<a/>'); } catch (e) { thrown = e instanceof InvoiceValidationException; }
if (!thrown) throw new Error('assertValid did not throw InvoiceValidationException');
console.log('FA(3) XSD validation from the installed tarball OK (valid accepted, xsd/doctype rejected)');
`;
  writeFileSync(join(app, 'validate.mjs'), validation);
  console.log(run('node', ['validate.mjs'], app).trim());

  const cjs = `
const m = require('@luroweb/ksef-foundry');
const { Environment, SensitiveString, Crypto } = m;
if (Environment.TEST.baseUrl !== 'https://api-test.ksef.mf.gov.pl/v2') throw new Error('env');
if (String(new SensitiveString('s')) !== '[REDACTED]') throw new Error('redaction');
if (typeof Crypto.sha256Base64 !== 'function') throw new Error('crypto');
console.log('CJS require(esm) OK on Node ' + process.versions.node);
`;
  writeFileSync(join(app, 'smoke.cjs'), cjs);
  console.log(run('node', ['smoke.cjs'], app).trim());

  console.log('--- publint');
  console.log(run('npx', ['publint', tarball], root).trim());
  console.log('--- attw');
  console.log(run('npx', ['attw', '--pack', '.', '--profile', 'esm-only', '--format', 'ascii'], root).trim());
  console.log('verify:pack OK');
} finally {
  rmSync(tmp, { recursive: true, force: true });
}
