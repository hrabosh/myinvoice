# 4. Implementační roadmapa a akceptační kritéria

## 4.1 Způsob realizace

Nevytvářet jeden obří PR. Každá fáze je samostatně nasaditelná, backward-compatible se
standalone režimem a má odpovídající consumer PR v `hrabosh/backend`, pokud jej potřebuje.

Doporučené větve:

```text
chore/revizior-r0-baseline
feature/revizior-managed-mode
feature/revizior-service-auth-provisioning
feature/revizior-client-price-invoice-api
feature/revizior-sso
feature/revizior-event-outbox
feature/revizior-attachments-managed-ui
chore/revizior-production-hardening
```

Každý PR obsahuje:

- jasný rozsah a mimo-scope;
- schema/API compatibility;
- migraci + čistý/upgrade test, pokud mění DB;
- PHP unit/integration/architecture testy;
- Vue type-check/build a obě locale, pokud mění UI;
- kontraktní fixtures;
- security/tenant testy;
- deploy pořadí a rollback;
- odkaz na související ReviziOR PR;
- aktualizaci manuálu až po dokončené user-visible slice.

`VERSION` a `CHANGELOG.md` se v běžném implementačním PR nemění; release provede maintainer.

## 4.2 R0 — baseline, provenance a kontrakt

### Cíl

Zmrazit výchozí stav a odstranit nejasnosti dříve, než vznikne integrační kód.

### Úkoly

1. Zaznamenat upstream remote/commit a aktuální fork commit.
2. Spustit úplnou PHP test suite, PHPStan, frontend test/build a migrace na čisté DB.
3. Přidat cross-repo contract fixtures `specVersion=1.0` bez runtime endpointů.
4. Přidat validační CI job pro `api/openapi-revizior-integration.yaml`.
5. Provést licenční provenance audit:
   - root `LICENSE` vs. `api/composer.json` metadata;
   - third-party dependencies;
   - žádný proprietární MyÚčto kód;
   - rozhodnutí zaznamenat v repu.
6. Přidat characterization testy pro:
   - create/update client;
   - create invoice draft a totals;
   - price resolution/customer override;
   - issue/send/payment/cancel/credit note event points;
   - supplier scope a effective role.
7. Vybrat a bezpečnostně schválit JOSE/JWT knihovnu; nepřidávat vlastní kryptografii.
8. Ověřit další volné číslo migrace pod 1000.

### Výstupy

```text
source/revizior-integration/contract/v1/*.json (nebo api/tests fixtures)
api/openapi-revizior-integration.yaml
source/revizior-integration/licensing-decision.md
```

### Akceptace

- baseline je zelený a opakovatelný;
- fixture hash se shoduje s `hrabosh/backend`;
- licenční metadata nejsou ponechána v nevysvětleném rozporu;
- žádná runtime funkce se ještě nemění.

## 4.3 R1 — managed mode a permission model

### Cíl

Zavést aditivní provozní režim bez integračního API.

### Backend úkoly

- `DeploymentMode` + `DeploymentCapabilities`;
- bezpečné config schema a validace startu;
- managed guard pro setup/login/reset/update/MyÚčto upgrade;
- platform bootstrap CLI + cross-platform wrapper;
- oddělení global vs. supplier permission;
- rozšíření `user_suppliers.role` o `supplier_owner`;
- permission policy a action guardy pro:
  - supplier settings;
  - price list;
  - members;
  - branding;
  - platform settings/users/update;
- managed fail-closed supplier access pro user bez memberships;
- session version/revocation mechanismus.

### Frontend úkoly

- deployment/modules/permissions v `/api/auth/me`;
- managed logo/název a bezpečný návrat do ReviziORu;
- capability-driven navigation;
- skrytí setup/update/MyÚčto a nerelevantních modulů;
- route guards;
- CZ + EN překlady;
- zachování MIT/licenční informace.

### Testy

