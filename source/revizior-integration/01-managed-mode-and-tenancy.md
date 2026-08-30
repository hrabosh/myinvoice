# 1. Managed režim a tenantová oprávnění

## 1.1 Účel managed režimu

`revizior_managed` není nový produktový fork účetní logiky. Je to provozní režim jedné
instalace MyInvoice, ve kterém:

- tenanty a uživatelé vznikají výhradně přes důvěryhodnou ReviziOR integraci;
- běžný zákazník nepoužívá first-run wizard ani lokální registraci;
- UI ukazuje pouze moduly relevantní pro fakturaci revizních služeb;
- autentizace uživatele přichází jednorázovým SSO ticketem;
- globální provozní správa zůstává oddělena od tenantových rolí;
- aktualizace řídí CI/CD forku, ne aplikace samotná.

Standalone režim musí zůstat výchozí a zpětně kompatibilní.

## 1.2 Konfigurace

Doplnit typovanou konfigurační službu, ne roztroušené čtení `cfg()` ve Vue/action kódu.
Navržená konfigurace:

```php
'deployment' => [
    'mode' => 'standalone', // standalone | revizior_managed
    'public_name' => 'MyInvoice.cz',
    'revizior' => [
        'app_url' => null,
        'allowed_return_hosts' => [],
        'service_issuers' => [],
        'service_audience' => null,
        'sso_audience' => null,
        'service_jwks' => [],
        'sso_jwks' => [],
        'webhook_url' => null,
        'webhook_signing_key_id' => null,
        'webhook_signing_private_key_file' => null,
        'contract_version' => '1.0',
    ],
],
```

Produkční tajemství a privátní klíče nejsou v `cfg.sample.php`; sample obsahuje pouze názvy
voleb a bezpečné placeholdery. Cesty k souborům musí respektovat `RuntimePaths` nebo explicitní
read-only secrets mount.

Doplnit `DeploymentMode` enum/value object a `DeploymentCapabilities` službu:

```text
isStandalone()
isReviziorManaged()
allowsFirstRunSetup()
allowsLocalPasswordLogin()
allowsSelfUpdate()
allowsMyuctoUpgrade()
showsModule(Module $module)
```

Actions a router guardují bezpečnost. Frontend capability jen schovává navigaci; nesmí být
jedinou ochranou endpointu.

## 1.3 Chování podle režimu

| Funkce | `standalone` | `revizior_managed` |
|---|---:|---:|
| First-run setup | ano | pouze bootstrap platform admina mimo veřejný flow |
| Lokální přihlášení zákazníků | ano | ne; pouze SSO |
| Password reset zákazníků | ano | ne; účet spravuje ReviziOR |
| Passkey/TOTP zákazníků | ano | volitelně jako dodatečný faktor, ne primární identity source |
| In-app update | ano podle konfigurace | ne |
| Upgrade na MyÚčto | ano podle upstreamu | ne |
| Globální user admin | global admin | pouze break-glass platform admin |
| Supplier creation UI | global admin | ne; jen provisioning API |
| Supplier switcher | podle memberships | podle memberships, SSO vybere očekávaný supplier |
| Přijaté faktury/daně/mzdy | dle standalone produktu | výchozí skryto |
| Vydané faktury/klienti/ceník/banka | ano | ano |

Managed režim nesmí odstranit účetní funkce z databázového jádra ani jejich invarianty. Skrývá
je capability konfigurací, aby upstream merge a případné budoucí zapnutí nevyžadovaly fork
business logiky.

## 1.4 Bootstrap platformy

Managed instalace stále potřebuje jednoho provozního administrátora pro incidenty a diagnostiku.
Nesmí vzniknout přes veřejný setup wizard.

Doporučený postup:

```bash
php api/bin/revizior-bootstrap-platform-admin.php \
  --email=<ops-address> \
  --name=<ops-name>
```

- příkaz funguje jen v `revizior_managed` režimu;
- vyžaduje interaktivní potvrzení nebo explicitní `--confirm` v řízeném deployi;
- heslo se nezadává v argumentu; použije bezpečný interaktivní vstup nebo one-time activation;
- účet musí zapnout MFA před přístupem k produkčním tenantům;
- každé použití se auditně loguje;
- platform admin není automaticky členem supplierů a běžné supplier UI nevidí bez explicitního
  break-glass vstupu;
- break-glass přístup má krátkou platnost, důvod a audit.

CLI musí mít `.sh` i `.ps1`/`.cmd` wrapper podle pravidel repozitáře.

## 1.5 Mapování organizace na supplier

Každá ReviziOR organizace má právě jednu aktivní vazbu na MyInvoice supplier.

