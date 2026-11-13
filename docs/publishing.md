# Publikacja w Packagist i npm / Publishing to Packagist and npm

**Stan na 2026-11-13: żaden pakiet nie jest opublikowany.** Ten dokument opisuje przygotowanie; samej publikacji nie wykonano (wymaga kont i potwierdzenia właściciela).
**Status: nothing is published yet.** This file only prepares the steps.

## Sprawdzone przed wydaniem

| Rejestr | Nazwa | Wynik sprawdzenia (2026-11-13) |
|---|---|---|
| npm | `@luroweb/ksef-foundry` | `404 Not Found` (nazwa wolna); `npm whoami` → brak zalogowania, więc **uprawnień do scope `@luroweb` nie zweryfikowano** |
| npm | `@luroweb/nestjs-ksef-foundry` | j.w. |
| Packagist | `luroweb/ksef-foundry` | `404` (pakiet nie istnieje); własność vendora `luroweb` nie jest zweryfikowana |
| Packagist | `luroweb/laravel-ksef-foundry` | j.w. |

Jeśli scope `@luroweb` nie istnieje lub nie należy do Ciebie: utwórz organizację npm o tej nazwie (darmowa dla pakietów publicznych) albo zmień nazwy pakietów w `package.json` i dokumentacji.

## npm (dwa pakiety, workspaces)

```bash
npm ci
npm run build
npm run verify:pack --workspaces --if-present      # npm pack + instalacja tarballa + smoke test + publint/attw
npm login                                          # konto z prawem do scope @luroweb
npm publish -w packages/typescript --access public --provenance   # najpierw rdzeń
npm publish -w packages/nestjs     --access public --provenance   # potem adapter
```

`--provenance` wymaga uruchomienia z GitHub Actions (OIDC); lokalnie pomiń. Adapter NestJS deklaruje peer-dependency `@luroweb/ksef-foundry`, dlatego rdzeń musi być opublikowany pierwszy, a wersje zsynchronizowane.

## Packagist (dwa pakiety w monorepo)

Packagist wymaga `composer.json` w **korzeniu** repozytorium, a pakiety PHP leżą w `packages/php` i `packages/laravel`. Dwie ścieżki:

1. **Repozytoria „split” (zalecane):** utwórz `mysterekkk/ksef-foundry-php` i `mysterekkk/laravel-ksef-foundry` (tylko do odczytu) i synchronizuj podkatalogi np. przez [splitsh/lite](https://github.com/splitsh/lite) lub `git subtree split` w workflow wydania; zgłoś te repozytoria w Packagist pod nazwami `luroweb/ksef-foundry` i `luroweb/laravel-ksef-foundry`.
2. Do czasu publikacji użytkownicy mogą używać repozytorium typu `path`/`vcs` (zob. `docs/pl/szybki-start.md`).

Przed zgłoszeniem: `composer validate --strict` w obu pakietach (zielone w CI), tag `v0.1.0` w repozytoriach split, a w `packages/laravel/composer.json` zależność `luroweb/ksef-foundry: ^0.1` (rozwiąże się po publikacji rdzenia).

## Lista kontrolna przed publikacją

- [ ] konta/scope zweryfikowane, nazwy dostępne,
- [ ] CI zielone na tagu, `docs/dev/test-report.md` zaktualizowany,
- [ ] `CHANGELOG.md` i wersje w manifestach zgodne (`node scripts/check-consistency.mjs`),
- [ ] dopiero po publikacji: dodać prawdziwe odznaki Packagist/npm do README (nie dodawać wcześniej).
