# Obsługa błędów

SDK zgłasza wyjątki z jednej hierarchii o **tych samych nazwach** w PHP i TypeScript. Ten przewodnik jest referencją: co oznacza każdy wyjątek, co jest ponawiane automatycznie, a czego nie wolno ponawiać nigdy.

## Spis treści

- [Hierarchia wyjątków](#hierarchia-wyjątków)
- [Pola wyjątków API](#pola-wyjątków-api)
- [Macierz ponawiania](#macierz-ponawiania)
- [429 i Retry-After](#429-i-retry-after)
- [403, reasonCode i uprawnienia](#403-reasoncode-i-uprawnienia)
- [Znaczące kody KSeF](#znaczące-kody-ksef)
- [Przykład obsługi](#przykład-obsługi)

## Hierarchia wyjątków

PHP: przestrzeń nazw `LuroWeb\KsefFoundry\Exception\…`. TypeScript: eksporty `@luroweb/ksef-foundry`.

```text
KsefException
 ├─ ConfigurationException
 ├─ NetworkException
 │   └─ TimeoutException
 ├─ AbortException                (tylko TypeScript)
 ├─ ApiException
 │   ├─ ValidationException        400
 │   ├─ AuthenticationException    401 i niepowodzenia statusu uwierzytelnienia
 │   ├─ AuthorizationException     403 i status uwierzytelnienia 415
 │   ├─ NotFoundException          404
 │   ├─ GoneException              410
 │   ├─ RateLimitException         429
 │   └─ ServerException            5xx
 ├─ SessionException
 ├─ InvoiceRejectedException
 ├─ UnknownOutcomeException
 ├─ EncryptionException
 ├─ CertificateException
 └─ XmlValidationException
     └─ InvoiceValidationException (FA(3): lista problemów)
InvoiceBuilderException             (FA(3) builder; patrz uwaga)
```

| Wyjątek | Znaczenie | Typowa reakcja |
|---|---|---|
| `ConfigurationException` | zła konfiguracja SDK (środowisko, adres, brak poświadczeń, zakres dat > 100 dni) | popraw konfigurację; nie ponawiaj |
| `NetworkException` | błąd połączenia, DNS, TLS, reset | ponawiane automatycznie tylko dla żądań idempotentnych |
| `TimeoutException` | przekroczony limit czasu żądania lub łączny termin; także zbyt długie oczekiwanie na status | dla wysyłki: **uzgodnij**, nie wysyłaj ponownie |
| `AbortException` (TS) | przerwanie przez `AbortSignal` | nigdy nie ponawiane |
| `ValidationException` | HTTP 400, treść w `errors[]` | popraw dane; nie ponawiaj |
| `AuthenticationException` | HTTP 401 lub status uwierzytelnienia inny niż 415 (np. 425, 450, 460) | sprawdź poświadczenia |
| `AuthorizationException` | HTTP 403 lub status uwierzytelnienia 415 (brak uprawnień) | sprawdź uprawnienia kontekstu |
| `NotFoundException`, `GoneException` | 404, 410 | zasób nie istnieje lub wygasł |
| `RateLimitException` | 429; żądanie **nie zostało przetworzone** | odczekaj `retryAfterSeconds` |
| `ServerException` | 5xx | 502, 503, 504 ponawiane dla idempotentnych |
| `SessionException` | stan sesji (wygasła, kod 21184 „chwilowo niedostępna") | otwórz nową sesję |
| `InvoiceRejectedException` | końcowy status faktury >= 400 (np. 440 duplikat) | popraw fakturę; nie ponawiaj |
| `UnknownOutcomeException` | niejednoznaczny wynik wysyłki | **uzgodnij** ([Cykl życia](cykl-zycia-faktury.md)) |
| `EncryptionException`, `CertificateException` | błąd kryptografii; zły, wygasły certyfikat, klucz lub hasło | popraw materiał kluczowy |
| `XmlValidationException` / `InvoiceValidationException` | błędy XSD i reguł technicznych (linia, kolumna, komunikat) | popraw XML |

**Uwaga o `InvoiceBuilderException`:** w PHP (`LuroWeb\KsefFoundry\Invoice`) dziedziczy po `\InvalidArgumentException`, a **nie** po `KsefException`; w TypeScript dziedziczy po `KsefException`. Łapiąc `KsefException` w PHP, nie złapiesz błędów modelu faktury.

## Pola wyjątków API

Każdy `ApiException` udostępnia: kategorię, kod HTTP (`statusCode`), pierwszy kod KSeF (`ksefCode`) i wszystkie (`ksefCodes`), `details`, `traceId`, `retryable`, `retryAfterSeconds`, `authStatusCode` oraz `diagnostics()` (struktura bez sekretów). W PHP są to metody (`$e->statusCode()`), w TypeScript pola i gettery (`e.statusCode`, `e.retryable`).

SDK rozpoznaje zarówno `application/problem+json`, jak i starszy kształt `ExceptionResponse`. Do logów używaj `diagnostics()`, nie surowego ciała odpowiedzi.

## Macierz ponawiania

Domyślnie: do 4 prób łącznie, wykładniczy backoff z jitterem (PHP: od 0,25 s do 8 s; TypeScript: od 400 ms do 10 s), `Retry-After` ma pierwszeństwo.

| Sytuacja | Żądanie idempotentne (GET, zapytania) | Żądanie nieidempotentne (wysyłka, otwarcie i zamknięcie sesji, `redeem`) |
|---|---|---|
| Błąd sieci, reset, timeout | ponawiane | **nigdy** po niejednoznacznym błędzie (dla wysyłki: `UnknownOutcomeException`) |
| 429 | ponawiane po `Retry-After` | ponawiane po `Retry-After` (żądanie nie zostało przetworzone) |
| 502, 503, 504 | ponawiane | **nigdy** |
| 500 | nie ponawiane | nie ponawiane |
| 400, 401, 403, 404, 410 | nie ponawiane | nie ponawiane |
| Przerwanie (`AbortSignal`) | nie ponawiane | nie ponawiane |

Wyjątki od reguł ogólnych:

- **Pobranie faktury** ponawia kod **21165** oraz gołe 406 do 15 s ([Cykl życia](cykl-zycia-faktury.md#pobieranie-faktury-i-błąd-21165)).
- **Logowanie tokenem**: kod 21470 (nieznany klucz publiczny) powoduje jednokrotne odświeżenie kluczy i jedną ponowną próbę.
- Zapytanie `POST /invoices/query/metadata` jest oznaczone jako idempotentne (odczyt).
- Różnica: gdy `Retry-After` jest większe niż `maxRetryAfterSeconds` (domyślnie 60), **PHP skraca czekanie do tej wartości**, a **TypeScript nie czeka i zgłasza 429**.

Wyłączenie ponawiania: PHP `TransportOptions(retry: RetryPolicy::none())` przekazane przez `KsefClient::builder()->transportOptions(...)`; TypeScript `KsefClient.create({ ..., retry: { maxAttempts: 1 } })`. W Laravelu klucze `ksef.retry.*` ([Laravel](laravel.md)).

Zachowania 429, 500 i zerwań połączenia sprawdzono na lokalnym serwerze HTTP (fault-injection); **prawdziwy 429 z `Retry-After` nie został zaobserwowany na żywo**, bo TEST nie zwraca go na żądanie.

## 429 i Retry-After

`RateLimitException` niesie `retryAfterSeconds` (nagłówek w sekundach lub dacie HTTP). SDK samo ponawia w ramach limitu prób. Po jego wyczerpaniu wyjątek trafia do aplikacji: odczekaj wskazany czas (najlepiej w kolejce, jak `SubmitInvoiceJob`, który zwalnia zadanie z opóźnieniem `Retry-After`) i ogranicz tempo. W adapterach: Laravel zwraca 429 z `Retry-After`; NestJS także.

## 403, reasonCode i uprawnienia

`AuthorizationException` zawiera `reasonCode` (np. `missing-permissions`) i sekcję `security` z uprawnieniami wymaganymi (`requiredAnyOfPermissions`) oraz posiadanymi (`presentPermissions`); są one dostępne w `diagnostics()`. Różnicuj 400, 401, 403 i 429: każdy wymaga innej reakcji. Status uwierzytelnienia **415** po świeżo utworzonym podatniku TEST to opóźnienie propagacji uprawnień, a nie błąd konfiguracji ([Uwierzytelnianie](uwierzytelnianie.md#status-415-tuż-po-testdataperson)).

## Znaczące kody KSeF

| Kod | Kontekst | Obsługa w SDK |
|---|---|---|
| 440 | duplikat faktury; pusta sesja zamknięta („Sesja anulowana") | `InvoiceRejectedException` ze `statusExtensions` (`originalKsefNumber`, `originalSessionReferenceNumber`) |
| 21165 | faktura przetworzona, jeszcze niedostępna do pobrania | ponawianie do 15 s |
| 21184 | sesja chwilowo niedostępna | `SessionException`; otwórz nową sesję |
| 21470 | nieznany klucz publiczny (rotacja) | jednokrotne odświeżenie kluczy i ponowna próba |
| 9101, 9102, 9105 | błędy podpisu XAdES (zły dokument, brak podpisu, zły podpis lub skrót) | `ValidationException` (HTTP 400) |

Kody 21470 i 21184 nie zostały zaobserwowane na żywo (tylko lokalny serwer).

## Przykład obsługi

Z `examples/typescript/src/04-error-handling.ts` (przykład uruchamia **prawdziwy lokalny serwer HTTP** udający odpowiedzi 429 i 403, ponieważ prawdziwego KSeF nie da się o nie poprosić; pokazuje ścieżkę obsługi, nie dowód zgodności z API):

```ts
try {
  await client.http.request({ method: 'GET', path, idempotent: true });
} catch (e) {
  if (e instanceof RateLimitException) {
    console.log(`429 -> wait ${String(e.retryAfterSeconds)}s, then retry (retryable=${String(e.retryable)}, traceId=${String(e.traceId)})`);
  } else if (e instanceof AuthorizationException) {
    console.log('403 -> missing permissions:', JSON.stringify(e.diagnostics()));
  } else if (e instanceof ApiException) {
    console.log('other API error', e.statusCode, e.ksefCode);
  } else throw e;
}
```

Odpowiednik PHP z `examples/php/src/04-error-handling.php`:

```php
try {
    $client->transport()->send(new HttpRequest('GET', $path, idempotent: true));
} catch (RateLimitException $e) {
    echo "429 -> wait {$e->retryAfterSeconds()}s, then retry (retryable=" . var_export($e->retryable(), true) . ")\n";
} catch (AuthorizationException $e) {
    echo '403 -> missing permissions: ' . json_encode($e->diagnostics(), JSON_UNESCAPED_UNICODE) . "\n";
} catch (ApiException $e) {
    echo 'other API error ' . $e->statusCode() . ' ' . var_export($e->ksefCode(), true) . "\n";
}
```

Kolejność `catch` ma znaczenie: podklasy `ApiException` przed samą `ApiException`.

Przy wysyłce faktury obsłuż co najmniej `InvoiceRejectedException` (końcowe odrzucenie) i `UnknownOutcomeException` (uzgodnienie), jak w `03-send-invoice` ([Cykl życia](cykl-zycia-faktury.md)). Mapowanie wyjątków na odpowiedzi HTTP w adapterach: [Laravel](laravel.md#błędy-http), [NestJS](nestjs.md#filtr-wyjątków).

---

Built and maintained by LuroWeb.
