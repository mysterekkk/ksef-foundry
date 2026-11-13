# Laravel

Adapter `luroweb/laravel-ksef-foundry` integruje rdzeń PHP (`luroweb/ksef-foundry`) z Laravel 12: provider z auto-discovery, konfiguracja z `.env`, cache tokenów, joby kolejki i komenda diagnostyczna. Wersja 0.1.0 (pre-release).

## Spis treści

- [Instalacja](#instalacja)
- [Konfiguracja](#konfiguracja)
- [Wstrzykiwanie zależności i fasada](#wstrzykiwanie-zależności-i-fasada)
- [Cache tokenów i blokada logowania](#cache-tokenów-i-blokada-logowania)
- [Joby kolejki](#joby-kolejki)
- [Błędy HTTP](#błędy-http)
- [Komenda ksef:check](#komenda-ksefcheck)
- [Wielu podatników](#wielu-podatników)
- [Aplikacja przykładowa](#aplikacja-przykładowa)
- [Zakres testów](#zakres-testów)

## Instalacja

Pakiet nie jest jeszcze opublikowany w Packagist (jest przygotowany do publikacji). Po publikacji wystarczy `composer require luroweb/laravel-ksef-foundry`. Do tego czasu użyj repozytoriów `path` do klona monorepo ([Szybki start](szybki-start.md#laravel)).

```bash
php artisan vendor:publish --tag=ksef-config   # opcjonalnie: config/ksef.php
```

Provider (`LuroWeb\KsefFoundry\Laravel\KsefServiceProvider`) i alias fasady `Ksef` są wykrywane automatycznie.

## Konfiguracja

Wszystkie ustawienia pochodzą z `.env`; sekrety nie powinny trafiać do `config/ksef.php`.

```dotenv
KSEF_ENVIRONMENT=test            # test (domyślnie) | demo | production | custom
KSEF_AUTH=token                  # token | certificate
KSEF_CONTEXT_TYPE=nip            # nip | internal_id | nip_vat_ue
KSEF_CONTEXT_NIP=<NIP>
KSEF_TOKEN=<token-KSeF>          # albo certyfikat:
# KSEF_CERTIFICATE_PATH=... KSEF_PRIVATE_KEY_PATH=... KSEF_PRIVATE_KEY_PASSPHRASE=...
KSEF_CACHE_STORE=redis           # wspólny magazyn, nie "array"
KSEF_LOG_CHANNEL=stack           # puste = brak logów
```

| Zmienna | Domyślnie | Znaczenie |
|---|---|---|
| `KSEF_CONNECTION` | `default` | nazwa połączenia domyślnego |
| `KSEF_ENVIRONMENT` | `test` | środowisko; `custom` wymaga `base_url` i służy tylko serwerom testowym |
| `KSEF_HTTP_DRIVER` | `curl` | `curl` (wbudowany) lub `guzzle` (zahartowany wrapper PSR-18) |
| `KSEF_HTTP_TIMEOUT` / `_CONNECT_TIMEOUT` / `KSEF_HTTP_DEADLINE` | 30 / 10 / 120 s | limity czasu |
| `KSEF_RETRY_ATTEMPTS`, `_BASE_DELAY`, `_MAX_DELAY`, `KSEF_RETRY_MAX_AFTER` | 4, 0,25 s, 8 s, 60 s | polityka ponawiania ([Obsługa błędów](obsluga-bledow.md)) |
| `KSEF_CACHE_STORE`, `KSEF_CACHE_PREFIX` | domyślny store, `ksef` | magazyn tokenów |
| `KSEF_CACHE_ENCRYPT` | `true` | szyfrowanie pary JWT kluczem `APP_KEY` |
| `KSEF_CACHE_LOCK`, `KSEF_LOCK_SECONDS`, `KSEF_LOCK_WAIT` | `true`, 150, 130 | blokada logowania |
| `KSEF_QUEUE`, `KSEF_QUEUE_CONNECTION` | puste | kolejka i połączenie jobów |
| `KSEF_JOB_AWAIT` | 20 s | jak długo `SubmitInvoiceJob` czeka na status, zanim przekaże sprawę `PollInvoiceStatusJob` |
| `KSEF_JOB_VALIDATE` | `true` | walidacja XSD w jobie |
| `KSEF_JOB_RECONCILE_DELAY`, `KSEF_JOB_UNIQUE_FOR` | 3 s, 3600 s | pauza przed uzgodnieniem; czas życia znacznika i unikalności |
| `KSEF_FETCH_LOOKBACK_DAYS` | 30 | start `FetchInvoicesJob` bez kursora |

Klucze połączenia (`connections.*`): `auth`, `context_type`, `context_value`, `token`, `certificate_path` / `certificate_pem`, `private_key_path` / `private_key_pem`, `private_key_passphrase`, `subject_identifier`, a także nadpisania: `environment`, `base_url`, `cache_store`, `cache_prefix`, `log_channel`, `http_driver`, `timeout`.

Uwagi:

- `KsefClient` jest tworzony **leniwie** przy pierwszym użyciu. Brak tokenu nie psuje `artisan about` ani `route:list`; błąd (`ConfigurationException`) pojawia się dopiero przy użyciu.
- `mergeConfigFrom` jest płytkie: jeśli własny plik konfiguracji definiuje `connections`, zastępuje te z pakietu w całości.
- Wartość `0000000000` w `KSEF_CONTEXT_NIP` z przykładowego `.env.example` jest odrzucana, dopóki jej nie zastąpisz.

## Wstrzykiwanie zależności i fasada

Preferowane jest wstrzyknięcie `KsefClient` (połączenie domyślne) lub `KsefManager` (połączenia nazwane i tenanci):

```php
use LuroWeb\KsefFoundry\KsefClient;
use LuroWeb\KsefFoundry\Laravel\KsefManager;

final class InvoiceController
{
    public function __construct(private KsefClient $ksef, private KsefManager $ksefs) {}

    public function limits() { return $this->ksef->limits()->context(); }
}

$ksefs->connection('shop-2')->query();   // połączenie nazwane z config('ksef.connections')
```

Fasada `LuroWeb\KsefFoundry\Laravel\Facades\Ksef` (`Ksef::limits()` itd.) przekierowuje do połączenia **domyślnego**; `Ksef::manager()` zwraca `KsefManager`. Preferuj wstrzyknięcie: jest testowalne i jawne co do podatnika.

## Cache tokenów i blokada logowania

`CacheTokenStorage` przechowuje **wyłącznie parę JWT** (nigdy token KSeF ani klucz prywatny) w cache Laravela, zaszyfrowaną `Encrypter`em. Zmiana `APP_KEY` unieważnia stare wpisy (traktowane jako brak, następuje nowe logowanie). TTL to krótszy z czasów: SDK i ważności refresh tokenu. Klucz to tenant key SDK pod prefiksem `ksef:jwt:`.

Logowanie i odświeżanie odbywa się w `Cache::lock('<prefiks>:login:<tenantKey>')`, jeśli magazyn obsługuje `LockProvider`. Dzięki temu równoległe workery dzielą jedno logowanie; worker, który nie zdobędzie blokady w `KSEF_LOCK_WAIT` sekund, kończy się `KsefException`.

Używaj magazynu współdzielonego przez procesy web i kolejki (Redis, baza, plik). Magazyn `array` jest per proces, a `null` nic nie przechowuje.

## Joby kolejki

```php
use LuroWeb\KsefFoundry\Laravel\Jobs\{SubmitInvoiceJob, PollInvoiceStatusJob, FetchInvoicesJob};

SubmitInvoiceJob::dispatch($fa3Xml, 'default');          // nazwa połączenia, nigdy poświadczenia
FetchInvoicesJob::dispatch('default', 'Subject2');       // np. w schedulerze co 15 minut
Event::listen(InvoiceAccepted::class, fn (InvoiceAccepted $e) => logger()->info('KSeF', ['ksefNumber' => $e->ksefNumber]));
```

**`SubmitInvoiceJob`** wysyła fakturę bez ryzyka duplikatu:

- jest unikalny dla pary (połączenie, skrót faktury): ten sam XML nie trafi dwa razy naraz do kolejki,
- **przed wysyłką** zapisuje w cache znacznik z numerem sesji (`ksef:submit:<sha256(połączenie|skrót)>`, TTL = `unique_for`),
- po `UnknownOutcomeException`, awarii procesu lub dowolnym błędzie po wysyłce następny krok to **`reconcile`**; ponowna wysyłka następuje tylko wtedy, gdy KSeF nie zna takiej faktury (znacznik z fakturą: tylko oczekiwanie na status; bez faktury: reconcile; brak znacznika: nowa sesja i wysyłka),
- 429: `release(Retry-After)`, znacznik zostaje (żądanie nie zostało przetworzone),
- jednoznaczny błąd 4xx lub odrzucenie (>= 400): zadanie kończy się niepowodzeniem **bez ponawiania** i emituje `InvoiceRejected`,
- zbyt wolne przetwarzanie: sprawę przejmuje `PollInvoiceStatusJob`.

Domyślnie `tries = 6`, opóźnienia 30, 60, 120, 300, 600 s. Ładunek zadania zawiera **XML faktury** (patrz [Bezpieczeństwo](bezpieczenstwo.md)). Odrzucone faktury trafiają do `failed_jobs` tylko pod prawdziwym workerem; przy `dispatchSync` metoda `fail()` jest pusta.

**`PollInvoiceStatusJob`** odpytuje status ze wzrastającym opóźnieniem, aż do statusu końcowego (maks. 7200 s); emituje `InvoiceAccepted` lub `InvoiceRejected`. Jest tylko do odczytu, więc ponowienia są bezpieczne.

**`FetchInvoicesJob`** pobiera metadane przyrostowo (`PermanentStorage`, rosnąco, z `restrictToPermanentStorageHwmDate`). Kursor (największa `permanentStorageDate`) przechowuje **Twój** `InvoiceSink`. Pakiet nic nie zapisuje; domyślny `NullInvoiceSink` odrzuca wszystko. Zaimplementuj interfejs `LuroWeb\KsefFoundry\Laravel\Contracts\InvoiceSink` (`cursor`, `accept`, `saveCursor`) i zwiąż go w kontenerze. `accept()` musi być idempotentny po numerze KSeF, bo faktura na granicy kursora może zostać dostarczona dwukrotnie.

Zdarzenia: `InvoiceAccepted` (połączenie, skrót, numer KSeF, referencje faktury i sesji) oraz `InvoiceRejected` (połączenie, skrót, kod statusu, opis, szczegóły, referencje).

## Błędy HTTP

```php
// bootstrap/app.php
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->render(fn (KsefException $e) => KsefExceptionRenderer::render($e));
})
```

`LuroWeb\KsefFoundry\Laravel\Exceptions\KsefExceptionRenderer` zwraca bezpieczny JSON `{"error": {"type", "message", ...}}` i mapuje:

| Wyjątek | HTTP |
|---|---|
| `RateLimitException` | 429 + `Retry-After` |
| `AuthorizationException` | 403 |
| `AuthenticationException` | 502 (zawiodły poświadczenia Twojego serwera, nie wywołującego) |
| `InvoiceRejectedException`, `XmlValidationException` | 422 |
| `UnknownOutcomeException` | 409 (skrót i numer sesji w treści) |
| `NetworkException` | 504 |
| `ConfigurationException` | 500 |
| pozostałe `ApiException` | 502 |

## Komenda ksef:check

```bash
php artisan ksef:check [połączenie] [--no-auth]
```

Wypisuje połączenie, środowisko i adres (z ostrzeżeniem dla PRODUCTION), zamaskowany kontekst, metodę uwierzytelniania, klucze publiczne (`KsefTokenEncryption`, `SymmetricKeyEncryption`), a bez `--no-auth` także wynik logowania i limity sesji online. Nie drukuje sekretów. Zwraca kod błędu przy niepowodzeniu.

## Wielu podatników

Dane podatników trzymane w Twojej bazie podajesz w czasie działania; każdy dostaje osobnego klienta i osobne wpisy w cache:

```php
$client = $ksefs->tenant(['auth' => 'token', 'context_value' => $tenant->nip, 'token' => $tenant->ksef_token]);

// nazwy połączeń spoza konfiguracji (np. id tenanta), także dla jobów:
$ksefs->resolveUsing(fn (string $name) => Tenant::find($name)?->ksefConfig()); // tablica jak w config lub null
```

Joby niosą tylko **nazwę** połączenia, więc tenanci z bazy działają przez `resolveUsing`. `KsefManager` zapamiętuje do 32 klientów tenantów. `forget($name)` i `purge()` zwalniają klientów (np. po rotacji tokenu).

## Aplikacja przykładowa

`examples/laravel` to minimalna aplikacja Laravel 12 (repozytoria `path` do monorepo), domyślnie tylko TEST.

```bash
cd examples/laravel
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate    # tabele kolejki
# uzupełnij KSEF_CONTEXT_NIP i KSEF_TOKEN (token ze środowiska TEST)
php artisan ksef:check && php artisan serve
php artisan queue:work                                    # w drugim terminalu
```

Trasy: `GET /ksef/limits`, `GET /ksef/invoices?subject=Subject2&page=1&per_page=20&days=30`, `GET /ksef/invoices/{ksefNumber}`, `POST /ksef/validate` (surowy XML), `POST /ksef/invoices` (waliduje i kolejkuje `SubmitInvoiceJob`, 202), `GET /ksef/status/{sesja}/{faktura}`, `GET /ksef/upo/{sesja}/{faktura}`. `php artisan ksef:send-sample` kolejkuje syntetyczną fakturę próbną (tylko TEST). Użyj `CACHE_STORE=file` lub Redis, aby web i worker dzieliły JWT i blokadę.

## Zakres testów

Testy adaptera: 60 testów / 343 asercje na Laravel 12.72.0 (Testbench 10.12), PHP 8.4.20, zielone (jednostkowe, funkcjonalne, fault-injection). **Nie uruchamiano:** `--prefer-lowest`, PHP 8.2 i 8.3, Laravel starszego niż 11.57, Octane, szczegółów Horizon. Test live (opt-in) tworzy syntetycznego podatnika na TEST. DEMO i PRODUCTION nie były używane. Szczegóły: [Testowanie](testowanie.md).

---

Built and maintained by LuroWeb.