- standalone parity;
- managed setup/login/update denial;
- supplier owner permission matrix;
- supplier owner nemůže platform admin endpoint;
- managed user bez membership = deny;
- revoked session;
- frontend route/nav capabilities.

### Akceptace

- standalone UI/API se chovají stejně;
- managed instalace nemá veřejný setup ani self-update;
- tenant owner spravuje pouze svůj supplier;
- globální admin není udělen přes membership.

## 4.4 R2 — service auth, replay ochrana a provisioning

### Cíl

Vytvořit bezpečný server-to-server kanál a právě-jednou provisioning organizace/uživatelů.

### Databáze

- `revizior_organization_links`;
- `revizior_user_links`;
- `revizior_idempotency_keys`;
- `revizior_security_nonces`;
- potřebné constraints/indexy;
- migration integration tests.

### Kód

```text
ReviziorServiceTokenVerifier
ReviziorReplayGuard
ReviziorScopePolicy
ReviziorServiceAuthMiddleware
CanonicalPayloadHasher
ReviziorIdempotencyRepository
ReviziorOrganizationLinkRepository
ReviziorUserLinkRepository
ProvisionReviziorOrganizationAction/Service
UpdateReviziorOrganizationAction/Service
UpsertReviziorUserAction/Service
RevokeReviziorUserAction/Service
ReviziorCapabilitiesAction
```

- routy pod `/api/integrations/revizior/v1`;
- request body/rate limits;
- stable response/error mapper;
- platform subject vs. tenant subject policy;
- activity logging bez payloadu;
- OpenAPI + fixtures.

### Transakce

Provisioning musí atomicky vytvořit supplier, organization link, owner user/link, membership,
idempotent response a audit. Retry po ztracené response vrátí stejný supplier.

### Testy

- valid/invalid/expired/wrong issuer/audience/alg/kid;
- replay jti i po restartu cache;
- missing/insufficient scope;
- subject/path mismatch;
- stejný idempotency payload;
- conflict jiného payloadu;
- souběžný provisioning;
- duplicate supplier/organization conflicts;
- user e-mail collision;
- role `admin` v payloadu odmítnuta;
- rollback u chyby v posledním kroku;
- dva tenanty.

### Akceptace

- organization UUID má právě jeden supplier;
- owner je `supplier_owner`, ne global admin;
- revoke invaliduje membership/session;
- `/capabilities` hlásí pouze skutečně ready funkce;
- běžný PAT integrační routy neotevře.

## 4.5 R3 — client upsert, price resolution a invoice draft

### Cíl

Využít existující MyInvoice doménu přes společné application služby a vytvořit atomický
idempotentní draft.

### Nejdříve společný refaktor

- `ClientWriter` z create/update client actions;
- `PriceResolutionService` nad existujícím resolverem;
- `InvoiceDraftCreator` z `CreateInvoiceAction` orchestrace;
- existující actions přepsat na nové služby bez změny response/chování;
- characterization testy musí projít červeně proti záměrně rozbitému refaktoru a zeleně po
  opravě.

### Databáze

- `revizior_client_links`;
- `revizior_invoice_links`;
- `revizior_invoice_sources`;
- případné external line metadata;
- unique/FK tenant guardy.

### Endpointy

```text
PUT  /organizations/{organizationUuid}/clients/{clientUuid}
POST /organizations/{organizationUuid}/prices/resolve
POST /organizations/{organizationUuid}/invoice-drafts
GET  /organizations/{organizationUuid}/invoices/{externalInvoiceKey}
```

### Fakturační pravidla

- všechny částky/DPH/exchange rate přes existující MyInvoice služby;
- decimal strings v kontraktu, žádné float jako source of truth;
- price resolver používá klientské overrides;
- nulová/chybějící cena se nerozhodne potichu;
- external client/invoice key je tenant-scoped;
- invoice + link + source + idempotent response jsou jedna transakce;
- běžný invoice bez ReviziOR linku není ovlivněn.

