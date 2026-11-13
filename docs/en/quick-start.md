# Quick start

This guide takes you from installation to your first KSeF call in PHP, Laravel, TypeScript and NestJS. Everything runs against the **TEST** environment with synthetic data.

## Contents

- [Before you begin](#before-you-begin)
- [PHP](#php)
- [TypeScript](#typescript)
- [Laravel](#laravel)
- [NestJS](#nestjs)
- [Delay after /testdata/person](#delay-after-testdataperson)
- [Next steps](#next-steps)

## Before you begin

**The packages are not published yet** to Packagist or npm (they are prepared for publication). The instructions below install from a clone of the repository. Once the packages appear, `composer require luroweb/ksef-foundry` and `npm install @luroweb/ksef-foundry` will be enough; check the [CHANGELOG](../../CHANGELOG.md) and the repository page.

```bash
git clone https://github.com/mysterekkk/ksef-foundry.git
```

**The examples need no secrets.** If you do not set `KSEF_TOKEN` and `KSEF_CONTEXT_NIP`, each example creates a synthetic taxpayer on TEST by itself:

1. it generates a checksum-valid NIP and PESEL (never real ones),
2. it calls `POST /testdata/person`,
3. it generates a self-signed personal certificate (TEST only) and logs in with it (XAdES),
4. when finished, it removes the synthetic taxpayer (best effort).

If you have your own KSeF token **from the TEST environment**, set `KSEF_TOKEN` and `KSEF_CONTEXT_NIP`. Do not use production tokens or data.

> No code switches environments on its own. TEST is the default; PRODUCTION must be chosen explicitly ([Environments](environments.md)).

## PHP

Requirements: PHP >= 8.2 with the `bcmath`, `dom`, `json`, `libxml`, `mbstring` and `openssl` extensions, plus `curl` for the built-in HTTP client (you can inject any PSR-18 client instead).

**Install from a clone** (a `path` repository, as in `examples/php/composer.json`):

```json
{
    "repositories": [
        { "type": "path", "url": "../ksef-foundry/packages/php", "options": { "symlink": true } }
    ],
    "require": { "luroweb/ksef-foundry": "@dev" },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

**Configuration.** The SDK does not read environment variables itself; the examples read `KSEF_TOKEN` and `KSEF_CONTEXT_NIP` through `getenv()`. In your application you pass credentials as an object:

```php
use LuroWeb\KsefFoundry\Auth\KsefTokenCredentials;
use LuroWeb\KsefFoundry\Context\ContextIdentifier;
use LuroWeb\KsefFoundry\Environment;
use LuroWeb\KsefFoundry\KsefClient;
use LuroWeb\KsefFoundry\Support\SensitiveString;

$client = KsefClient::create(
    new KsefTokenCredentials(new SensitiveString(getenv('KSEF_TOKEN')), ContextIdentifier::nip(getenv('KSEF_CONTEXT_NIP'))),
    Environment::test(),
);
```

**First call** with a synthetic taxpayer (excerpt from `examples/php/src/bootstrap.php` and `01-quickstart.php`):

```php
use LuroWeb\KsefFoundry\Auth\CertificateCredentials;
use LuroWeb\KsefFoundry\Testing\SyntheticIdentity;
use LuroWeb\KsefFoundry\Testing\TestCertificateFactory;

$nip = SyntheticIdentity::nip();
$pesel = SyntheticIdentity::pesel();
$cert = (new TestCertificateFactory())->personWithPesel($pesel);
$client = KsefClient::create(
    new CertificateCredentials($cert->certificatePem, $cert->privateKeyPem, ContextIdentifier::nip($nip)),
    Environment::test(),
);
$client->testData()->createPerson($nip, $pesel, 'ksef-foundry example taxpayer');
// Permissions after /testdata/person take a few seconds to apply (see below).
echo json_encode($client->limits()->context());
```

Sending a sample invoice and fetching the UPO (`examples/php/src/03-send-invoice.php`):

```php
$xml = Fa3InvoiceBuilder::sampleXml($nip, 'FV/EX/' . time());
$result = $client->sender()->submit($xml);          // returns only after status 200
echo $result->ksefNumber;
$upo = $client->upo()->invoiceUpo($result->sessionReferenceNumber, $result->invoiceReferenceNumber);
```

**Running the ready-made examples:**

```bash
cd ksef-foundry/examples/php
composer install
composer quickstart      # limits and a metadata query
composer build-validate  # build and validate FA(3), offline
composer send            # send a sample invoice to TEST
composer errors          # error handling (local demo server)
```

## TypeScript

Requirements: Node >= 22, ESM. The SDK uses `fetch`; XSD validation runs offline (libxml2 compiled to WebAssembly).

**Install from a clone** (an npm workspace in the monorepo):

```bash
cd ksef-foundry
npm install
npm run build -w @luroweb/ksef-foundry
```

**Install in your own project** through a packed tarball (this is what `npm run verify:pack` does in CI):

```bash
cd ksef-foundry && npm install && npm run build -w @luroweb/ksef-foundry
npm pack -w @luroweb/ksef-foundry          # creates luroweb-ksef-foundry-0.1.0.tgz
cd ../my-project && npm install ../ksef-foundry/luroweb-ksef-foundry-0.1.0.tgz
npm install -D @peculiar/x509 reflect-metadata   # only for TestCertificateFactory (TEST)
```

**Configuration and first call:**

```ts
import { Context, Environment, KsefClient, KsefTokenCredentials } from '@luroweb/ksef-foundry';

const client = KsefClient.create({
  environment: Environment.TEST, // TEST is the default; may be omitted
  credentials: new KsefTokenCredentials(process.env.KSEF_TOKEN!, Context.nip(process.env.KSEF_CONTEXT_NIP!)),
});
console.log(await client.limits.context());
```

`KsefClient.create` sends nothing; login happens on the first call.

The synthetic taxpayer (`examples/typescript/src/provision.ts`) uses `generateTestNip()`, `generateTestPesel()`, `TestCertificateFactory.createPersonal(...)`, `CertificateCredentials` and `client.testData.createPerson(...)`.

**Running the examples** (from the monorepo root):

```bash
npm run 01:quickstart   -w @luroweb/ksef-foundry-example-typescript
npm run 02:build-validate -w @luroweb/ksef-foundry-example-typescript
npm run 03:send         -w @luroweb/ksef-foundry-example-typescript
npm run 04:errors       -w @luroweb/ksef-foundry-example-typescript
```

## Laravel

Requirements: PHP >= 8.2, Laravel 12. The suite was run on Laravel 12.72.0 (PHP 8.4.20).

**Install from a clone.** Add both `path` repositories to your app's `composer.json` (as in `examples/laravel/composer.json`) and require the adapter:

```json
{
    "repositories": [
        { "type": "path", "url": "../ksef-foundry/packages/laravel",
          "options": { "symlink": true, "versions": { "luroweb/laravel-ksef-foundry": "0.1.x-dev" } } },
        { "type": "path", "url": "../ksef-foundry/packages/php",
          "options": { "symlink": true, "versions": { "luroweb/ksef-foundry": "0.1.x-dev" } } }
    ],
    "require": { "luroweb/laravel-ksef-foundry": "@dev" },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

**Configuration through `.env`:**

```dotenv
KSEF_ENVIRONMENT=test      # test (default) | demo | production
KSEF_AUTH=token            # token | certificate
KSEF_CONTEXT_TYPE=nip
KSEF_CONTEXT_NIP=<TEST-taxpayer-NIP>
KSEF_TOKEN=<TEST-KSeF-token>
```

**First call:**

```bash
php artisan ksef:check
```

The command prints the environment, public keys, login result and limits (no secrets). In code, inject `KsefClient`:

```php
public function __construct(private readonly KsefClient $ksef) {}
// ...
$limits = $this->ksef->limits()->context();
```

A ready-made app lives in `examples/laravel` (instructions in [Laravel](laravel.md)). Its live test creates a synthetic taxpayer on its own; a normal run needs a TEST token in `.env`.

## NestJS

Requirements: Node >= 22, NestJS `^11` (Nest 10 was not verified); the package is ESM.

**Install from a clone** (run the example app):

```bash
cd ksef-foundry
npm install
npm run build -w @luroweb/ksef-foundry
npm run build -w @luroweb/nestjs-ksef-foundry
cp examples/nestjs/.env.example examples/nestjs/.env   # fill in the values
npm run start -w ksef-foundry-example-nestjs
```

In your own project, pack both packages (`npm pack -w ...`) and install the tarballs together with `@nestjs/common`, `@nestjs/core`, `reflect-metadata` and `rxjs`.

**Configuration and injection:**

```ts
import { Context, KsefTokenCredentials } from '@luroweb/ksef-foundry';
import { KsefModule } from '@luroweb/nestjs-ksef-foundry';

@Module({
  imports: [
    KsefModule.forRoot({
      isGlobal: true,
      credentials: new KsefTokenCredentials(process.env.KSEF_TOKEN!, Context.nip(process.env.KSEF_CONTEXT_NIP!)),
      logger: 'nest',
    }),
  ],
})
export class AppModule {}
```

```ts
constructor(@Inject(KsefService) private readonly ksef: KsefService) {}
// ...
const page = await this.ksef.queryInvoices({ subjectType: 'Subject1', dateRange: { dateType: 'Issue', from: new Date(Date.now() - 30 * 86_400_000) } });
```

Details: [NestJS](nestjs.md).

## Delay after /testdata/person

On TEST, creating a person through `/testdata/person` takes a few seconds to take effect. Logging in right after it can end with authentication status **415 "No permissions assigned"**; the same request a few seconds later succeeds. The examples retry (up to 8 times, 2 s apart). Details: [Authentication](authentication.md#status-415-right-after-testdataperson).

## Next steps

- [FA(3) invoices](fa3-invoices.md): build and validate your own invoice.
- [Invoice lifecycle](invoice-lifecycle.md): what happens after `submit()`.
- [Environments](environments.md): etiquette for the shared TEST environment.

---

Built and maintained by LuroWeb.
