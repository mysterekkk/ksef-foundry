Wypuściłem wersję v0.1.0 projektu KSeF Foundry: otwartoźródłowego SDK (MIT) dla Krajowego Systemu e-Faktur (KSeF 2.0), dla PHP/Laravel i TypeScript/NestJS.

To nie jest pierwsza biblioteka do KSeF. Ministerstwo Finansów udostępnia oficjalne klienty C# i Java, istnieją też niezależne klienty PHP i TypeScript. Co starałem się zrobić inaczej:

- jeden projekt z dwoma SDK o tych samych nazwach, modelu błędów i regułach ponawiania, plus adaptery Laravel i NestJS
- wysyłka odporna na duplikaty: HTTP 202 oznacza "żądanie odebrane", a nie "przyjęte", a po niejednoznacznym wyniku SDK uzgadnia stan po skrócie faktury zamiast wysyłać ponownie
- offline'owa walidacja FA(3) względem oficjalnego XSD i dokładna arytmetyka dziesiętna w builderze
- zasada testów bez mocków: kryptografia, XSD i API KSeF TEST są testowane naprawdę, a testy z wstrzykiwaniem błędów są wyraźnie tak oznaczone

Uczciwy status: zweryfikowane na żywo tylko w środowisku TEST. DEMO i PRODUKCJI nigdy nie dotykałem, certyfikaty kwalifikowane i wydane przez KSeF są niezweryfikowane, a sesje wsadowe, tryby offline i faktury korygujące nie są zaimplementowane. Pakiety są przygotowane, ale jeszcze nie opublikowane w Packagist ani npm, więc na razie instalacja z repozytorium.

Repozytorium, zakres i ustalenia: https://github.com/mysterekkk/ksef-foundry
Uwagi i zgłoszenia błędów mile widziane w issues.

#KSeF #PHP #TypeScript #OpenSource
