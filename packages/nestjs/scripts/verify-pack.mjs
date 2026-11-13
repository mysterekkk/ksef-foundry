// npm pack (core SDK + this package) -> install both tarballs plus Nest into a temp project ->
// boot a tiny Nest app with real DI -> ESM import smoke -> publint on the real tarball. Never publishes anything.
import { spawnSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const coreRoot = resolve(root, '../typescript');
const sh = process.platform === 'win32';
function run(cmd, args, cwd) {
  const r = spawnSync([cmd, ...args].join(' '), { cwd, encoding: 'utf8', shell: sh || true });
  if (r.status !== 0) {
    console.error(`FAILED: ${cmd} ${args.join(' ')}\n${r.stdout}\n${r.stderr}`);
    process.exit(1);
  }
  return r.stdout;
}

run('npm', ['run', 'build'], coreRoot);
run('npm', ['run', 'build'], root);
const tmp = mkdtempSync(join(tmpdir(), 'ksef-nest-verify-pack-'));
try {
  const packDir = join(tmp, 'pack');
  mkdirSync(packDir);
  run('npm', ['pack', '--pack-destination', packDir], coreRoot);
  run('npm', ['pack', '--pack-destination', packDir], root);
  const tgzs = readdirSync(packDir).filter((f) => f.endsWith('.tgz'));
  const core = tgzs.find((f) => f.startsWith('luroweb-ksef-foundry-'));
  const nest = tgzs.find((f) => f.startsWith('luroweb-nestjs-ksef-foundry-'));
  if (core === undefined || nest === undefined) throw new Error('tarballs missing');
  console.log(`packed: ${nest} (+ ${core})`);

  const listing = JSON.parse(run('npm', ['pack', '--dry-run', '--json'], root));
  const files = listing[0].files.map((f) => f.path).filter((p) => !p.endsWith('.map'));
  console.log(`${String(files.length)} files in tarball (without maps): ${files.join(', ')}`);
  for (const must of ['README.md', 'CHANGELOG.md', 'LICENSE', 'package.json', 'dist/index.js', 'dist/index.d.ts']) {
    if (!files.includes(must)) throw new Error(`tarball is missing ${must}`);
  }
  if (files.some((p) => p.startsWith('test/') || p.startsWith('src/') || p.endsWith('.pem') || p.endsWith('.env'))) {
    throw new Error('unexpected files in tarball');
  }

  const app = join(tmp, 'app');
  mkdirSync(app);
  writeFileSync(join(app, 'package.json'), JSON.stringify({ name: 'smoke', version: '1.0.0', private: true, type: 'module' }));
  run(
    'npm',
    ['install', '--no-audit', '--no-fund', `"${join(packDir, core)}"`, `"${join(packDir, nest)}"`, '@nestjs/common@^11', '@nestjs/core@^11', '@nestjs/platform-express@^11', 'reflect-metadata', 'rxjs'],
    app,
  );

  const smoke = `
import 'reflect-metadata';
import { Controller, Get, Inject, Module } from '@nestjs/common';
import { NestFactory } from '@nestjs/core';
import { Context, Environment, KsefClient, KsefTokenCredentials } from '@luroweb/ksef-foundry';
import { KsefModule, KsefService, KsefClientRegistry, KsefExceptionFilter, KsefHealthIndicator, KSEF_CLIENT } from '@luroweb/nestjs-ksef-foundry';

class XController {
  constructor(client, service) {
    this.client = client;
    this.service = service;
  }
  get() {
    return { same: this.client === this.service.client, env: this.client.environment.name, isClient: this.client instanceof KsefClient };
  }
}
// plain .mjs has no decorator syntax: apply the Nest decorators programmatically
Inject(KSEF_CLIENT)(XController, undefined, 0);
Inject(KsefService)(XController, undefined, 1);
Controller('x')(XController);
Get()(XController.prototype, 'get', Object.getOwnPropertyDescriptor(XController.prototype, 'get'));
class AppModule {}
Module({
  imports: [KsefModule.forRoot({ credentials: new KsefTokenCredentials('x'.repeat(12), Context.nip('5265877635')) })],
  controllers: [XController],
})(AppModule);
const app = await NestFactory.create(AppModule, { logger: false, abortOnError: false });
app.useGlobalFilters(new KsefExceptionFilter());
await app.listen(0, '127.0.0.1');
const url = await app.getUrl();
const body = await (await fetch(url + '/x')).json();
if (!body.same || body.env !== 'TEST' || !body.isClient) throw new Error('bad wiring ' + JSON.stringify(body));
if (!(app.get(KsefClientRegistry).get('default') instanceof KsefClient)) throw new Error('registry');
if (typeof KsefHealthIndicator !== 'function' || Environment.TEST.name !== 'TEST') throw new Error('exports');
await app.close();
console.log('ESM boot OK: ' + JSON.stringify(body));
`;
  writeFileSync(join(app, 'smoke.mjs'), smoke);
  console.log(run('node', ['smoke.mjs'], app).trim());

  console.log('--- publint');
  console.log(run('npx', ['publint', `"${join(packDir, nest)}"`], root).trim());
  console.log('verify:pack OK');
} finally {
  rmSync(tmp, { recursive: true, force: true });
}