### Testy

- existing UI/API parity po service extraction;
- client UUID upsert/unchanged/update;
- žádné merge podle IČO;
- client ID cizího supplieru;
- price base/customer override/missing currency/missing code;
- invoice VAT/rounding/reverse charge/prices include VAT fixtures;
- exchange rate path;
- duplicate request/souběh/timeout replay;
- stejné key + jiné body 409;
- rollback faktury/linku;
- reconciliation snapshot.

### Akceptace

- integrace netvoří vlastní invoice math;
- stejný request vytvoří právě jednu fakturu;
- cizí client/invoice nelze použít;
- response totals odpovídají současnému editoru/API;
- standalone create invoice používá stejný refaktorovaný use-case.

## 4.6 R4 — browser SSO

### Cíl

Uživatel přejde z ReviziORu přímo do svého draftu bez druhého hesla a bez sdílené cookie.

### Kód

```text
ReviziorSsoTicketVerifier
ReviziorSsoReplayGuard (sdílený nonce storage)
ReviziorSsoTargetPolicy
ReviziorReturnUrlPolicy
ReviziorSsoAction
ManagedSessionIssuer
```

- samostatné audience/key config;
- one-time jti;
- organization/user link + membership reload;
- target resource ownership lookup;
- session ID rotation a supplier selection;
- bezpečné managed error pages;
- proxy/app log redaction query stringu;
- `Back to ReviziOR` přes session uloženou schválenou return URL, ne raw query.

### Testy

- valid ticket;
- expired/not-yet-valid/wrong issuer/audience/purpose/alg/kid;
- replay;
- unknown/suspended org;
- inactive/revoked user;
- target invoice jiného supplieru;
- `//evil`, absolute URL, encoded traversal, unexpected query;
- return host/look-alike scheme;
- session fixation/rotation;
- managed user bez membership;
- standalone auth unaffected.

### Akceptace

- úspěšný flow končí `303` v přesném draftu;
- druhé použití selže;
- browser nikdy nedostane service credential;
- cookie je host-only pro `fakturace.revizior.cz`;
- revoke ukončí přístup existující session podle session version.

## 4.7 R5 — transakční event outbox

### Cíl

Spolehlivě propagovat stav linked faktury do ReviziORu bez vazby business transakce na
okamžitou dostupnost callbacku.

### Databáze

- `revizior_event_outbox`;
- `event_sequence` na invoice linku (již v R3 nebo migrace R5);
- indexy pro claim/backlog;
- žádné mutable payload regeneration.

### Kód

```text
ReviziorInvoiceEventPublisher
ReviziorEventSnapshotBuilder
ReviziorOutboxRepository
ReviziorEventSigner
ReviziorOutboxDispatcher
ReviziorIntegrationStatusCommand
ReviziorRetryEventCommand
```

Event hooky přidat do společných application služeb, nikoli jen actions:

- draft create/update;
- issue;
- send;
- payment create/delete/match;
- partial/full paid;
- overdue;
- cancel;
- credit note;
- draft delete.

Publisher je no-op pro invoice bez active ReviziOR linku.

### Worker

- parallel-safe claim;
- retry/backoff/jitter/Retry-After;
- 2xx success;
- retryable status matrix;
- dead-letter + alert;
- metrics/status CLI;
- cross-platform cron wrappers;
- secrets/signing key rotation.

### Testy

- outbox ve stejné transakci;
- rollback nevytvoří event;
- callback failure nevrátí invoice transaction;
- event immutable snapshot;
- monotónní sequence při souběhu;
- duplicate delivery očekávaná;
- retry 429/5xx/network;
- non-retryable 4xx;
- worker crash/stale claim;
- signed fixture ověřitelná backend testem;
- všechny mutation channels produkují event.

### Akceptace

