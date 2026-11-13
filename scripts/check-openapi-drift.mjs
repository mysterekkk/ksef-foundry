#!/usr/bin/env node
// Detects changes in the official KSeF OpenAPI contracts compared with the copies
// vendored in schemas/openapi/. It NEVER updates the SDK: it only reports.
//
//   node scripts/check-openapi-drift.mjs            # compare, exit 1 on drift
//   node scripts/check-openapi-drift.mjs --update   # refresh vendored copies + manifest (then run all tests!)
//
// Only the three official, public KSeF hosts are contacted (read-only GET).

import { createHash } from 'node:crypto';
import { readFile, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const SOURCES = {
  test: 'https://api-test.ksef.mf.gov.pl/docs/v2/openapi.json',
  demo: 'https://api-demo.ksef.mf.gov.pl/docs/v2/openapi.json',
  prod: 'https://api.ksef.mf.gov.pl/docs/v2/openapi.json',
};
const update = process.argv.includes('--update');
const sha256 = (b) => createHash('sha256').update(b).digest('hex');

const operations = (spec) => {
  const ops = new Map();
  for (const [path, item] of Object.entries(spec.paths ?? {})) {
    for (const [method, op] of Object.entries(item)) {
      if (['get', 'post', 'put', 'delete', 'patch'].includes(method)) {
        ops.set(`${method.toUpperCase()} ${path}`, Boolean(op.deprecated));
      }
    }
  }
  return ops;
};

let drift = false;
const manifest = { checkedAt: new Date().toISOString(), contracts: {} };

for (const [env, url] of Object.entries(SOURCES)) {
  const file = join(root, 'schemas', 'openapi', `${env}.json`);
  const res = await fetch(url, { redirect: 'error', signal: AbortSignal.timeout(60_000) });
  if (!res.ok) {
    console.error(`[${env}] cannot fetch ${url}: HTTP ${res.status}`);
    process.exitCode = 2;
    continue;
  }
  const remoteBytes = Buffer.from(await res.arrayBuffer());
  const localBytes = await readFile(file);
  const remote = JSON.parse(remoteBytes.toString('utf8'));
  const local = JSON.parse(localBytes.toString('utf8'));
  manifest.contracts[env] = { source: url, sha256: sha256(remoteBytes), infoTitle: remote.info?.title, paths: Object.keys(remote.paths ?? {}).length };

  if (sha256(remoteBytes) === sha256(localBytes)) {
    console.log(`[${env}] unchanged (${manifest.contracts[env].sha256.slice(0, 12)}…)`);
    continue;
  }
  drift = true;
  const a = operations(local);
  const b = operations(remote);
  const added = [...b.keys()].filter((k) => !a.has(k));
  const removed = [...a.keys()].filter((k) => !b.has(k));
  const newlyDeprecated = [...b].filter(([k, dep]) => dep && a.get(k) === false).map(([k]) => k);
  console.log(`[${env}] CHANGED`);
  console.log(`  added operations:        ${added.join(', ') || '-'}`);
  console.log(`  removed operations:      ${removed.join(', ') || '-'}`);
  console.log(`  newly deprecated:        ${newlyDeprecated.join(', ') || '-'}`);
  console.log('  (schema-level differences: diff the files — no automatic SDK update is performed)');
  if (update) await writeFile(file, remoteBytes);
}

if (update) {
  await writeFile(join(root, 'schemas', 'openapi', 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
  console.log('Vendored copies refreshed. Re-run ALL test suites (incl. live TEST) before releasing.');
}
if (drift && !update) {
  console.error('\nOpenAPI drift detected. Review api-changelog.md of CIRFMF/ksef-api, update SDKs + tests, then run with --update.');
  process.exitCode = 1;
}
