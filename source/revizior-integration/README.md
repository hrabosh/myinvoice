# ReviziOR managed integrace

> **Stav:** 🟢 R0–R6 hotové; inzerovány všechny schopnosti kromě `priceResolution`, `proforma`, `creditNote` a `partialPayments` (2026-09-03); zbývá R7 hardening a cutover
> **Rozhodnutí:** 2026-08-30  
> **Provider repozitář:** `hrabosh/myinvoice`  
> **Consumer repozitář:** `hrabosh/backend`  
> **Produkční host:** `https://fakturace.revizior.cz`  
> **Consumer-side plán:** `hrabosh/backend/docs/features/revizior-invoicing/`

Tato složka je kanonické implementační zadání pro provoz MyInvoice jako managed fakturačního
modulu ReviziORu. Nejde o obecný rebranding MyInvoice ani o přenos proprietárních částí
MyÚčto. Výchozím a jediným licenčním základem je tento MIT fork MyInvoice.

## Cílový model

```text
ReviziOR (`app.revizior.cz`)
  ├── organizace, uživatelé, klienti, revize a protokoly
  ├── pravidlo, co se má fakturovat a v jakém množství
  └── lokální read model vazeb a stavů faktur
            │
            │ krátkodobý podepsaný service assertion
            │ jednorázový browser SSO ticket
            ▼
MyInvoice (`fakturace.revizior.cz`)
  ├── jeden `supplier` tenant pro každou ReviziOR organizaci
  ├── synchronizovaní uživatelé a klienti podle ReviziOR UUID
  ├── ceník a klientské cenové výjimky
  ├── faktury, proformy, dobropisy, platby a upomínky
  ├── DPH, měny, číselné řady, PDF, QR a exporty
  └── transakční event outbox zpět do ReviziORu
```

Jedna managed instalace obsluhuje více nesouvisejících zákazníků. Tenant isolation proto není
pouze UX pravidlo, ale bezpečnostní hranice se samostatnými integračními a penetračními testy.

## Zásadní rozhodnutí

1. **Stávající MyInvoice doména zůstává zdrojem pravdy.** Integrace orchestrace využije
   současné klientské, ceníkové, fakturační, platební, PDF a daňové služby; nesmí vytvořit
   paralelní jednodušší výpočet faktury.
2. **ReviziOR endpointy mají samostatnou autentizaci.** Nejsou dostupné přes běžné PAT tokeny
   ani jejich allowlist.
3. **Prohlížeč nikdy nedostane service credential.** Přechod do UI řeší jednorázový SSO ticket
   a vlastní host-only session MyInvoice.
4. **Globální `admin` není tenantová role.** Vznikne explicitní `supplier_owner`, která může
   spravovat jen konkrétní supplier, jeho členy, ceník a fakturační nastavení.
5. **Idempotence je persistentní.** Retry po timeoutu nebo dvojklik nikdy nezaloží druhého
   klienta, suppliera, draft ani přílohu.
6. **Události se neposílají best-effort HTTP voláním z invoice transakce.** Vzniknou v
   transakčním outboxu a doručují se opakovaně.
7. **Managed režim je aditivní overlay.** Zachovat namespace `MyInvoice\\`, proměnné
   `MYINVOICE_*`, storage/session identifikátory, obecné API a upstream mergeovatelnost.
8. **Branding neruší MIT attribution.** Zákazník vidí „ReviziOR Fakturace“, licence a původ
   zůstávají dohledatelné.
9. **In-app update a upgrade na MyÚčto jsou v managed režimu vypnuté.** Produkci nasazuje
   řízená CI/CD pipeline forku.
10. **Neznámá hodnota se nedoplňuje odhadem.** Zejména plátcovství DPH se nesmí určit pouze z
    přítomnosti DIČ; onboarding vyžaduje explicitní potvrzení.

## Dokumenty

| Dokument | Obsah |
|---|---|
| [`01-managed-mode-and-tenancy.md`](01-managed-mode-and-tenancy.md) | Managed deployment, tenant mapping, `supplier_owner`, UI capabilities a zdroje pravdy |
| [`02-service-api-sso-and-events.md`](02-service-api-sso-and-events.md) | Service assertion, endpointy v1, idempotence, SSO, přílohy a podepsaný event kontrakt |
| [`03-database-security-and-operations.md`](03-database-security-and-operations.md) | Databázové tabulky, transakce, replay ochrana, threat model, provoz, backupy a upstream |
| [`04-roadmap-and-acceptance.md`](04-roadmap-and-acceptance.md) | Fázované PR, využití existujícího kódu, testovací matice, cutover a Definition of Done |
| [`r0-baseline.md`](r0-baseline.md) | Přesné upstream/fork commity, ověřovací příkazy, characterization mapa a další číslo migrace |
| [`r1-managed-mode.md`](r1-managed-mode.md) | Implementovaný deployment/capability slice R1, konfigurace, ověření a zbývající permission práce |
| [`r2-service-foundation.md`](r2-service-foundation.md) | Implementovaný service assertion, replay guard, capabilities probe, konfigurace a další R2 slice |
| [`r2-organization-provisioning.md`](r2-organization-provisioning.md) | Atomický a idempotentní provisioning organizace, suppliera a prvního ownera |
| [`r2-tenant-synchronization.md`](r2-tenant-synchronization.md) | Organization update, user upsert/revoke, session version a cross-repo capability gate |
| [`licensing-decision.md`](licensing-decision.md) | MIT provenance, third-party gate a bezpečnostní volba JOSE/JWT knihovny |
| [`contract/v1/`](contract/v1/) | Kanonické cross-repo JSON fixtures a jejich deterministické SHA-256 hashe |

## Co se nemá implementovat podruhé

ReviziOR integrační vrstva nesmí duplikovat:

- `CreateInvoiceAction`/`InvoiceRepository` business pravidla;
- `InvoiceDefaults`, `InvoiceCalculator`, `InvoiceMath` a VAT klasifikaci;
- klienty, kontakty a supplier scope;
- `PriceListItemResolver` a zákaznické cenové výjimky;
- číselné řady;
- PDF/QR/ISDOC/Pohoda export;
- proformy, doklady k platbě, dobropisy a storna;
- platby, bankovní matching, odesílání a upomínky.

Integrační use-case vytvoří a validuje vstup, ale vlastní zápis deleguje na znovupoužitelné
application služby vytažené z existujících actions, pokud dnes orchestrace žije přímo v action.
Refaktor musí být charakterizačně otestovaný a malý; integrační API nesmí volat vlastní HTTP API
přes localhost.

## Cílová Definition of Done

- ReviziOR organizace se provisionuje právě jednou a mapuje se na právě jeden supplier;
- ReviziOR uživatel vstoupí bez druhého hesla pouze do supplierů, ke kterým má aktivní vazbu;
- `supplier_owner` nemá žádný přístup ke globální správě instalace ani jiným tenantům;
- klient se upsertuje podle externího UUID, ne podle názvu nebo IČO;
- invoice draft je idempotentní a používá existující MyInvoice výpočty a snapshoty;
- vydané ReviziOR PDF lze bezpečně připojit jako idempotentní přílohu;
- každá důležitá změna dokladu vytvoří sekvenovanou událost v outboxu;
- výpadek ReviziORu neztratí událost a výpadek MyInvoice nevytvoří duplicitní doklad;
- managed UI je přehledné, ale společné jádro zůstává mergeovatelné s upstream MyInvoice;
- obecný standalone režim a jeho testy nejsou managed integrací regresně rozbité.
