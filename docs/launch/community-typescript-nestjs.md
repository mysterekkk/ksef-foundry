# Description for TypeScript / NestJS communities

Language: English first, Polish version below. Draft for manual posting (forums, Discord/Slack, groups). Read each community's self-promotion rules first, say you are the author, and be ready to answer questions.

---

## English

**KSeF Foundry v0.1.0: TypeScript and NestJS SDK for Poland's e-invoicing system (KSeF 2.0), MIT**

I am the author. This is an early release of an SDK for KSeF, the Polish national e-invoice system.

**Packages (prepared, not yet published to npm)**

- `@luroweb/ksef-foundry`: TypeScript SDK, Node 22+, ESM, `.d.ts`, strict types
- `@luroweb/nestjs-ksef-foundry`: NestJS 11 adapter with `KsefModule.forRoot/forRootAsync`, an exception filter and a health indicator
- the same repository contains PHP and Laravel SDKs with identical behaviour (names, error model, retry rules)

**What it does**

- KSeF token and certificate (XAdES) authentication, JWT cache and refresh
- encrypted interactive session (AES plus RSA-OAEP SHA-256), FA(3) send to a final status, KSeF number, UPO, invoice download with hash check
- duplicate-safe sending: HTTP 202 is "received", not "accepted"; after an ambiguous outcome you get an `UnknownOutcomeException` and reconcile by invoice hash instead of resending
- FA(3) builder with exact decimal arithmetic and offline XSD validation (libxml2 compiled to WebAssembly, so `build()` is async)

**How it differs**

It is not the first KSeF library: the Ministry of Finance publishes official C# and Java clients, and independent PHP and TypeScript clients exist. The goal here is two ecosystems with the same behaviour, framework adapters, and a no-mocks testing policy whose limits are documented.

**Honest status**

- Verified live on the KSeF TEST environment only. DEMO and PRODUCTION were never touched.
- Qualified and KSeF-issued certificates are unverified; only self-signed TEST certificates were exercised.
- Not implemented: batch sessions, offline modes, QR codes, correcting and advance invoices.
- Until the npm release, install from a clone or a tarball from `npm pack`. Examples: `npm run 03:send -w @luroweb/ksef-foundry-example-typescript`.
- Version 0.x: the API may change.

Repository: https://github.com/mysterekkk/ksef-foundry
Issues and feedback: https://github.com/mysterekkk/ksef-foundry/issues
Author: https://github.com/mysterekkk (LuroWeb)

Test results for this release: [INSERT REPORT LINK BEFORE POSTING]

---

## Polski

**KSeF Foundry v0.1.0: SDK TypeScript i NestJS do KSeF 2.0 (MIT)**

Jestem autorem. To wczesne wydanie SDK do Krajowego Systemu e-Faktur.

- `@luroweb/ksef-foundry`: SDK TypeScript (Node 22+, ESM, ścisłe typy) oraz `@luroweb/nestjs-ksef-foundry`: adapter NestJS 11 (`KsefModule.forRoot/forRootAsync`, filtr wyjątków, health indicator). Pakiety są przygotowane, ale jeszcze nie opublikowane w npm. Na razie instalacja z klonu lub paczki z `npm pack`.
- Funkcje: logowanie tokenem i certyfikatem, szyfrowana sesja interaktywna, wysyłka FA(3) do statusu końcowego, UPO, pobranie faktury z kontrolą skrótu, wysyłka odporna na duplikaty (HTTP 202 to nie przyjęcie, po niejednoznacznym wyniku uzgadnianie po skrócie faktury), builder FA(3) i offline'owa walidacja XSD.
- To nie jest pierwsza biblioteka KSeF (są oficjalne klienty C#/Java MF i niezależne klienty PHP/TS). Różnica: PHP i TypeScript w jednym projekcie z identycznym zachowaniem, adaptery Laravel i NestJS, testy bez mocków z jawnie opisanymi granicami.
- Status: zweryfikowane na żywo tylko w TEST. DEMO i PRODUKCJI nigdy nie dotykałem. Certyfikaty kwalifikowane i KSeF niezweryfikowane. Brak sesji wsadowych, trybów offline, kodów QR i faktur korygujących. Wersja 0.x.

Repozytorium: https://github.com/mysterekkk/ksef-foundry
Zgłoszenia: https://github.com/mysterekkk/ksef-foundry/issues
