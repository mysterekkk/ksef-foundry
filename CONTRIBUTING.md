# Contributing / Współtworzenie

Dziękujemy za zainteresowanie! / Thanks for your interest!

## Zasady / Ground rules

1. **Kontrakt jest święty.** Zachowanie SDK wynika z oficjalnego OpenAPI i przewodnika MF — nie zgadujemy. Zob. [`docs/dev/sdk-contract.md`](docs/dev/sdk-contract.md) i [`docs/api-compatibility.md`](docs/api-compatibility.md).
2. **Bez mocków dla logiki, którą da się uruchomić naprawdę.** Kryptografia, XSD i API TEST są testowane naprawdę; scenariusze awarii — przeciwko prawdziwemu lokalnemu serwerowi HTTP (`fault-injection`).
3. **PHP i TypeScript muszą zachowywać się identycznie** (te same nazwy, te same reguły ponawiania, ten sam model błędów).
4. **Żadnych sekretów ani prawdziwych danych** (NIP, PESEL, faktur) w kodzie, testach, issue i PR.
5. Nigdy nie kieruj testów, przykładów ani CI na DEMO/PRODUCTION.

## Środowisko / Setup

```bash
# TypeScript + NestJS (Node >= 22)
npm ci
npm run build --workspaces --if-present
npm test --workspaces --if-present

# PHP (>= 8.2) + Laravel
cd packages/php && composer install && composer check
cd ../laravel && composer install && composer check
```

Testy live (opcjonalne, tylko TEST, bez żadnych sekretów — same tworzą syntetycznego podatnika):

```bash
KSEF_LIVE_TEST=1 composer --working-dir=packages/php test:live
KSEF_LIVE_TEST=1 npm --workspace @luroweb/ksef-foundry run test:live
```

## Pull requesty

- Mały, skupiony PR; opis co i dlaczego; testy; aktualizacja `CHANGELOG.md` i dokumentacji (PL + EN).
- Zmiany w API publicznym wymagają wpisu w CHANGELOG i uzasadnienia.
- Commity: [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, `test:`, `chore:`).
- CI musi być zielone. Wynik „SKIPPED” testów live nie jest sukcesem.

## Aktualizacja kontraktu OpenAPI

`node scripts/check-openapi-drift.mjs` → przeczytaj changelog MF → zaimplementuj → testy (w tym live) → `--update`.
