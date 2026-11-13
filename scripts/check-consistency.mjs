#!/usr/bin/env node
// Repository consistency gate (runs in CI, no network):
//  1. vendored OpenAPI files match schemas/openapi/manifest.json (sha256)
//  2. resolved FA(3) XSD bundle is byte-identical in schemas/, packages/php and packages/typescript
//  3. no http(s) schemaLocation remains in the resolved bundle (validation must work offline)
//  4. package versions agree (php/laravel composer branch-alias vs npm versions) and docs mention the verified API version
//  5. no obvious secret material is tracked (private keys, bearer tokens, KSeF token-like strings)

import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { readFile, readdir } from 'node:fs/promises';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const sha = (b) => createHash('sha256').update(b).digest('hex');
const problems = [];
const fail = (m) => problems.push(m);

// 1 -----------------------------------------------------------------
const manifest = JSON.parse(await readFile(join(root, 'schemas/openapi/manifest.json'), 'utf8'));
for (const [env, info] of Object.entries(manifest.contracts)) {
  const actual = sha(await readFile(join(root, `schemas/openapi/${env}.json`)));
  if (actual !== info.sha256) fail(`openapi/${env}.json hash ${actual.slice(0, 12)} != manifest ${info.sha256.slice(0, 12)}`);
}

// 2 + 3 -------------------------------------------------------------
async function bundle(dir) {
  const out = new Map();
  let names;
  try {
    names = (await readdir(dir)).filter((n) => n.endsWith('.xsd')).sort();
  } catch {
    fail(`missing XSD bundle directory ${relative(root, dir)}`);
    return out;
  }
  for (const n of names) out.set(n, await readFile(join(dir, n)));
  return out;
}
const ref = await bundle(join(root, 'schemas/xsd/FA/resolved'));
for (const dir of ['packages/php/resources/xsd/FA3', 'packages/typescript/resources/xsd/FA3']) {
  const other = await bundle(join(root, dir));
  if ([...ref.keys()].join() !== [...other.keys()].join()) fail(`${dir}: file list differs from schemas/xsd/FA/resolved`);
  for (const [n, buf] of ref) if (other.has(n) && sha(other.get(n)) !== sha(buf)) fail(`${dir}/${n}: differs from resolved bundle`);
}
for (const [n, buf] of ref) {
  if (/schemaLocation\s*=\s*"https?:/i.test(buf.toString('utf8'))) fail(`resolved/${n}: contains remote schemaLocation`);
}

// 4 -----------------------------------------------------------------
const versions = {};
for (const p of ['packages/typescript', 'packages/nestjs']) {
  versions[p] = JSON.parse(await readFile(join(root, p, 'package.json'), 'utf8')).version;
}
for (const p of ['packages/php', 'packages/laravel']) {
  try {
    const c = JSON.parse(await readFile(join(root, p, 'composer.json'), 'utf8'));
    versions[p] = (c.extra?.['branch-alias']?.['dev-main'] ?? '').replace(/\.x-dev$/, '') || c.version;
  } catch {
    /* package may not exist yet */
  }
}
const minor = (v) => String(v).split('.').slice(0, 2).join('.');
const minors = new Set(Object.values(versions).map(minor));
if (minors.size > 1) fail(`package minor versions disagree: ${JSON.stringify(versions)}`);
const compat = await readFile(join(root, 'docs/api-compatibility.md'), 'utf8');
for (const [env, info] of Object.entries(manifest.contracts)) {
  if (!compat.includes(info.sha256.slice(0, 12))) fail(`docs/api-compatibility.md does not list the ${env} contract hash ${info.sha256.slice(0, 12)}`);
}

// 5 -----------------------------------------------------------------
let files = [];
try {
  files = execFileSync('git', ['ls-files', '-z'], { cwd: root, encoding: 'utf8' }).split('\0').filter(Boolean);
} catch {
  /* not a git checkout */
}
const patterns = [
  // a real key has a long base64 body; short placeholders in redaction tests are not keys
  [/-----BEGIN (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----s*[A-Za-z0-9+/=s]{200,}?-----END/, 'private key'],
  [/\bgh[pousr]_[A-Za-z0-9]{30,}\b/, 'GitHub token'],
  [/\beyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}\b/, 'JWT'],
];
// official example JWTs copied from the MF OpenAPI into generated types
const allowJwt = new Set(['packages/typescript/src/generated/openapi.d.ts']);
const textExt = /\.(md|ts|js|mjs|php|json|yml|yaml|xml|xsd|txt|env|example|neon|dist|html)$/i;
const skip = (process.env.KSEF_SCAN_SKIP ?? '').split(',').filter(Boolean); // local use only; CI scans everything
for (const f of files) {
  if (skip.some((s) => f.endsWith(s))) continue;
  if (!textExt.test(f) || f.startsWith('schemas/openapi/') || f.endsWith('package-lock.json') || f.endsWith('composer.lock')) continue;
  const text = await readFile(join(root, f), 'utf8').catch(() => '');
  for (const [re, label] of patterns) {
    if (label === 'JWT' && allowJwt.has(f)) continue;
    if (re.test(text)) fail(`${f}: looks like it contains a ${label}`);
  }
}

if (problems.length > 0) {
  console.error('Consistency check FAILED:\n - ' + problems.join('\n - '));
  process.exit(1);
}
console.log(`Consistency check OK (versions: ${JSON.stringify(versions)})`);
