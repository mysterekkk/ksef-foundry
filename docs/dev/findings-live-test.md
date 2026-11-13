# Ustalenia z testów live (KSeF TEST) / Findings from live TEST runs

Rozbieżności i zachowania zaobserwowane na `api-test.ksef.mf.gov.pl` (2026-11-13, kontrakt 2.9.0).
Zasada projektu: **żywe API wygrywa z dokumentacją**. Źródło: testy live pakietu TypeScript (PHP dopisze własne w sekcji końcowej).

1. **`/testdata/person` działa z opóźnieniem kilku sekund.** Logowanie XAdES tuż po utworzeniu osoby może skończyć się statusem `415 „Brak przypisanych uprawnień"`; to samo żądanie kilka sekund później przechodzi. Testy live ponawiają przy 415.
2. **Przestrzeń nazw `AuthTokenRequest`.** XSD v2-1: `http://ksef.mf.gov.pl/auth/token/2.1`; przykłady w przewodniku: `…/2.0`. TEST przyjął oba. SDK używa 2.1.
3. **Błędy podpisu wracają synchronicznie** z `POST /auth/xades-signature` jako 400: `9101` (zły dokument), `9102` (brak podpisu), `9105` (nieprawidłowy podpis/skrót). Problemy z certyfikatem lub uprawnieniami (`415`, `460`) widać dopiero w odpytywaniu statusu.
4. **Dwa przykłady w OpenAPI pomijają pola wymagane przez schemat**: `GET /auth/{ref}` (brak `authenticationMethodInfo`), `GET /sessions/{ref}/invoices/{invoiceRef}` (brak `invoiceHash`). Test kontraktowy trzyma je na liście znanych braków.
5. **`iat` w JWT ma rozdzielczość 1 s** — odświeżenie w tej samej sekundzie zwraca identyczny access token.
6. `/testdata/person` i `/remove` zwracają 200 z pustym ciałem.
7. Nagłówek `X-Error-Format: problem-details` jest akceptowany; błędy 400 przychodzą jako `application/problem+json`. Starsze ciało 429 jest oznaczone jako deprecated.
8. Odpowiedzi ustawiają ciasteczka Imperva — SDK je ignoruje.
9. Przewodnik pokazuje `downloadUrl` UPO jako `/api/v2/...`, a faktyczna ścieżka bazowa to `/v2`. SDK nigdy nie podąża za `downloadUrl` (reguła SSRF) i woła udokumentowane endpointy.
10. **UPO z TEST nie przechodzi walidacji `upo-v4-3.xsd`** z dwóch powodów: `NazwaPodmiotuPrzyjmujacego` = `Ministerstwo Finansów - środowisko testowe (TE)` (XSD wymaga stałej `Ministerstwo Finansów`) oraz UPO zawiera `ds:Signature` (XAdES MF, zgodnie z dokumentacją), którego XSD nie deklaruje. Reszta jest zgodna. Test live potwierdza, że to jedyne dwa odchylenia i że podpis jest obecny. **Podpis UPO nie jest weryfikowany kryptograficznie.**
11. `failedInvoiceCount` jest pomijane (a nie `0`), gdy żadna faktura nie została odrzucona.
12. Status duplikatu 440 zawiera `extensions.originalKsefNumber` i `extensions.originalSessionReferenceNumber`.
13. **Faktura próbna** zbudowana przez `Fa3InvoiceBuilder.sample` została przyjęta przez TEST bez zmian (status 200, numer KSeF nadany).

## Zweryfikowane na żywo (TypeScript) / Verified live
Logowanie XAdES (RSA 2048 i ECDSA P-256, cert samopodpisany `PNOPL-<pesel>`, `certificateSubject`, kontekst NIP), `POST /tokens` + logowanie tokenem KSeF (RSA-OAEP `token|timestampMs`), refresh, otwarcie/zamknięcie sesji interaktywnej z kluczem AES zaszyfrowanym RSA-OAEP-SHA256, wysyłka FA(3), status 200 z numerem KSeF, pobranie faktury i zgodność skrótu, UPO faktury i sesji, wykrycie duplikatu (440), zapytanie o metadane, limity, sesje.

## Niezweryfikowane na żywo / Not verified live
Prawdziwy 429 z `Retry-After`, błędy 21470/21184, 500/502/503 (tylko lokalny serwer fault-injection); certyfikaty kwalifikowane i certyfikat KSeF; `certificateFingerprint`; inne typy kontekstu; DEMO i PRODUCTION (nigdy nie używane); platformy inne niż Windows/Node 24.

---

## Dodatkowe ustalenia z testów live PHP / Additional findings (PHP run)

14. **Zamknięcie pustej sesji** (bez faktur) kończy się statusem 440 „Sesja anulowana", a nie 200.
15. **Pobranie faktury tuż po statusie 200** zwróciło raz gołe HTTP 406; kolejne wywołanie przeszło (niepowtarzalne). `InvoiceService::download()` ponawia 406 / kod 21165 do 15 s — ten retry jest sprawdzony tylko na lokalnym serwerze fault-injection, nie na żywo.
16. **XSD uwierzytelniania:** wzorzec `TNipVatUE` kończy się dosłownym `$`, więc żadna wartość go nie spełnia. Test XSD pomija kontekst `NipVatUe`.
17. **TLS na Windows:** bez `curl.cainfo` weryfikacja TLS kończy się błędem; wbudowany klient używa magazynu certyfikatów systemu (`CURLSSLOPT_NATIVE_CA`) przy włączonej weryfikacji.
18. `GET /auth/{ref}` w OpenAPI używa `examples` (liczba mnoga) zamiast `example`.
19. Metody nieobjęte testem live w PHP: `GET /sessions` (lista sesji) nie jest zaimplementowane; `/sessions/{ref}/invoices/failed` i `…/ksef/{n}/upo` są sprawdzone tylko kształtem na lokalnym serwerze.
20. **Pobranie faktury tuż po statusie 200** może zwrócić HTTP 400 z kodem `21165` („przetworzona, ale jeszcze niedostępna do pobrania”) albo (rzadziej) gołe 406; znika po kilku sekundach. Oba SDK ponawiają pobranie przez maks. 15 s (kod 21165 występuje w OpenAPI tylko dla `GET /invoices/ksef/{ksefNumber}`). Zaobserwowane na żywo również w TypeScript (opóźnienie 1,2 s przy logowaniu tokenem). Zachowanie przy niedostępności dłuższej niż 15 s zweryfikowano tylko na lokalnym serwerze fault-injection.
21. **XSD uwierzytelniania:** wzorce `TNipVatUE` i `TPeppolId` mają dosłowne `^`/`$` wewnątrz wyrażenia, więc żadna wartość ich nie spełnia; testy XSD pomijają kontekst `NipVatUe` i `PeppolId`. (Serwer TEST przyjął logowanie z kontekstem `Nip`, pozostałych nie weryfikowano live.)
22. **Odpowiedź 2xx na wysyłkę może być uszkodzona/ucięta** — SDK traktuje to jako niejednoznaczny wynik (`UnknownOutcomeException`), nie jako sukces ani zwykły błąd (wynik przeglądu bezpieczeństwa; sprawdzone na lokalnym serwerze fault-injection).
