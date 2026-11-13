# Integracje komercyjne / Commercial integrations

KSeF Foundry jest darmowe (MIT). Autorem jest **LuroWeb**; jeśli potrzebujesz wdrożenia szytego na miarę, możesz zamówić integrację.
Kontakt: profil autora na GitHub — <https://github.com/mysterekkk>.

> Ta strona opisuje **do czego SDK można użyć**. SDK jest narzędziem programistycznym — nie jest gotowym ERP, CRM ani wtyczką. Wymienione scenariusze to możliwe wdrożenia na bazie SDK, a nie istniejące produkty.

## Typowe scenariusze

| Scenariusz | Co SDK daje | Co trzeba dobudować |
|---|---|---|
| Integracja KSeF z ERP | uwierzytelnianie, wysyłka, statusy, UPO, pobieranie | mapowanie modeli ERP na FA(3), kolejki, archiwizacja, uprawnienia |
| Integracja KSeF z CRM | pobieranie metadanych i faktur zakupowych | dopasowanie kontrahentów, workflow akceptacji |
| Aplikacja Laravel | pakiet `laravel-ksef-foundry` (DI, cache, kolejki) | logika biznesowa, interfejs użytkownika, polityka retencji |
| Backend NestJS | moduł `nestjs-ksef-foundry` (DI, konfiguracja async) | API dla frontendu, autoryzacja użytkowników |
| Automatyczne pobieranie faktur | iterator po metadanych z obsługą stron | harmonogram, trwały magazyn, deduplikacja po numerze KSeF |
| WooCommerce przez dedykowany backend | builder FA(3), bezpieczna wysyłka z kontrolą duplikatów | konektor sklepu, reguły VAT, korekty (poza zakresem v0.1 SDK) |
| Automatyzacja obiegu dokumentów | statusy, UPO, błędy z kodami KSeF | integracja z systemem dokumentów, powiadomienia |

## Czego nie obiecujemy

- SDK w wersji 0.x nie obsługuje m.in. faktur korygujących, zaliczkowych, sesji wsadowej i trybów offline — zob. [`api-compatibility.md`](api-compatibility.md).
- Nie świadczymy usług księgowych ani doradztwa podatkowego; odpowiedzialność za poprawność merytoryczną faktur leży po stronie wystawcy.

---

LuroWeb helps businesses integrate KSeF with custom applications, internal systems and business workflows. The library is free and MIT-licensed; commercial work is optional. Contact via the author's GitHub profile: <https://github.com/mysterekkk>.
