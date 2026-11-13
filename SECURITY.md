# Security Policy / Polityka bezpieczeństwa

KSeF Foundry komunikuje się z systemem faktur i obsługuje dane uwierzytelniające podatników.
Bezpieczeństwo jest wymaganiem podstawowym. / This library handles taxpayer credentials; security is a core requirement.

## Zgłaszanie podatności / Reporting a vulnerability

**Nie zakładaj publicznego zgłoszenia (issue) dla podatności.** Użyj prywatnego zgłoszenia GitHub:
<https://github.com/mysterekkk/ksef-foundry/security/advisories/new>

Do not open a public issue. Use GitHub private vulnerability reporting (link above).

Podaj / Please include: wersję pakietu, opis i wpływ, kroki odtworzenia (najlepiej minimalny przykład), czy dotyczy TEST/DEMO/PROD.
**Nigdy nie wklejaj prawdziwych tokenów KSeF, kluczy prywatnych, certyfikatów ani treści faktur.**

Cel czasowy (best effort, projekt wolontariacki): potwierdzenie w 7 dni, poprawka lub plan w 30 dni, publikacja po wydaniu poprawki.

## Wspierane wersje / Supported versions

| Wersja | Wsparcie |
|---|---|
| najnowsza `0.x` | poprawki bezpieczeństwa |
| starsze | brak |

## Co jest w zakresie / In scope

- wyciek sekretów (token KSeF, JWT, refresh token, klucze) do logów, wyjątków, plików lub artefaktów,
- błędy kryptografii (szyfrowanie sesji/tokenu, podpis XAdES),
- SSRF / podążanie za nieoczekiwanymi adresami URL, wyłączanie weryfikacji TLS,
- XXE, rozszerzanie encji, XML injection w walidatorze i builderze FA(3),
- mieszanie kontekstów podatników (tenant isolation) w cache tokenów,
- niebezpieczne ponawianie operacji prowadzące do duplikatów faktur.

Poza zakresem: podatności w samym systemie KSeF (zgłaszaj do Ministerstwa Finansów), błędy w zależnościach bez wpływu na SDK.

## Zasady bezpiecznego użycia / Safe-use rules

1. Trzymaj token KSeF i klucze prywatne w menedżerze sekretów, nie w repozytorium.
2. Domyślne środowisko to TEST; PRODUCTION wymaga jawnego wyboru. Nie używaj prawdziwych danych w TEST/DEMO.
3. Nie włączaj logowania treści faktur; SDK domyślnie nie loguje niczego.
4. Własny `TokenStorage` powinien szyfrować dane w spoczynku i izolować klucze per podatnik.
5. Po niejednoznacznym wyniku wysyłki (`UnknownOutcomeException`) zawsze najpierw sprawdź stan, dopiero potem ponawiaj.
