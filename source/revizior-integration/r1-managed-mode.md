# R1 managed mode — implementovaný základ

> Stav: deployment, tenant membership a základ permission policy hotový; bootstrap/session část R1 zůstává otevřená
> Datum: 2026-09-01

## Hotovo

- typovaný `DeploymentMode` (`standalone` / `revizior_managed`);
- jediná `DeploymentCapabilities` služba pro backend i payload UI;
- bezpečné startovní ověření `public_name`, HTTPS `app_url` a volitelného exact host allowlistu;
- ENV konfigurace `MYINVOICE_DEPLOYMENT_MODE`, `MYINVOICE_PUBLIC_NAME`,
  `MYINVOICE_REVIZIOR_APP_URL` a `MYINVOICE_REVIZIOR_ALLOWED_RETURN_HOSTS`;
- standalone zůstává výchozí a všechny moduly/cesty má povolené;
- managed HTTP guard vrací stabilní `404` kódy pro veřejný setup, lokální login/passkey login,
  reset hesla, self-update a MyÚčto upgrade;
- managed HTTP guard blokuje vypnuté moduly přijatých faktur, daní a knihy jízd;
- `/api/auth/setup-status` a `/api/auth/me` vracejí `deploymentMode`, `productName`, `modules`
  a pouze konfigurovaný `returnUrl`;
- Vue router a navigace používají stejnou capability mapu, včetně přímých URL guardů;
- managed shell zobrazuje „ReviziOR Fakturace“, bezpečný návrat do ReviziORu a samostatnou
  vstupní stránku namísto lokálního login/setup/reset UI;
- české a anglické překlady, MIT/GitHub attribution zůstává v patičce.
- idempotentní migrace `0151` rozšiřuje výhradně `user_suppliers.role` o
  `supplier_owner`; globální `users.role` se nemění;
- `supplier_owner` se v existujícím business RBAC chová jako `accountant`, ale
  nikdy neprojde globálním admin fallbackem `/api/admin/*`;
- request a `/api/auth/me` drží odděleně `platform_role` a `supplier_role`;
- managed non-admin bez membershipu failne zavřeně i s PAT vázaným na supplier,
  zatímco standalone zachovává legacy přístup bez membership řádků;
- uživatel bez managed membershipu stále načte vlastní session stav a může se
  odhlásit, ale seznam supplierů je prázdný a supplier-scoped endpointy vracejí 403.
- centralizovaná permission matice používá stabilní klíče z integrační specifikace
  a `/api/auth/me` je vrací frontendu;
- `supplier_owner` může spravovat ceník, údaje a číselnou řadu aktuální firmy a
  její branding, zatímco `accountant` tyto owner-only operace nedostane;
- Vue navigace a route guard pro ceník používají `price_list.manage` místo odhadu
  z legacy role `admin`.

## Konfigurace

```php
'deployment' => [
    'mode' => 'revizior_managed',
    'public_name' => 'ReviziOR Fakturace',
    'revizior' => [
        'app_url' => 'https://app.revizior.cz/fakturace',
        'allowed_return_hosts' => ['app.revizior.cz'],
    ],
],
```

`app_url` je autoritativní návratová adresa. UI nikdy nepoužívá `return_to` z query stringu.
Managed konfigurace bez absolutní HTTPS adresy failne při startu aplikace.

## Otevřené části R1

- tenant-scoped správa členů nad `supplier_members.manage` (stávající
  `/api/admin/users/*` zůstává správně globální a ownerovi se neotevírá);
- dokončení oddělení globální platform role od efektivní supplier role ve všech action guardech;
- session version/revocation;
- bezpečný platform bootstrap CLI a cross-platform wrapper;
- SSO vstupní endpoint (v roadmapě spolu se service auth v R2).

Dokud nejsou tyto body hotové, managed mode je integrační základ, ne produkční cutover gate.

## Kontrola MyÚčto upstreamu

K 2026-09-01 (`radekhulan/myucto` commit `bcbc4ea`) má MyÚčto obecnější dynamické role,
permission catalog a fail-closed membership model z migrace `1074`. Tento fork je nepřebírá
hromadně: znamenalo by to velký refaktor sdílených action guardů a konfliktní databázové změny.
R1 proto zatím přidává lokalizovaný `supplier_owner` overlay nad stávajícími legacy rolemi;
navazující permission policy musí zachovat kompatibilní permission klíče, aby budoucí merge
nevyžadoval další paralelní autorizační model.

## Lokální Docker propojení

`docker-compose.revizior-local.yml` připojí aplikační kontejner zároveň k jeho privátní DB
síti a k externí síti ReviziORu. Na externí síti má alias `revizior-invoice.local`, takže consumer
použije base URL `http://revizior-invoice.local` bez závislosti na host portu. Wrappery
`cmd/docker-revizior-local.sh` a `.ps1` stack sestaví, spustí a ověří health request přímo
z backend PHP kontejneru. Prohlížeč používá výchozí lokální port `8082`.

## Ověření slice

- focused R1 PHPUnit: 38 testů / 233 assertions;
- unit + architecture PHPUnit: 1 704 testů / 5 516 assertions, bez failure
  (67 DB-dependent skipů, 1 existující deprecation);
- focused PHPStan level 0: bez chyb;
- frontend PWA testy: 63/63;
- `pnpm build`: type-check i produkční Vite build zelené;
- migrace `0151` aplikovaná přes `migrate.php`; opakovaný běh bez pending migrací;
- manuál: HTML 42 kapitol a PDF export zelené.