- žádný relevantní linked invoice stav není závislý na konkrétní HTTP action;
- restart mezi commit a delivery event neztratí;
- backlog je viditelný/alertovaný;
- event payload neobsahuje zbytečné PII.

## 4.8 R6 — přílohy a dokončení managed UI

### Cíl

Bezpečně připojit vydaný revizní dokument a dokončit souvislý uživatelský flow.

### Databáze/kód

- `revizior_attachment_links`;
- streaming upload action/service;
- digest/MIME/magic/size/temporary storage guard;
- idempotence external attachment key;
- invoice source read model;
- managed UI odkaz **Otevřít revizi v ReviziORu**;
- onboarding completion event;
- final module navigation/capabilities;
- manuál pro managed uživatele a provoz.

### Testy

- valid PDF;
- fake Content-Type/non-PDF/oversize/truncated/digest mismatch;
- path traversal v názvu;
- retry stejného digestu;
- conflict jiného digestu;
- attachment cizího supplieru;
- source URL/target allowlist;
- frontend CZ/EN/build;
- UI neukazuje disabled modules ani platform settings.

### Akceptace

- vydaný report PDF je připojen právě jednou;
- upload nebufferuje neomezené body v RAM;
- zdrojový odkaz nikdy nevede na cizí tenant;
- uživatel se může bezpečně vrátit mezi oběma aplikacemi.

## 4.9 R7 — production hardening

### Bezpečnost

- formální threat-model review;
- penetrační test service API, SSO, target/return URL, attachments a tenant IDOR;
- dependency/SBOM scan;
- key rotation rehearsal;
- break-glass audit;
- proxy log redaction ověření;
- rate/body limit load test.

### Provoz

- staging s oddělenou DB/storage/keys/callback;
- CI/CD bez in-app updateru;
- liveness/readiness/capabilities smoke;
- outbox SLO/alerts;
- DB PITR a document backup;
- restore test s vypnutým mailem/webhooky;
- incident runbook rehearsal;
- upstream merge rehearsal;
- licenční/provenance gate uzavřený.

### Výkon

Testovat minimálně:

- 50 souběžných idempotentních create-draft pokusů na stejný key;
- 100 různých draftů v krátké dávce;
- webhook backlog 10 000 eventů;
- attachment max size;
- více paralelních outbox workers;
- dva a více tenantů se stejnými klientskými identifikátory.

Čísla nejsou produkční SLO sama o sobě; ověřují absence race, lock contention a memory leak.

### Akceptace

- žádný otevřený critical/high tenant/auth nález;
- key rotation nevyžaduje odstávku;
- restore dosáhne schváleného RPO/RTO;
- provider lze nasadit před consumerem bez regresí;
- standalone režim a upstream merge zůstávají funkční.

## 4.10 Cross-repo deploy pořadí

Pro každou capability:

1. merge/deploy MyInvoice backward-compatible provider změny;
2. spustit migrace a readiness;
3. ověřit `/capabilities` ze staging ReviziORu;
4. merge/deploy `hrabosh/backend` consumer podporu defaultně vypnutou;
5. zapnout interní/test organization;
6. projít E2E a observabilitu;
7. postupný rollout dalším organizacím;
8. teprve po stabilizaci odstranit starou interní fakturaci v backendu.

Rollback consumeru nesmí vyžadovat rollback provider DB. Provider musí tolerovat nevyužitou
novou capability. Při rollbacku provideru se nejprve vypne capability/traffic z ReviziORu.

## 4.11 Contract fixtures

Provider a consumer sdílejí významově identické fixtures:

```text
capabilities.json
provision-request.json
provision-response.json
organization-update.json
user-upsert.json
client-upsert-request.json
client-upsert-response.json
price-resolve-request.json
price-resolve-response.json
invoice-draft-request.json
invoice-snapshot.json
errors/*.json
events/*.json
```

CI test:

