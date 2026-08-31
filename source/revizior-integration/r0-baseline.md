# R0 baseline

> Stav baseline: provider i cross-repo hash gate zelené
> Datum: 2026-08-31

## Identita zdrojů

| Zdroj | URL | Commit |
|---|---|---|
| MyInvoice upstream | `https://github.com/radekhulan/myinvoice.git` | `b173abc6f1198ae7c4945e84666ec5e7769c52c5` |
| ReviziOR fork před R0 | `git@github.com:hrabosh/fakturace.revizior.cz.git` | `c73528a8e1f526b253e075c470cbc651e5d07f6f` |
| Kontrolovaný MyÚčto strom | `https://github.com/radekhulan/myucto.git` | `1b953ab5c873375b617e1500a1cb4cd98d53ca7c` |

Merge-base upstreamu a forku je `b173abc6…`; fork před R0 měnil jen integrační dokumentaci.
Podrobnosti a licenční závěr jsou v `licensing-decision.md`.

## Zmrazený kontrakt

- verze: `1.0`;
- base path: `/api/integrations/revizior/v1`;
- samostatný kontrakt: `api/openapi-revizior-integration.yaml`;
- kanonické payloady a hashe: `source/revizior-integration/contract/v1/`;
- validace: `python3 tools/validateReviziorContract.py`;
- R0 neregistruje žádnou integrační ani SSO runtime route.

Consumer `hrabosh/backend` obsahuje kopii kanonických payloadů a unit gate, který ověřuje
zmrazený hash manifest, přesný seznam 13 fixture a canonical SHA-256 každého JSON payloadu.
Gate má 1 test a 43 assertions. Consumer si smí držet další vlastní contract fixture, ale ty
nejsou součástí provider manifestu.

## Výsledek čistého běhu

Ověřeno v izolovaných kontejnerech s PHP `8.5.9`, MariaDB `11.8`, Redis `7` a Node `24`:

| Kontrola | Výsledek |
|---|---|
| produkční Alpine image | sestaven včetně 99 produkčních Composer balíků, Vue buildu a manuálu |
| čistá DB | všech 155 migrací aplikováno; druhý běh: žádné nové migrace, oba backfill checky OK |
| syntetický CI seed | 2 suppliers, 4 měny, 1 admin |
| PHPUnit 13 | 2 093 testů, 7 020 assertions, 0 failures; 114 očekávaných skipů, 1 existující deprecation |
| PHPStan | level 0, `No errors` při explicitním limitu 1 GB |
| frontend | 62/62 PWA testů, `vue-tsc --noEmit` OK, produkční Vite build 398 modulů |
| kontrakt | 13 fixtures, canonical SHA-256, všechny lokální OpenAPI refs OK |
| nezávislý OpenAPI lint | validní OpenAPI 3.1; pouze očekávané style warning pro SSO-only `303` response |
| Composer metadata/audit | validní; 0 security advisories po synchronizaci Guzzle 7.15.5 z MyÚčto |

R0 splňuje provider i cross-repo akceptaci. Samostatný širší consumer Contract suite vznikal
souběžně a není součástí hash gate; jeho wire codec musí nadále vycházet z tohoto kanonického
provider kontraktu.

## Charakterizační krytí

| Oblast | Baseline guard |
|---|---|
| create/update client | `ReviziorSharedFlowCharacterizationTest` + HTTP create/effective-role scénář v `SupplierMembershipTest` |
| invoice draft orchestrace | `ReviziorSharedFlowCharacterizationTest` |
| invoice totals a `prices_include_vat` | `PricesIncludeVatTest`, `InvoiceMathTest` |
| price resolution/customer override | `PriceListItemResolverTest` |
| issue/send/payment/cancel/credit-note body | charakterizované activity/event hook points v `ReviziorSharedFlowCharacterizationTest`; behavior dále kryjí stávající invoice/payment testy |
| supplier scope a effective role | `SupplierMembershipTest`, `SupplierGuardTest`, `RoleMiddlewareTest` |

Guardy záměrně popisují současné action-owned chování. R3/R5 je smí změnit teprve současně s
vytažením sdílené application služby a odpovídajícími funkčními testy.

## Migrace

Nejvyšší existující prefix je `0150`; další volné číslo pro managed overlay je `0151` (pod
hranicí `1000`). R0 žádnou migraci nevytváří. V historii existují duplicitní prefixy, proto se
při každé další fázi znovu kontroluje přesný seznam souborů, ne jen aritmetické maximum.

## Opakovatelná validace

```bash
python3 tools/validateReviziorContract.py
cd api && composer validate --no-check-publish
cd api && php vendor/bin/phpunit
cd api && php vendor/bin/phpstan analyse src --level=0 --no-progress --memory-limit=1G
cd web && pnpm test:pwa
cd web && pnpm type-check
cd web && pnpm build
php api/bin/migrate.php --status
```

Consumer hash gate se spouští v jeho PHPUnit Unit suite.
