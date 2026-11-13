# Publikacja w Packagist i npm / Publishing to Packagist and npm

**Stan na 2026-11-14:** oba pakiety npm (`@luroweb/ksef-foundry` i `@luroweb/nestjs-ksef-foundry`, wersja 0.1.0) zostały opublikowane w npmjs.com w dniu 2026-11-13 z konta `luroweb`, z opcją `--access public`. Pakiety Composer (`luroweb/ksef-foundry`, `luroweb/laravel-ksef-foundry`) **nie są** jeszcze opublikowane w Packagist (brak konta Packagist i repozytoriów split).
**Status as of 2026-11-14:** both npm packages (`@luroweb/ksef-foundry` and `@luroweb/nestjs-ksef-foundry`, version 0.1.0) were published to npmjs.com on 2026-11-13 by the account `luroweb`, with `--access public`. The Composer packages are **not yet** published on Packagist (a Packagist account and split repositories are still needed).

## Sprawdzone przed wydaniem

| Rejestr | Nazwa | Wynik sprawdzenia |
|---|---|---|
| npm | `@luroweb/ksef-foundry` | przed wydaniem (2026-11-13): `404 Not Found` (nazwa wolna). **Opublikowany 2026-11-13** |
| npm | `@luroweb/nestjs-ksef-foundry` | j.w. **Opublikowany 2026-11-13** |
| Packagist | `luroweb/ksef-foundry` | `404` (pakiet nie istnieje); **nieopublikowany**, własność vendora `luroweb` nie jest zweryfikowana |
| Packagist | `luroweb/laravel-ksef-foundry` | j.w. |

## npm (dwa pakiety, workspaces) - opublikowane

```bash
npm ci
npm run build
npm run verify:pack --workspaces --if-present      # npm pack + instalacja tarballa + smoke test + publint/attw
npm login                                          # konto z prawem do scope @luroweb
npm publish -w packages/typescript --access public   # najpierw rdzeń
npm publish -w packages/nestjs     --access public   # potem adapter
```

Uwagi z wydania 2026-11-13:

- Publikacja poszła z konta `luroweb` z `--access public`. Sumy kontrolne tarballi w rejestrze są zgodne z lokalnie przygotowanymi (zob. [test-report](dev/test-report.md)).
- Przy uwierzytelnianiu npm przez przeglądarkę (web-auth) po udanej publikacji CLI może wypisać echo błędu `403`. Jest to nieszkodliwe; stan pakietu należy sprawdzić w rejestrze (`npm view @luroweb/ksef-foundry version`).
- `--provenance` wymaga uruchomienia z GitHub Actions (OIDC); lokalnie pomiń. Adapter NestJS deklaruje peer-dependency `@luroweb/ksef-foundry`, dlatego rdzeń musi być opublikowany pierwszy, a wersje zsynchronizowane.

*EN:* published by account `luroweb` with `--access public`; registry tarball checksums match the local ones. With npm web-auth, the CLI may print a harmless `403` echo after a successful publish; verify with `npm view`. `--provenance` only works from GitHub Actions (OIDC). Publish the core first, then the NestJS adapter.

## Packagist (dwa pakiety w monorepo) - oczekuje

Packagist wymaga `composer.json` w **korzeniu** repozytorium, a pakiety PHP leżą w `packages/php` i `packages/laravel`. Dwie ścieżki:

1. **Repozytoria „split” (zalecane):** utwórz `mysterekkk/ksef-foundry-php` i `mysterekkk/laravel-ksef-foundry` (tylko do odczytu) i synchronizuj podkatalogi np. przez [splitsh/lite](https://github.com/splitsh/lite) lub `git subtree split` w workflow wydania; zgłoś te repozytoria w Packagist pod nazwami `luroweb/ksef-foundry` i `luroweb/laravel-ksef-foundry`.
2. Do czasu publikacji użytkownicy mogą używać repozytorium typu `path`/`vcs` (zob. `docs/pl/szybki-start.md`).

Przed zgłoszeniem: `composer validate --strict` w obu pakietach (zielone w CI), tag `v0.1.0` w repozytoriach split, a w `packages/laravel/composer.json` zależność `luroweb/ksef-foundry: ^0.1` (rozwiąże się po publikacji rdzenia).

*EN:* Packagist needs a root `composer.json`, so use read-only split repositories and submit them as `luroweb/ksef-foundry` and `luroweb/laravel-ksef-foundry`. Until then, users install through a `path`/`vcs` repository.

## Lista kontrolna przed publikacją

- [x] konta/scope npm zweryfikowane, nazwy npm dostępne (konto `luroweb`),
- [ ] konto Packagist, repozytoria split i nazwy Packagist,
- [x] CI zielone na tagu, `docs/dev/test-report.md` zaktualizowany (npm),
- [x] `CHANGELOG.md` i wersje w manifestach zgodne (`node scripts/check-consistency.mjs`),
- [x] odznaki npm dodane do README po publikacji,
- [ ] odznaki Packagist dodać do README dopiero po publikacji w Packagist (nie dodawać wcześniej).
