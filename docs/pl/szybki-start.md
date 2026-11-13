# Szybki start

Ten przewodnik prowadzi od instalacji do pierwszego wywołania KSeF dla PHP, Laravel, TypeScript i NestJS. Wszystko odbywa się na środowisku **TEST** z danymi syntetycznymi.

## Spis treści

- [Zanim zaczniesz](#zanim-zaczniesz)
- [PHP](#php)
- [TypeScript](#typescript)
- [Laravel](#laravel)
- [NestJS](#nestjs)
- [Co dalej](#co-dalej)

## Zanim zaczniesz

**Pakiety npm są opublikowane** (`@luroweb/ksef-foundry`, `@luroweb/nestjs-ksef-foundry`), więc dla TypeScript i NestJS wystarczy `npm install`. **Pakiety Composer nie są jeszcze w Packagist** (są przygotowane do publikacji), więc dla PHP i Laravela użyj instalacji z klona repozytorium (repozytorium VCS/path), opisanej poniżej. Gdy pakiety Composer się pojawią, wystarczy `composer require luroweb/ksef-foundry`; sprawdź [CHANGELOG](../../CHANGELOG.md) i stronę repozytorium.

```bash
git clone https://github.com/mysterekkk/ksef-foundry.git
```

**Przykłady nie wymagają sekretów.** Gdy nie ustawisz `KSEF_TOKEN` i `KSEF_CONTEXT_NIP`, każdy przykład sam tworzy na TEST syntetycznego podatnika:

1. losuje poprawny sumą kontrolną NIP i PESEL (nigdy prawdziwe),
2. wywołuje `POST /testdata/person`,
3. generuje samopodpisany certyfikat osoby (tylko TEST) i loguje się nim (XAdES),
4. po zakończeniu usuwa syntetycznego podatnika (best effort).

Jeśli masz własny token KSeF **ze środowiska TEST**, ustaw `KSEF_TOKEN` i `KSEF_CONTEXT_NIP`. Nie używaj tokenów ani danych produkcyjnych.

> Nie ma kodu, który sam przełącza środowisko. Domyślnym środowiskiem jest TEST; PRODUCTION trzeba wskazać jawnie ([Środowiska](srodowiska.md)).

## PHP

Wymagania: PHP >= 8.2 z rozszerzeniami `bcmath`, `dom`, `json`, `libxml`, `mbstring`, `openssl` oraz `curl` (dla wbudowanego klienta HTTP; można podać dowolnego klienta PSR-18).

**Instalacja z klona** (repozytorium `path`, jak w `examples/php/composer.json`):

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

**Konfiguracja.** SDK nie czyta zmiennych środowiskowych samo; przykłady czytają `KSEF_TOKEN` i `KSEF_CONTEXT_NIP` przez `getenv()`. W aplikacji podajesz dane uwierzytelniające obiektem:

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

**Pierwsze wywołanie** z syntetycznym podatnikiem (fragment `examples/php/src/bootstrap.php` i `01-quickstart.php`):

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
// Uprawnienia po /testdata/person działają z opóźnieniem kilku sekund (patrz niżej).
echo json_encode($client->limits()->context());
```

Wysłanie faktury próbnej i pobranie UPO (`examples/php/src/03-send-invoice.php`):

```php
$xml = Fa3InvoiceBuilder::sampleXml($nip, 'FV/EX/' . time());
$result = $client->sender()->submit($xml);          // wraca dopiero po statusie 200
echo $result->ksefNumber;
$upo = $client->upo()->invoiceUpo($result->sessionReferenceNumber, $result->invoiceReferenceNumber);
```

**Uruchomienie gotowych przykładów:**

```bash
cd ksef-foundry/examples/php
composer install
composer quickstart      # limity i zapytanie o metadane
composer build-validate  # budowa i walidacja FA(3), offline
composer send            # wysyłka faktury próbnej na TEST
composer errors          # obsługa błędów (lokalny serwer demonstracyjny)
```

## TypeScript

Wymagania: Node >= 22, ESM. SDK korzysta z `fetch`; walidator XSD działa offline (libxml2 skompilowane do WebAssembly).

**Instalacja we własnym projekcie** (z npmjs.com):

```bash
npm install @luroweb/ksef-foundry
npm install -D @peculiar/x509 reflect-metadata   # opcjonalnie, tylko dla TestCertificateFactory (TEST)
```

**Instalacja z klona** (dla współtwórców; workspace npm w monorepo):

```bash
cd ksef-foundry
npm install
npm run build -w @luroweb/ksef-foundry
```

**Konfiguracja i pierwsze wywołanie:**

```ts
import { Context, Environment, KsefClient, KsefTokenCredentials } from '@luroweb/ksef-foundry';

const client = KsefClient.create({
  environment: Environment.TEST, // domyślnie TEST; można pominąć
  credentials: new KsefTokenCredentials(process.env.KSEF_TOKEN!, Context.nip(process.env.KSEF_CONTEXT_NIP!)),
});
console.log(await client.limits.context());
```

`KsefClient.create` nic nie wysyła; logowanie następuje przy pierwszym wywołaniu.

Syntetyczny podatnik (`examples/typescript/src/provision.ts`) używa `generateTestNip()`, `generateTestPesel()`, `TestCertificateFactory.createPersonal(...)`, `CertificateCredentials` oraz `client.testData.createPerson(...)`.

**Uruchomienie przykładów** (z korzenia monorepo):

```bash
npm run 01:quickstart   -w @luroweb/ksef-foundry-example-typescript
npm run 02:build-validate -w @luroweb/ksef-foundry-example-typescript
npm run 03:send         -w @luroweb/ksef-foundry-example-typescript
npm run 04:errors       -w @luroweb/ksef-foundry-example-typescript
```

## Laravel

Wymagania: PHP >= 8.2, Laravel 12. Zestaw testów uruchomiono na Laravel 12.72.0 (PHP 8.4.20).

**Instalacja z klona.** W `composer.json` aplikacji dodaj oba repozytoria `path` (jak w `examples/laravel/composer.json`) i wymagaj pakietu adaptera:

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

**Konfiguracja przez `.env`:**

```dotenv
KSEF_ENVIRONMENT=test      # test (domyślnie) | demo | production
KSEF_AUTH=token            # token | certificate
KSEF_CONTEXT_TYPE=nip
KSEF_CONTEXT_NIP=<NIP-podatnika-TEST>
KSEF_TOKEN=<token-KSeF-TEST>
```

**Pierwsze wywołanie:**

```bash
php artisan ksef:check
```

Komenda wypisuje środowisko, klucze publiczne, wynik logowania i limity (bez sekretów). W kodzie wstrzyknij `KsefClient`:

```php
public function __construct(private readonly KsefClient $ksef) {}
// ...
$limits = $this->ksef->limits()->context();
```

Gotowa aplikacja: `examples/laravel` (instrukcja w [Laravel](laravel.md)). Jej test live sam tworzy syntetycznego podatnika; zwykłe uruchomienie wymaga tokenu TEST w `.env`.

## NestJS

Wymagania: Node >= 22, NestJS `^11` (Nest 10 nie był weryfikowany), pakiet jest ESM.

**Instalacja we własnym projekcie** (z npmjs.com):

```bash
npm install @luroweb/nestjs-ksef-foundry @luroweb/ksef-foundry @nestjs/common@^11 @nestjs/core@^11 reflect-metadata rxjs
```

**Instalacja z klona** (dla współtwórców; uruchomienie przykładowej aplikacji):

```bash
cd ksef-foundry
npm install
npm run build -w @luroweb/ksef-foundry
npm run build -w @luroweb/nestjs-ksef-foundry
cp examples/nestjs/.env.example examples/nestjs/.env   # uzupełnij wartości
npm run start -w ksef-foundry-example-nestjs
```

**Konfiguracja i wstrzyknięcie:**

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

Szczegóły: [NestJS](nestjs.md).

## Opóźnienie po `/testdata/person`

Na TEST utworzenie osoby przez `/testdata/person` działa z opóźnieniem kilku sekund. Logowanie tuż po nim może zakończyć się statusem uwierzytelnienia **415 „Brak przypisanych uprawnień"**; to samo żądanie kilka sekund później przechodzi. Przykłady ponawiają próbę (do 8 razy, co 2 s). Szczegóły: [Uwierzytelnianie](uwierzytelnianie.md#status-415-tuż-po-testdataperson).

## Co dalej

- [Faktura FA(3)](faktura-fa3.md): budowanie i walidacja własnej faktury.
- [Cykl życia faktury](cykl-zycia-faktury.md): co dzieje się po `submit()`.
- [Środowiska](srodowiska.md): zasady korzystania ze wspólnego TEST.

---

Built and maintained by LuroWeb.
