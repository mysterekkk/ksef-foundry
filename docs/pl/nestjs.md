# NestJS

Pakiet `@luroweb/nestjs-ksef-foundry` integruje SDK TypeScript (`@luroweb/ksef-foundry`) z NestJS: jeden długo żyjący `KsefClient` na rejestrację (nigdy per żądanie), typowany `KsefService`, rejestr wielu klientów, filtr wyjątków i opcjonalny health check. Wersja 0.1.0.

## Spis treści

- [Instalacja i wymagania](#instalacja-i-wymagania)
- [Rejestracja modułu](#rejestracja-modułu)
- [Wstrzykiwanie](#wstrzykiwanie)
- [KsefService](#ksefservice)
- [Wielu klientów (multi-tenant)](#wielu-klientów-multi-tenant)
- [Filtr wyjątków](#filtr-wyjątków)
- [Cykl życia i zamykanie](#cykl-życia-i-zamykanie)
- [Health check](#health-check)
- [Aplikacja przykładowa](#aplikacja-przykładowa)
- [Zakres testów i ograniczenia](#zakres-testów-i-ograniczenia)

## Instalacja i wymagania

Pakiet nie jest jeszcze opublikowany w npm (jest przygotowany do publikacji). Po publikacji:

```bash
npm i @luroweb/nestjs-ksef-foundry @luroweb/ksef-foundry @nestjs/common @nestjs/core reflect-metadata rxjs
```

Do tego czasu zbuduj i spakuj oba pakiety z klona monorepo (`npm pack -w @luroweb/ksef-foundry`, `npm pack -w @luroweb/nestjs-ksef-foundry`) i zainstaluj tarballe ([Szybki start](szybki-start.md#nestjs)).

Wymagania: Node >= 22; peer dependencies: `@nestjs/common` i `@nestjs/core` `^11`, `rxjs ^7.1`, `reflect-metadata`, `@luroweb/ksef-foundry >=0.1.0 <1.0.0`. Pakiet jest **tylko ESM**, jak rdzeń SDK. Uruchomiono wyłącznie NestJS 11; Nest 10 nie był weryfikowany (dlatego nie jest deklarowany), a Nest 12 (tylko ESM) nie był testowany.

## Rejestracja modułu

```ts
import { Context, KsefTokenCredentials } from '@luroweb/ksef-foundry';
import { KsefModule } from '@luroweb/nestjs-ksef-foundry';

@Module({
  imports: [
    KsefModule.forRoot({
      isGlobal: true,
      credentials: new KsefTokenCredentials(process.env.KSEF_TOKEN!, Context.nip(process.env.KSEF_CONTEXT_NIP!)),
      logger: 'nest', // zdarzenia SDK (już zredagowane) do Nest Logger "Ksef"
    }),
  ],
})
export class AppModule {}
```

Wariant asynchroniczny (`useFactory`, `useClass` z metodą `createKsefOptions()` lub `useExisting`):

```ts
KsefModule.forRootAsync({
  imports: [ConfigModule],
  inject: [ConfigService],
  useFactory: (c: ConfigService): KsefModuleOptions => ({
    credentials: new KsefTokenCredentials(c.getOrThrow('KSEF_TOKEN'), Context.nip(c.getOrThrow('KSEF_NIP'))),
  }),
});
```

`name` i `isGlobal` podajesz obok `useFactory`, ponieważ muszą być znane, zanim uruchomi się fabryka.

### Opcje (`KsefModuleOptions`)

| Opcja | Znaczenie |
|---|---|
| `environment` | domyślnie `Environment.TEST`; PRODUCTION tylko jawnie |
| `credentials` | `KsefTokenCredentials` lub `CertificateCredentials` |
| `storage` | własny `TokenStorage` ([Uwierzytelnianie](uwierzytelnianie.md#tokenstorage)) |
| `fetch`, `timeoutMs`, `deadlineMs`, `retry`, `expirySkewSeconds`, `allowedHosts` | przekazywane do `KsefClient.create` |
| `logger` | `'nest'` lub obiekt `Logger` SDK; domyślnie brak logowania |
| `logoutOnShutdown` | domyślnie `false`; unieważnia sesję uwierzytelnienia przy zamykaniu |
| `shutdownLogoutTimeoutMs` | 5000 ms |
| `invoiceValidator` | własny walidator przed `submitInvoice` (domyślnie dołączony XSD FA(3)) |

Przykładowa fabryka konfiguracji (`examples/nestjs/src/ksef.config.ts`) wybiera token, a gdy go brak, certyfikat XAdES z PEM-ów w zmiennych środowiskowych (znaki nowej linii jako `\n`).

## Wstrzykiwanie

| Co | Jak |
|---|---|
| `KsefClient` | `@InjectKsef()` lub `@Inject(KSEF_CLIENT)` |
| `KsefService` (domyślny) | `constructor(private ksef: KsefService)` lub `@Inject(KsefService)` |
| klient / serwis nazwany | `@InjectKsef('tenant-a')`, `@InjectKsefService('tenant-a')` |
| rejestr | `KsefClientRegistry` (`get(name)`, `service(name)`, `register(name, options)`, `has`, `names`) |
| health | `KsefHealthIndicator` (rejestracja domyślna) |

Pakiet **nie używa `emitDecoratorMetadata`**: wstrzykiwanie zawsze przez jawny token, więc przy braku metadanych pisz `@Inject(KsefService)`. Dzięki temu działa także w narzędziach bez emisji metadanych.

## KsefService

Cienka, typowana nakładka na jednego klienta. Pola: `client`, `name`, `shutdownSignal`, `tokens`, `limits`, `sessions`, `authSessions`. Metody:

| Metoda | Działanie |
|---|---|
| `validateInvoice(xml)` | walidacja FA(3) offline; zwraca `ValidationResult`, nie rzuca dla nieprawidłowego XML |
| `submitInvoice(xml, opts?)` | otwarcie sesji, wysyłka, oczekiwanie na status 200, zamknięcie; **nigdy nie wysyła ponownie**, niejednoznaczny wynik to `UnknownOutcomeException` |
| `queryInvoices(filters, opts?)` | jedna strona metadanych |
| `iterateInvoices(filters, opts?)` | leniwa iteracja po wszystkich stronach |
| `downloadInvoice(ksefNumber)` | pobranie faktury |
| `getInvoiceStatus(sessionRef, invoiceRef)` | status faktury w sesji |
| `getUpo({ sessionReferenceNumber, invoiceReferenceNumber \| ksefNumber \| upoReferenceNumber })` | UPO |

Metody sieciowe łączą Twój `AbortSignal` z sygnałem zamknięcia aplikacji.

```ts
const page = await this.ksef.queryInvoices({
  subjectType: 'Subject1',
  dateRange: { dateType: 'Issue', from: new Date(Date.now() - 30 * 86_400_000) },
});
const result = await this.ksef.submitInvoice(xml);   // wraca po statusie 200
```

## Wielu klientów (multi-tenant)

Zarejestruj po jednym kliencie na tenanta albo twórz je w czasie działania:

```ts
KsefModule.forRoot({ name: 'a', credentials: credsA });
KsefModule.forRoot({ name: 'b', credentials: credsB });

registry.register(tenantId, { credentials, storage });   // zamykany razem z aplikacją
const client = registry.get(tenantId);
```

Klienci **nie współdzielą tokenów**: SDK kluczuje cache JWT środowiskiem, kontekstem, metodą i odciskiem poświadczeń. `KsefModule.forFeature(['a'])` re-eksportuje zarejestrowane już klienty nazwane do modułu funkcjonalnego. Zarejestrowanie tej samej nazwy dwa razy zgłasza `ConfigurationException`.

## Filtr wyjątków

Włącz: `@UseFilters(KsefExceptionFilter)`, `app.useGlobalFilters(new KsefExceptionFilter())` lub `{ provide: APP_FILTER, useClass: KsefExceptionFilter }`. Treść odpowiedzi: `{ statusCode, error, code, message, ksef?, reconcile?, validationErrors?, detail? }`, budowana wyłącznie z dozwolonych pól `diagnostics()`: bez tokenów, surowego ciała z KSeF i śladu stosu.

| Wyjątek SDK | HTTP | `code` |
|---|---|---|
| `RateLimitException` | 429 + `Retry-After` | `KSEF_RATE_LIMITED` |
| `AuthorizationException` | 403 (`reasonCode`, wymagane uprawnienia) | `KSEF_FORBIDDEN` |
| `AuthenticationException` | 502 | `KSEF_AUTHENTICATION_FAILED` |
| `ValidationException` (400 z KSeF) | 400 | `KSEF_VALIDATION_FAILED` |
| `XmlValidationException` | 422 + `validationErrors` | `KSEF_XML_INVALID` |
| `InvoiceBuilderException` | 422 + `detail` | `KSEF_INVOICE_MODEL_INVALID` |
| `InvoiceRejectedException` | 422 | `KSEF_INVOICE_REJECTED` |
| `UnknownOutcomeException` | 409 + wskazówka `reconcile` | `KSEF_UNKNOWN_OUTCOME` |
| `TimeoutException` | 504 | `KSEF_TIMEOUT` |
| `NetworkException` | 502 | `KSEF_NETWORK_ERROR` |
| `NotFoundException` | 404 | `KSEF_NOT_FOUND` |
| `GoneException` | 410 | `KSEF_GONE` |
| `SessionException` | 409; 503 dla kodu KSeF 21184 | `KSEF_SESSION_ERROR` / `KSEF_SESSION_UNAVAILABLE` |
| `AbortException` | 503 | `KSEF_ABORTED` |
| inny `ApiException` (5xx itd.) | 502 | `KSEF_UPSTREAM_ERROR` |
| `ConfigurationException`, `CertificateException`, `EncryptionException` | 500, ogólny komunikat | `KSEF_INTERNAL_ERROR` |

`AuthenticationException` to 502, nie 401: poświadczenia, które zawiodły, należą do **Twojego serwera**; 401 sugerowałby klientowi Twojego API, że ma zalogować się do Twojej usługi. `UnknownOutcomeException` oznacza, że wysyłka mogła dotrzeć do KSeF: wylistuj faktury sesji i porównaj `invoiceHash`, zanim wyślesz ponownie ([Cykl życia](cykl-zycia-faktury.md)). Filtr korzysta z `HttpAdapterHost` Nest; w testach przykładu użyto Expressa.

## Cykl życia i zamykanie

Przy zniszczeniu modułu (`app.close()` lub sygnał z `app.enableShutdownHooks()`): opcjonalny `logout` (tylko z `logoutOnShutdown: true`), potem przerwanie wspólnego `AbortController` wszystkich żądań tego klienta. Wywołania `KsefService` i bezpośrednie `client.*` kończą się `AbortException`; przerwanie nie jest ponawiane i nic nie jest wysyłane po zamknięciu.

`NestFactory.create` domyślnie wywołuje `process.abort()` przy błędach inicjalizacji; przykładowa aplikacja ustawia `abortOnError: false`, żeby błąd konfiguracji był zwykłym wyjątkiem.

## Health check

```ts
new KsefHealthIndicator(service, { mode: 'public-key' /* lub 'rate-limits' */, timeoutMs: 5000 }).check();
// { ksef: { status: 'up' | 'down', environment, message? } }
```

Kształt jest zgodny z `@nestjs/terminus` (terminus nie jest wymagany). Tryb `public-key` (domyślny) to niecachowane, nieuwierzytelnione `GET /security/public-key-certificates`: sprawdza osiągalność i TLS. Tryb `rate-limits` jest uwierzytelniony, więc dodatkowo dowodzi poprawności poświadczeń. W stanie `down` komunikat zawiera tylko nazwę klasy wyjątku, bez adresów i sekretów.

## Aplikacja przykładowa

`examples/nestjs` działa wyłącznie na TEST (`KSEF_ENVIRONMENT` musi być `test`, inna wartość zatrzymuje aplikację przy starcie).

```bash
npm install
npm run build -w @luroweb/ksef-foundry
npm run build -w @luroweb/nestjs-ksef-foundry
cp examples/nestjs/.env.example examples/nestjs/.env   # uzupełnij wartości
npm run start -w ksef-foundry-example-nestjs           # http://127.0.0.1:3000
```

Użyj syntetycznego NIP TEST i prawdziwego tokenu KSeF z TEST albo zostaw `KSEF_TOKEN` puste i podaj `KSEF_CERTIFICATE_PEM` / `KSEF_PRIVATE_KEY_PEM`. Główne endpointy: `GET /invoices` (metadane, zakres do 100 dni), `GET /invoices/:ksefNumber`, `POST /invoices/validate`, `POST /invoices/build` (model JSON do XML), `POST /invoices` (XML lub model JSON; walidacja, sesja, wysyłka, status 200), `GET /invoices/status/:sessionRef/:invoiceRef`, trasy UPO, `GET /limits`, `GET /health`, `GET /demo/forbidden` i `GET /demo/rate-limit` (demonstracje filtra). `FileTokenStorage` (`KSEF_TOKEN_CACHE_FILE`) to przykład własnego `TokenStorage`: plik z uprawnieniami 0600, tylko para JWT; w produkcji użyj szyfrowanego magazynu. Treść faktur nie jest logowana.

## Zakres testów i ograniczenia

Testy używają prawdziwego DI Nest, prawdziwego HTTP i prawdziwej kryptografii; lokalny serwer (`test/support/auth-server.ts`) realizuje protokół logowania tokenem KSeF z prawdziwym odszyfrowaniem RSA-OAEP-SHA256, a błędy (429, 403, 400, 401, 500, zawieszenie, reset) wstrzykuje po prawdziwym logowaniu. Jest to **fault-injection, a nie dowód zgodności z API**. Test live (`KSEF_LIVE_TEST=1`) uruchamia aplikację przykładową z poświadczeniami XAdES syntetycznego podatnika na TEST. Szczegóły: [Testowanie](testowanie.md).

Ograniczenia: tylko Nest 11 (patrz wyżej), brak weryfikacji na DEMO i PRODUCTION. `npm install` może ostrzegać o skrypcie instalacyjnym `esbuild` (zależność przechodnia `vitest` i `tsx`); nic w pakiecie go nie wymaga.

---

Built and maintained by LuroWeb.
