# R0 — licenční a provenance rozhodnutí

> Auditováno: 2026-08-31  
> Rozsah: managed fork `hrabosh/fakturace.revizior.cz` před vznikem runtime integrace

## Rozhodnutí

Managed ReviziOR fork pokračuje výhradně z MIT větve MyInvoice. Kořenový `LICENSE` je
autoritativní licence vlastního kódu. Hodnota `proprietary` v `api/composer.json` byla zděděná
chybná metadata: veřejný upstream tentýž soubor deklaroval stejně, přestože kořen repozitáře a
GitHub metadata deklarují MIT. R0 ji sjednocuje na `MIT`.

Toto rozhodnutí se nevztahuje na MyÚčto. Z něj se do forku nesmí kopírovat žádný kód, asset ani
proprietární účetní nadstavba bez samostatného písemného oprávnění.

## Doložený původ

- upstream: `https://github.com/radekhulan/myinvoice.git`;
- ověřený upstream commit: `b173abc6f1198ae7c4945e84666ec5e7769c52c5`
  (`2026-08-27T21:10:03Z`);
- fork commit před R0: `c73528a8e1f526b253e075c470cbc651e5d07f6f`;
- merge-base forku a upstreamu je uvedený upstream commit;
- změny forku před R0 jsou pouze šest dokumentačních commitů v
  `source/revizior-integration/` a rozcestníku `source/00-README.md`;
- MyÚčto bylo ověřeno na commitu `1b953ab5c873375b617e1500a1cb4cd98d53ca7c`
  (`2026-08-30T17:44:40Z`); jeho strom neobsahoval cestu s `revizior`/`revizor` a managed
  integrace tam nebyla hotová.

Git konfigurace lokálního checkoutu měla na začátku R0 pouze `origin`. Kanonická upstream URL a
commit jsou proto zapsané zde; přidání lokálního remote není součástí verzovaného obsahu repa.

## Third-party audit

`api/composer.lock` byl zkontrolován po všech produkčních i vývojových balících. Převládají
permisivní licence, ale distribuce musí respektovat zejména GPL/LGPL balíky vyjmenované v
kořenovém `THIRD_PARTY_NOTICES.md`. Přesný frontend i backend inventory se při release generuje z
lock souborů příkazy uvedenými v tomto notice; package license files musí v bundle zůstat.

Audit původního locku našel dvě nové advisories pro Guzzle `7.15.1`. MyÚčto už na kontrolovaném
commitu používalo opravenou verzi `7.15.5`, proto R0 synchronizuje pouze Guzzle rodinu na
`guzzlehttp/guzzle 7.15.5`, `guzzlehttp/promises 2.5.3` a `guzzlehttp/psr7 2.13.1`. Následný
`composer audit --locked` nehlásí žádnou známou bezpečnostní advisory.

R0 neprovádí právní reklasifikaci třetích stran a netvrdí, že jejich podmínky přecházejí na
vlastní MIT zdrojáky. Produkční distributor odpovídá za splnění jejich attribution/source
obligations.

## JOSE/JWT security gate

Pro R2 je schválena knihovna `web-token/jwt-framework`, nikoli vlastní kryptografie:

- podporuje explicitní JWS algoritmy, JWK/`kid`, PS256 a detached JWS potřebné pro service
  assertions i podepsané webhook body;
- licence je MIT a PHP požadavek `>=8.2` je kompatibilní s PHP 8.5;
- minimální bezpečná řada je `>=4.1.7`; verze před `4.1.7` v řadě 4.1 mají čtyři zveřejněné
  advisories z 2026-06-06, včetně algorithm-confusion a CPU-amplification DoS;
- při implementaci se má použít aktuální zkontrolovaná 4.2.x verze (v době auditu `4.2.2`) a
  Composer audit musí zůstat zelený;
- povolený algoritmus se váže na nakonfigurovaný `kid`; nesmí se přijmout z nechráněného headeru
  ani obecného seznamu algoritmů.

Dependency se v R0 záměrně nepřidává: žádný runtime verifier/signer ještě neexistuje. Přidání a
integrační security testy jsou atomický úkol R2.

## Gate do produkce

- zachovat root MIT notice a tento provenance záznam;
- přiložit/uchovat third-party license texts v release bundle a container image;
- před releasem spustit `composer audit` a odpovídající npm/pnpm audit;
- nevydat managed release, pokud se objeví nedoložený soubor z MyÚčto nebo nevyřešená licence;
- SBOM/lock-file inventory archivovat jako release artefakt.