```text
ReviziOR organization UUID 1 ─── 1 revizior_organization_links 1 ─── 1 supplier.id
```

Provisioning používá externí UUID jako identitu. IČO, DIČ, název ani doména nejsou unikátním
integračním klíčem.

### Provisioning pravidla

- stejný organization UUID + stejný payload hash → vrátit stávající vazbu;
- stejný organization UUID + změněný payload → aktualizovat jen povolená živá supplier data;
- stejný organization UUID nesmí být navázán na druhý supplier;
- supplier už spojený s jinou organization UUID nelze převzít běžným API;
- konflikty vracejí stabilní `409 organization_link_conflict`;
- žádné automatické sloučení podle IČO;
- `supplier` nelze přes integrační API fyzicky smazat, pouze suspendovat/disconnectnout;
- znovupřipojení vyžaduje explicitní recovery postup a audit.

### Fakturační identita

Provisioning přenese dostupná data, ale stav zůstane `onboarding` do explicitního potvrzení v
MyInvoice UI. Povinné potvrzení:

- právní název a adresa;
- IČO, pokud existuje;
- DIČ, pokud existuje;
- stav plátce/neplátce/identifikované osoby podle současného MyInvoice modelu;
- bankovní účet;
- číselná řada;
- výchozí měna, splatnost a způsob ceny s/bez DPH;
- e-mailový profil.

Integrace nesmí nastavovat právně významný stav pouze heuristikou.

## 1.6 Oddělení globální a tenantové role

Současná globální hierarchie `admin > accountant > readonly` není dostatečná pro veřejnou
multi-tenant službu. `admin` má celoinstanční význam a nesmí být přidělen zákazníkovi pouze
proto, že je ownerem své firmy.

Cílový model:

```text
GlobalRole
  platform_admin
  regular_user

SupplierRole
  supplier_owner
  accountant
  readonly
```

Není nutné okamžitě přejmenovat stávající `users.role`, pokud by to vyvolalo velký refaktor.
Implementace však musí odlišit:

- **globální roli uživatele** z `users.role`;
- **efektivní roli v aktuálním supplieru** z `user_suppliers.role`;
- **integrační stav membership** z ReviziOR linku.

Rozšířit enum/constraint `user_suppliers.role` o `supplier_owner`. Současný ochranný princip,
že membership nesmí eskalovat na globální `admin`, zůstává. `supplier_owner` se interpretuje
jen v supplier-scoped permission policy.

## 1.7 Permission policy

Nerozšiřovat další dlouhé regex seznamy bez centralizace významu. Zavést permission enum/policy,
například:

```text
invoice.read
invoice.write
invoice.issue
invoice.cancel
payment.write
client.read
client.write
project.write
price_list.manage
supplier_settings.manage
supplier_members.manage
supplier_branding.manage
supplier_exports.read
platform_settings.manage
platform_users.manage
platform_update.manage
```

Matice:

| Permission | supplier_owner | accountant | readonly | platform_admin |
|---|---:|---:|---:|---:|
| invoice.read | ano | ano | ano | break-glass |
| invoice.write/issue | ano | ano | ne | break-glass |
| payment.write | ano | ano | ne | break-glass |
| client/project.write | ano | ano | ne | break-glass |
| price_list.manage | ano | ne nebo explicitní grant | ne | break-glass |
| supplier_settings/branding.manage | ano | ne | ne | break-glass |
| supplier_members.manage | ano | ne | ne | break-glass |
| platform_settings/users/update | ne | ne | ne | ano |

Action používá policy + aktuální supplier context. Role middleware může zůstat hrubou první
branou, ale citlivá action musí ověřit konkrétní permission. Frontend čte permission/capability
response, ne odhad z názvu role.

## 1.8 Synchronizace uživatele

ReviziOR je identity authority pro zákaznické účty.

Vazba:

```text
ReviziOR user UUID → MyInvoice user ID + supplier membership
```

### Upsert

Payload obsahuje:

- ReviziOR user UUID;
- e-mail a zobrazované jméno;
- organization UUID;
- tenantovou roli;
- `active`;
- source version / updated timestamp.

Pravidla:

- externí UUID je primární integrační identita;
- e-mail lze použít k bezpečnému připojení existujícího účtu pouze podle explicitní politiky;
- kolize e-mailu s jiným externím uživatelem vrací 409, ne tiché sloučení;
- jedno MyInvoice user ID může mít membership ve více suppliers, pokud stejný člověk patří do
  více ReviziOR organizací;
- změna e-mailu aktualizuje účet až po ověření, že nevznikne kolize;
- roli vždy nahradí aktuální authoritative role ReviziORu;
- provisioning nesmí nikdy z payloadu nastavit globální admin roli.

