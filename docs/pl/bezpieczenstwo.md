# Bezpieczeństwo

SDK obsługuje poświadczenia podatników i dokumenty finansowe, więc bezpieczeństwo jest wymaganiem podstawowym. Ten przewodnik opisuje, co SDK gwarantuje domyślnie, co pozostaje po Twojej stronie i jak zgłosić podatność. Zasady są spójne z [`SECURITY.md`](../../SECURITY.md).

## Spis treści

- [Zasady bezpiecznego użycia](#zasady-bezpiecznego-użycia)
- [Obsługa sekretów](#obsługa-sekretów)
- [Polityka logowania](#polityka-logowania)
- [TLS](#tls)
- [SSRF i przekierowania](#ssrf-i-przekierowania)
- [Bezpieczeństwo XML](#bezpieczeństwo-xml)
- [Izolacja podatników](#izolacja-podatników)
- [Bezpieczeństwo operacji (duplikaty)](#bezpieczeństwo-operacji-duplikaty)
- [Czego SDK nie robi](#czego-sdk-nie-robi)
- [Zgłaszanie podatności](#zgłaszanie-podatności)

## Zasady bezpiecznego użycia

1. Trzymaj token KSeF i klucze prywatne w menedżerze sekretów, nie w repozytorium ani w obrazie kontenera.
2. Domyślne środowisko to TEST; PRODUCTION wymaga jawnego wyboru. Nie używaj prawdziwych danych (NIP, PESEL, faktur) w TEST i DEMO.
3. Nie włączaj logowania treści faktur. SDK domyślnie nie loguje niczego.
4. Własny `TokenStorage` powinien szyfrować dane w spoczynku i izolować klucze per podatnik.
5. Po niejednoznacznym wyniku wysyłki (`UnknownOutcomeException`) zawsze najpierw sprawdź stan (`reconcile`), dopiero potem ewentualnie ponów.
6. Używaj osobnych poświadczeń dla każdego środowiska ([Środowiska](srodowiska.md)).
7. Dla nowych integracji produkcyjnych rozważ certyfikat KSeF zamiast tokenu ([Uwierzytelnianie](uwierzytelnianie.md)).
8. Przy zgłoszeniach błędów i w issue **nigdy nie wklejaj** prawdziwych tokenów, kluczy, certyfikatów ani treści faktur.

## Obsługa sekretów

Za sekret SDK uważa: token KSeF, klucze prywatne, JWT (access i refresh), nagłówek `Authorization`, zaszyfrowane ładunki i klucze symetryczne.

- Wartości są opakowane (`SensitiveString`; w PHP także atrybut `#[\SensitiveParameter]`, gdzie jest dostępny). `var_dump`, `console.log`, `util.inspect`, `__toString` i serializacja JSON dają `[REDACTED]`.
- `Redactor` czyści wyjątki i `diagnostics()`. Wyjątki, ślady stosu i wyniki testów nie zawierają sekretów.
- Do `TokenStorage` trafia wyłącznie para JWT. Długotrwały token KSeF i klucz prywatny **nigdy** nie są przekazywane do magazynu.
- Para JWT nadal jest poświadczeniem (refresh token do 7 dni). Adapter Laravel szyfruje ją kluczem `APP_KEY`; po zmianie `APP_KEY` stare wpisy są traktowane jako brak (następuje nowe logowanie). `KSEF_CACHE_ENCRYPT=false` jest odradzane.
- Materiał klucza sesji (AES) jest trzymany tylko w pamięci i generowany od nowa dla każdej sesji.
- `SensitiveString` zwraca wartość tylko przez jawne `reveal()`; wywołuj je wyłącznie tam, gdzie surowa wartość jest naprawdę potrzebna, i nigdy nie przekazuj wyniku do loggera.

## Polityka logowania

- Domyślny logger jest pusty (no-op). Logger PSR-3 (PHP) lub interfejs `Logger` (TypeScript) podłączasz jawnie.
- Gdy włączysz logger, SDK zapisuje metodę, ścieżkę i status; **nigdy** treści faktur ani sekretów, a zdarzenia dodatkowo przechodzą przez redaktor.
- Laravel: `KSEF_LOG_CHANNEL` (puste = brak logów); logger jest owinięty `RedactingLogger`. Logi zadań zawierają nazwę połączenia i skrót faktury.
- NestJS: `logger: 'nest'` kieruje zdarzenia SDK do `Logger` o kontekście `Ksef`.
- **Uwaga:** tabela `failed_jobs` w Laravelu przechowuje ładunek zadania, czyli **XML faktury** (dane użytkownika, nie sekrety). Zabezpiecz ją i ustal retencję.
- Filtry wyjątków adapterów budują odpowiedzi wyłącznie z dozwolonych pól `diagnostics()`: bez tokenów, surowego ciała z KSeF i śladu stosu.

## TLS

- Weryfikacja certyfikatu serwera jest **zawsze włączona**; nie ma opcji jej wyłączenia. Minimalna wersja TLS to 1.2.
- Wbudowany klient PHP (`CurlHttpClient`) wymaga `ext-curl`. Na Windows bez ustawionego `curl.cainfo` weryfikacja TLS by się nie powiodła; wbudowany klient używa magazynu certyfikatów systemu (`CURLSSLOPT_NATIVE_CA`) przy włączonej weryfikacji.
- Własny klient PSR-18 musi mieć włączoną weryfikację TLS. Sterownik Guzzle w adapterze Laravel ma te opcje przypięte (`verify=true`); testy sprawdzają odrzucenie certyfikatu samopodpisanego na prawdziwym gnieździe.

## SSRF i przekierowania

- SDK wywołuje wyłącznie adresy wyprowadzone z adresu bazowego wybranego środowiska. Ścieżki żądań muszą być względne; adresy bezwzględne i ucieczka poza host są odrzucane.
- Adresy bezwzględne z odpowiedzi API (np. `downloadUrl` UPO) **nie są śledzone**. SDK woła udokumentowane endpointy.
- Przekierowań **nie śledzi się między hostami**. W TypeScript `fetch` działa z `redirect: 'manual'`; przekierowanie na inny host jest odrzucane, a dozwolone hosty to host środowiska oraz jawnie wymienione w opcji `allowedHosts`. PHP nie podąża za przekierowaniami.
- Własny klient PSR-18 (Guzzle, Symfony) **musisz** skonfigurować tak, by nie podążał za przekierowaniami na inne hosty.
- `Environment::custom()` / `Environment.custom()` dopuszcza tylko `https` (wyjątek: pętla zwrotna) i jest oznaczone jako niebezpieczne dla prawdziwych danych.
- Sterownik Guzzle adaptera Laravel **nie wymusza** limitu rozmiaru odpowiedzi (wbudowany klient curl go ma).

## Bezpieczeństwo XML

- Faktury są parsowane **bez dostępu do sieci** i bez rozwijania encji. `DOCTYPE`, deklaracje `ENTITY` i instrukcje przetwarzania są odrzucane (KSeF też je odrzuca).
- PHP ładuje XML z `LIBXML_NONET`, nigdy z `LIBXML_NOENT` ani `DTDLOAD`. TypeScript używa libxml2 w WebAssembly bez gniazd, dodatkowo z `--nonet`; pamięć jest ograniczona (start 16 MiB, twardy limit 128 MiB).
- Kontrole wstępne (rozmiar, UTF-8 bez BOM, kodowanie prologu, zabronione znaki) działają przed parsowaniem.
- Builder normalizuje teksty zgodnie z XSD i generuje XML z escapowaniem; wstrzyknięcie XML jest w zakresie zgłoszeń bezpieczeństwa. Implementacje PHP i TypeScript porównano na 1500 losowych, wrogich modelach (CR, LF, TAB, `]]>`, znaki specjalne XML, emoji): wyniki były bajt w bajt zgodne lub dawały te same błędy.
- Wbudowany klient curl ma limit rozmiaru odpowiedzi (domyślnie 64 MiB).
- Nie ma limitu czasu ani przerwania dla trwającej walidacji WASM; granicami są limit rozmiaru i pamięci.

## Izolacja podatników

Tokeny są buforowane pod kluczem `sha256(środowisko | typ kontekstu | wartość kontekstu | metoda | odcisk poświadczeń)`. Dwóch podatników nie współdzieli tokenów; ma to test. W aplikacjach wielodostępnych używaj osobnego klienta na podatnika (Laravel: `KsefManager::tenant()`, NestJS: `KsefClientRegistry`). Pamiętaj, że izolację danych w Twojej aplikacji (kto może wywołać które połączenie) zapewniasz Ty.

## Bezpieczeństwo operacji (duplikaty)

Niebezpieczne ponawianie jest traktowane jako podatność (patrz zakres w `SECURITY.md`). Dlatego wysyłka, otwarcie i zamknięcie sesji oraz `redeem` nie są ponawiane po niejednoznacznym błędzie. Zobacz [Cykl życia faktury](cykl-zycia-faktury.md) i [Obsługa błędów](obsluga-bledow.md).

## Czego SDK nie robi

- Nie weryfikuje kryptograficznie podpisu UPO.
- Nie przechowuje faktur; trwałość i retencja po Twojej stronie.
- Nie zastępuje kontroli dostępu w Twojej aplikacji.
- Nie był weryfikowany na DEMO ani PRODUCTION; certyfikaty kwalifikowane i certyfikat KSeF nie były sprawdzane na żywo.
- CI uruchamia `npm audit` i `composer audit` dla zależności produkcyjnych oraz sprawdza, czy śledzone pliki nie zawierają sekretów, ale to nie zastępuje własnego przeglądu zależności.

## Zgłaszanie podatności

**Nie zakładaj publicznego zgłoszenia (issue).** Użyj prywatnego zgłoszenia GitHub: <https://github.com/mysterekkk/ksef-foundry/security/advisories/new>.

Podaj: wersję pakietu, opis i wpływ, kroki odtworzenia (najlepiej minimalny przykład), informację, czy dotyczy TEST, DEMO czy PROD. Cel czasowy (best effort, projekt wolontariacki): potwierdzenie w 7 dni, poprawka lub plan w 30 dni, publikacja po wydaniu poprawki. Wspierana jest najnowsza wersja `0.x`.

W zakresie: wyciek sekretów do logów, wyjątków, plików lub artefaktów; błędy kryptografii (szyfrowanie sesji i tokenu, podpis XAdES); SSRF i wyłączanie TLS; XXE, rozszerzanie encji, wstrzyknięcie XML; mieszanie kontekstów podatników; niebezpieczne ponawianie prowadzące do duplikatów. Poza zakresem: podatności samego systemu KSeF (zgłaszaj do Ministerstwa Finansów) i błędy zależności bez wpływu na SDK.

---

Built and maintained by LuroWeb.