- fixture JSON schema/OpenAPI validace;
- canonical hash očekávaná hodnota;
- decimal/minor units;
- unknown optional fields tolerance;
- required field removal failure;
- provider serialization == fixture;
- consumer deserialization == fixture.

V1 může aditivně přidat volitelné pole. Povinné pole, změna typu nebo významu vyžaduje v2.

## 4.12 Testovací matice

### Unit

- deployment capabilities;
- permission matrix;
- canonical hasher;
- JWT/JWS claim/algorithm/key selection;
- target/return policy;
- status/event snapshot mapping;
- idempotency comparison;
- retry schedule;
- attachment validators.

### Integration

- všechny nové repository + constraints;
- clean/upgrade/idempotent migrations;
- transaction rollback;
- concurrency;
- existing business services used from integration;
- supplier ownership joins;
- session revocation;
- outbox claim/retry.

### Architecture

- integration Action nesmí používat raw PDO mimo repository/service pattern;
- žádná kopie invoice math ve `Integration/Revizior` namespace;
- integration routy mají service auth middleware;
- managed platform endpoints mají global permission;
- nový SQL používá prepared statements;
- žádný `v-html` pro integrační user content.

### Frontend

- capability navigation;
- managed route guards;
- return link;
- onboarding status;
- obě locale;
- `pnpm build`.

### E2E cross-repo

1. provision owner;
2. onboarding;
3. upsert klient;
4. resolve cenu;
5. create draft;
6. SSO do editoru;
7. upload reportu;
8. issue/send;
9. partial/full payment;
10. callback retry/out-of-order handling na consumeru;
11. revoke user;
12. tenant B IDOR pokusy.

## 4.13 Definition of Done pro každý MyInvoice PR

```bash
cd api && php vendor/bin/phpunit
cd api && php vendor/bin/phpstan analyse
cd web && pnpm type-check
cd web && pnpm build
php api/bin/migrate.php --status
# při manuálu:
php tools/generateManualHtml.php
php tools/exportManualToPdf.php
```

Dále:

- žádná reálná PII v tests/fixtures;
- syntetické IČO/e-mail/bank data;
- nový/změněný API kontrakt v OpenAPI + fixtures;
- migration číslo pod 1000 a idempotentní;
- security test pro auth, tenant, replay a input;
- user-visible změna má CZ/EN a manuál;
- žádná změna `VERSION`/release changelogu v běžném PR;
- žádný generated `web/dist`/manual artefakt v commitu;
- commit message česky v conventional style;
- PR odkazuje na odpovídající `hrabosh/backend` práci a deployment order.

## 4.14 Finální akceptační scénář

1. Managed instalace startuje pouze s validní service/SSO/webhook konfigurací.
2. ReviziOR provisionuje organization a ownera; vznikne jeden supplier a `supplier_owner`.
3. Druhý identický provisioning vrátí stejný resource bez dalšího zápisu.
4. Owner přes one-time SSO dokončí fakturační nastavení.
5. ReviziOR upsertne klienta a MyInvoice jej nespojí s podobným klientem jiného tenant.
6. Price resolver použije správnou klientskou výjimku a měnu.
7. Současné/retry create requesty vytvoří právě jeden draft a totals odpovídají běžnému editoru.
8. Revizní PDF projde stream validation a připojí se právě jednou.
9. SSO otevře správný invoice editor; replay a cizí target selžou.
10. Issue/send/payment/cancel/credit note vytvoří monotónní immutable outbox eventy.
11. Nedostupný ReviziOR callback nezablokuje fakturaci; eventy se po obnově doručí.
12. Supplier owner nemůže platform admin funkce ani data jiné organizace.
13. Revoked user ztratí nové i stávající tenantové session oprávnění.
14. Standalone MyInvoice flow zůstává funkční.
15. Licenční provenance, backup/restore, key rotation a incident runbook jsou uzavřené před
    veřejným rolloutem.
