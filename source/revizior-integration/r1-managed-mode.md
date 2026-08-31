# R1 managed mode — implementovaný základ

> Stav: první aditivní slice hotový; permission/provisioning část R1 zůstává otevřená
> Datum: 2026-08-31

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

- migrace `0151` pro `supplier_owner` a navazující permission policy;
- oddělení globální platform role od efektivní supplier role ve všech action guardech;
- managed fail-closed chování uživatele bez membership;
- session version/revocation;
- bezpečný platform bootstrap CLI a cross-platform wrapper;
- SSO vstupní endpoint (v roadmapě spolu se service auth v R2).

Dokud nejsou tyto body hotové, managed mode je integrační základ, ne produkční cutover gate.

## Lokální Docker propojení

`docker-compose.revizior-local.yml` připojí aplikační kontejner zároveň k jeho privátní DB
síti a k externí síti ReviziORu. Na externí síti má alias `revizior-invoice.local`, takže consumer
použije base URL `http://revizior-invoice.local` bez závislosti na host portu. Wrappery
`cmd/docker-revizior-local.sh` a `.ps1` stack sestaví, spustí a ověří health request přímo
z backend PHP kontejneru. Prohlížeč používá výchozí lokální port `8082`.

## Ověření slice

- focused PHPUnit: 30 testů / 107 assertions;
- celý PHPUnit: 2 113 testů / 5 452 assertions, bez failure;
- focused PHPStan level 0: bez chyb;
- frontend PWA testy: 62/62;
- `pnpm build`: type-check i produkční Vite build zelené;
- manuál: HTML 42 kapitol a PDF export zelené.
