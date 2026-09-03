# R6 slice 2 — tenantová obrazovka nastavení fakturace

> Stav: hotovo — SSO cíl `/settings/supplier` odemčený
> Datum: 2026-09-03

## Proč vznikla

Managed režim skrývá platformní `/admin/settings` a `/admin/codebooks`, ale
doklad bez úplné adresy a DIČ vystavit nejde a ReviziOR tyhle údaje sám
neposílá (kontrakt nese jen to, co o organizaci ví). Vlastník firmy tak neměl
kde je doplnit; SSO cíl `/settings/supplier` proto do teď vracel
`sso_target_unavailable`.

## Co obrazovka umí

`web/src/pages/SupplierSettings.vue` na routě `settings/supplier`
(`requiresPermission: supplier_settings.manage`, `requiresSupplier`):

- fakturační identita — název, ulice, město, PSČ;
- daňové údaje — IČO, DIČ a režim DPH jako **jeden výběr** (neplátce /
  identifikovaná osoba / plátce), protože `is_vat_payer` a `is_identified`
  se podle § 6g–6l vylučují a dvě zaškrtávátka svádí k neplatné kombinaci;
- kontakt — e-mail, telefon, web;
- výchozí hodnoty dokladu — splatnost, jednotka splatnosti, režim cen.

Chybějící povinné údaje vypíše nahoře **dřív**, než uživatel narazí na
odmítnutý doklad. Země je jen ke čtení: přiřazuje ji provisioning podle
organizace a její změna by rozhodila daňový režim už vystavených dokladů.

Stránka jede na existujícím `GET/PUT /api/settings/supplier`, které už mají
oprávnění `supplier_settings.manage` — žádný nový endpoint, žádná migrace.
Posílá jen pole, která edituje, takže zbytek nastavení firmy zůstává, jak ho
má instalace nebo provisioning.

Navigace: položka **Systém → Nastavení fakturace** se zobrazí každému, kdo má
`supplier_settings.manage` a není platformní admin (ten má vlastní sekci).
Obě locale, manuál § 36.2.5.

## SSO

`ReviziorSsoTargetPolicy` teď `/settings/supplier` propouští. Ceník zůstává
přeložený z kontraktního `/price-list` na `/admin/price-list`.

## Cross-repo smoke (2026-09-03)

| Krok | Výsledek |
|---|---|
| `/faktury/nastaveni` v ReviziORu | `303` na SSO poskytovatele |
| SSO | `303` na `/settings/supplier` (dřív `403 sso_target_unavailable`) |
| SPA | `200`, `GET /api/settings/supplier` vrátil firmu z provisioningu |
| uložení | `PUT` prošlo jako `supplier_owner`: DIČ doplněno, režim DPH `plátce` |

## Ověření

- `pnpm type-check`, `pnpm build`, `pnpm test:pwa` — 66 testů zelených, nový
  test hlídá oprávnění, routu, navigaci i obě locale;
- PHPUnit celá suite a PHPStan level 0 beze změny;
- manuál přegenerován (HTML, 42 kapitol).

## Odkaz na revizi u dokladu

`GET /api/invoices/{id}/revizior-sources` (managed only, doklad musí patřit
aktuální firmě) vrací zdroje z `revizior_invoice_sources` i s hotovou absolutní
URL. Origin se skládá z `deployment.revizior.app_url` — nikdy ze vstupu
requestu, jinak by z odkazu na detailu dokladu vznikl open redirect na doméně
poskytovatele.

Odkazuje se jen `revision_report` (`/revize/zpravy/{uuid}`), protože jen ten má
dnes v ReviziORu obrazovku; ostatní typy se vypíšou jako text bez odkazu —
uhodnout cestu a poslat uživatele na 404 je horší než odkaz nemít.

Detail dokladu ukazuje sekci **Původ v ReviziORu** jen v managed režimu a jen
když seznam není prázdný; chyba načtení sekci tiše skryje, protože je to
doplněk, ne podmínka zobrazení faktury.

Živě ověřeno 2026-09-03: doklad 5 vrátil revizní zprávu RZ-2026-0002 s odkazem
`https://app.revizior.cz/revize/zpravy/<uuid>`.

## R6 je hotová

Zbývá R7 hardening (threat model, penetrační test, key rotation rehearsal,
zátěž, SLO a runbooky) a cutover: odstavení staré interní fakturace v ReviziORu
až po ověřeném provozu.