### Revoke

`DELETE .../users/{userUuid}`:

- označí integrační membership jako neaktivní nebo ji bezpečně odstraní;
- zvýší `session_version`/revocation marker, aby existující session ztratila tenantový přístup;
- zachová auditní stopu a `created_by` reference historických dokladů;
- nesmaže uživatele, pokud má jiné aktivní memberships;
- další SSO do organizace odmítne.

## 1.9 SSO a supplier selection

Při SSO je organization UUID součástí podepsaného ticketu. Po ověření se přeloží přes
`revizior_organization_links`; MyInvoice nesmí věřit numerickému supplier ID z browseru.

- aktivní link + aktivní membership jsou povinné;
- SSO nastaví aktuální supplier server-side;
- případný `X-Supplier-Id` z prohlížeče po přihlášení stále prochází stávající supplier access
  kontrolou;
- target invoice/client musí patřit vybranému supplieru;
- uživatel bez membership nedostane fallback do nejnižšího supplieru;
- v managed mode odstranit zpětně kompatibilní chování „uživatel bez memberships = přístup bez
  omezení“ pro zákaznické účty; výjimka může zůstat jen explicitnímu platform adminovi.

Toto je kritická změna: standalone režim může zachovat legacy fallback, managed režim musí být
fail-closed.

## 1.10 Managed navigation a branding

Zavést modulové capabilities v backend response, například `/api/auth/me`:

```json
{
  "deploymentMode": "revizior_managed",
  "productName": "ReviziOR Fakturace",
  "modules": {
    "salesInvoices": true,
    "clients": true,
    "projects": true,
    "priceList": true,
    "bank": true,
    "documents": true,
    "purchaseInvoices": false,
    "tax": false,
    "payroll": false,
    "logbook": false,
    "selfUpdate": false,
    "myuctoUpgrade": false
  },
  "returnUrl": "https://app.revizior.cz/fakturace"
}
```

Frontend:

- používá stávající design language;
- zobrazí „ReviziOR Fakturace“ a bezpečnou akci **Zpět do ReviziORu**;
- nesmí renderovat raw `return_to` z query stringu;
- skryté routy mají i route guard/API guard, ne jen chybějící menu;
- invoice detail může zobrazit odkaz na zdrojovou revizi pouze z ověřené externí reference a
  nakonfigurovaného base URL;
- copyright/licenční stránka zachová MIT notice.

Branding se mění jen v uživatelských textech, logu a assetech. Namespace, env proměnné, cookie,
localStorage, Redis prefixy, ISDOC namespace a interní identifikátory zůstávají `MyInvoice`.

## 1.11 First-run a lokální login

V managed mode:

- veřejné `/api/auth/setup*` vrací `404` nebo stabilní `managed_setup_disabled`;
- login/password reset pro zákaznické účty je vypnutý;
- SSO endpoint je jediná běžná vstupní cesta;
- platform admin login je oddělený, explicitně konfigurovaný a chráněný MFA/IP policy;
- uživatel vytvořený ReviziORem nemusí mít použitelné lokální heslo;
- SSO nesmí automaticky obcházet existující session lock u jiné identity; vytvoří nebo rotuje
  správnou session podle bezpečnostní politiky.

## 1.12 Audit

Nové audit akce:

```text
revizior.organization.provisioned
revizior.organization.updated
revizior.organization.suspended
revizior.user.linked
revizior.user.role_changed
revizior.user.revoked
revizior.sso.succeeded
revizior.sso.denied
revizior.break_glass.started
revizior.break_glass.ended
```

Audit nesmí obsahovat ticket, service assertion, celé payloady ani citlivé údaje. Uvádí
request ID, organization UUID, interní cílové ID, actor/source a změnu stavu/role.

## 1.13 Akceptační kritéria

- standalone instalace se chová stejně jako před změnou;
- managed instalaci nelze veřejně inicializovat setup wizardem;
- opakovaný provisioning stejné organization UUID vrátí stejný supplier;
- owner ReviziOR organizace dostane `supplier_owner`, nikoli globální `admin`;
- `supplier_owner` nemůže otevřít globální users/update/config endpointy;
- membership organizace A nelze použít pro supplier B;
- zákaznický uživatel bez membership v managed mode nemá přístup k žádnému supplieru;
- revoke ukončí budoucí i již existující tenantový přístup podle session policy;
- skrytý modul nelze otevřít přímou URL ani zavolat jeho endpoint bez permission;
- MyInvoice interní identifikátory a MIT attribution zůstávají zachovány.
