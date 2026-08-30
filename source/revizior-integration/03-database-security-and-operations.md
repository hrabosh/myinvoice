# 3. Databáze, bezpečnost a provoz

## 3.1 Migrační zásady

- nové tabulky používají další volná čísla migrací v rozsahu MyInvoice `0125–0999`;
- konkrétní číslo zvolit až při implementaci podle aktuálního `master`, ne podle tohoto plánu;
- každá migrace je idempotentní a používá MariaDB `IF NOT EXISTS`/`ADD ... IF NOT EXISTS`;
- žádné ruční spuštění SQL mimo `php api/bin/migrate.php`;
- nové FK/indexy musí mít integrační test na čisté databázi i upgrade existující databáze;
- migrace managed overlay nesmí změnit fakturační tabulky způsobem, který rozbije standalone;
- rollback produkce se řeší forward-fixem a backupem, ne automatickým DROP v down migraci.

Externí ReviziOR UUID ukládat jako `CHAR(36)` s ASCII/binární kolací a validovat přes UUID
value object. Numerická MyInvoice ID zůstávají FK `BIGINT UNSIGNED` podle stávajícího schématu.

## 3.2 Navržené tabulky

Názvy jsou záměrně prefixované `revizior_`, aby byl overlay lokalizovaný a snadno auditovatelný.

### `revizior_organization_links`

```sql
id                         BIGINT UNSIGNED PK AUTO_INCREMENT
organization_uuid          CHAR(36) ASCII NOT NULL
supplier_id                BIGINT UNSIGNED NOT NULL
status                     VARCHAR(32) NOT NULL
onboarding_state           VARCHAR(32) NOT NULL
payload_hash               CHAR(64) ASCII NOT NULL
source_updated_at          DATETIME(6) NULL
contract_version           VARCHAR(16) NOT NULL
created_at                 DATETIME(6) NOT NULL
updated_at                 DATETIME(6) NOT NULL
suspended_at               DATETIME(6) NULL
```

Constraints/indexy:

```text
UNIQUE organization_uuid
UNIQUE supplier_id
FK supplier_id → supplier(id)
INDEX (status, updated_at)
CHECK-like invariant v kódu + DB enum/constraint podle konvence projektu
```

Vazba se fyzicky nemaže běžným API. Suspended/disconnected stav zachová historii.

### `revizior_user_links`

```sql
id                         BIGINT UNSIGNED PK AUTO_INCREMENT
organization_link_id       BIGINT UNSIGNED NOT NULL
user_uuid                   CHAR(36) ASCII NOT NULL
user_id                     BIGINT UNSIGNED NOT NULL
supplier_role               VARCHAR(32) NOT NULL
active                      TINYINT(1) NOT NULL
source_updated_at           DATETIME(6) NULL
session_version             BIGINT UNSIGNED NOT NULL DEFAULT 1
created_at                  DATETIME(6) NOT NULL
updated_at                  DATETIME(6) NOT NULL
revoked_at                  DATETIME(6) NULL
```

```text
UNIQUE (organization_link_id, user_uuid)
UNIQUE (organization_link_id, user_id)
FK organization_link_id → revizior_organization_links(id)
FK user_id → users(id)
INDEX (user_id, active)
```

Membership v `user_suppliers` je stále autorizační zdroj pro obecné MyInvoice middleware.
`revizior_user_links` dokládá authoritative externí původ, revocation a session version. Zápis
obou musí být v jedné transakci.

### `revizior_client_links`

```sql
id                         BIGINT UNSIGNED PK AUTO_INCREMENT
organization_link_id       BIGINT UNSIGNED NOT NULL
client_uuid                 CHAR(36) ASCII NOT NULL
client_id                   BIGINT UNSIGNED NOT NULL
payload_hash                CHAR(64) ASCII NOT NULL
source_updated_at           DATETIME(6) NULL
created_at                  DATETIME(6) NOT NULL
updated_at                  DATETIME(6) NOT NULL
```

```text
UNIQUE (organization_link_id, client_uuid)
UNIQUE (organization_link_id, client_id)
FK organization_link_id → revizior_organization_links(id)
FK client_id → clients(id)
INDEX (client_uuid)
```

Každé načtení client linku ověří, že `clients.supplier_id` odpovídá supplieru organization linku.
Constraint, join nebo repository guard musí zabránit cross-tenant linku i při chybě v action.

### `revizior_invoice_links`

```sql
id                         BIGINT UNSIGNED PK AUTO_INCREMENT
organization_link_id       BIGINT UNSIGNED NOT NULL
external_invoice_key       CHAR(36) ASCII NOT NULL
invoice_id                  BIGINT UNSIGNED NOT NULL
request_hash                CHAR(64) ASCII NOT NULL
event_sequence             BIGINT UNSIGNED NOT NULL DEFAULT 0
created_at                  DATETIME(6) NOT NULL
updated_at                  DATETIME(6) NOT NULL
```

```text
UNIQUE (organization_link_id, external_invoice_key)
UNIQUE invoice_id
FK organization_link_id → revizior_organization_links(id)
FK invoice_id → invoices(id)
INDEX (external_invoice_key)
```

`invoice_id` je unikátní, protože jedna MyInvoice faktura nesmí být spojena s více externími
ReviziOR invoice keys. Repository vždy ověří supplier faktury.

### `revizior_invoice_sources`

```sql
id                         BIGINT UNSIGNED PK AUTO_INCREMENT
invoice_link_id            BIGINT UNSIGNED NOT NULL
source_type                VARCHAR(32) NOT NULL
source_uuid                CHAR(36) ASCII NOT NULL
external_line_key          CHAR(36) ASCII NULL
metadata_json              JSON NULL
created_at                 DATETIME(6) NOT NULL
```

```text
UNIQUE (invoice_link_id, source_type, source_uuid, external_line_key)
FK invoice_link_id → revizior_invoice_links(id)
INDEX (source_type, source_uuid)
```

Metadata nesmí obsahovat celý revizní protokol; pouze popisek/URL path nebo údaje nutné pro
zobrazení vazby.

### `revizior_attachment_links`

```sql
id                         BIGINT UNSIGNED PK AUTO_INCREMENT
invoice_link_id            BIGINT UNSIGNED NOT NULL
external_attachment_key    CHAR(36) ASCII NOT NULL
attachment_id              BIGINT UNSIGNED NOT NULL
sha256_hex                 CHAR(64) ASCII NOT NULL
size_bytes                 BIGINT UNSIGNED NOT NULL
created_at                 DATETIME(6) NOT NULL
```

```text
UNIQUE (invoice_link_id, external_attachment_key)
UNIQUE attachment_id
FK invoice_link_id → revizior_invoice_links(id)
FK attachment_id → existující invoice attachment tabulka
```

Pokud současná attachment doména nepoužívá stabilní tabulku/FK, implementátor nejprve ověří
skutečný model a upraví jen FK část; invariant externí key + digest zůstává.

### `revizior_idempotency_keys`

```sql
id                         BIGINT UNSIGNED PK AUTO_INCREMENT
subject_uuid               CHAR(36) ASCII NOT NULL
operation                  VARCHAR(64) NOT NULL
key_hash                   CHAR(64) ASCII NOT NULL
request_hash               CHAR(64) ASCII NOT NULL
state                      VARCHAR(16) NOT NULL
http_status                SMALLINT UNSIGNED NULL
response_json              JSON NULL
resource_type              VARCHAR(32) NULL
resource_id                VARCHAR(64) NULL
created_at                 DATETIME(6) NOT NULL
completed_at               DATETIME(6) NULL
expires_at                 DATETIME(6) NOT NULL
```

```text
UNIQUE (subject_uuid, operation, key_hash)
INDEX (expires_at)
INDEX (state, created_at)
```

- ukládat hash idempotency key, ne raw hodnotu;
- `request_hash` je SHA-256 kanonického payloadu;
- completed response musí být dostatečná pro identický retry;
- business resource se po expiraci klíče samozřejmě nemaže;
- retence create-invoice klíčů musí být dlouhá tak, aby pozdní retry nevytvořil duplicitu;
  external invoice key unique constraint je druhá obranná vrstva;
- cleanup nemaže klíč, pokud by zmizela jediná deduplikační informace bez external unique linku.

### `revizior_security_nonces`

```sql
jti_hash                   CHAR(64) ASCII PRIMARY KEY
purpose                    VARCHAR(16) NOT NULL
issuer                     VARCHAR(255) NOT NULL
subject                    VARCHAR(64) NOT NULL
expires_at                 DATETIME(6) NOT NULL
consumed_at                DATETIME(6) NOT NULL
request_id                 CHAR(36) ASCII NULL
```

Insert s unikátním PK je autoritativní replay guard pro service i SSO. Redis může fungovat jako
rychlá cache, ale nesmí být jedinou ochranou, pokud restart/eviction dovolí replay ještě platného
ticketu. Cleanup maže jen bezpečně expirované nonces.

### `revizior_event_outbox`

```sql
id                         CHAR(36) ASCII PRIMARY KEY
organization_link_id       BIGINT UNSIGNED NOT NULL
invoice_link_id            BIGINT UNSIGNED NULL
aggregate_type             VARCHAR(32) NOT NULL
aggregate_id               VARCHAR(64) NOT NULL
aggregate_sequence         BIGINT UNSIGNED NOT NULL
event_type                 VARCHAR(64) NOT NULL
spec_version               VARCHAR(16) NOT NULL
payload_json               JSON NOT NULL
payload_hash               CHAR(64) ASCII NOT NULL
state                      VARCHAR(16) NOT NULL
delivery_attempts          INT UNSIGNED NOT NULL DEFAULT 0
next_attempt_at            DATETIME(6) NOT NULL
last_http_status           SMALLINT UNSIGNED NULL
last_error_code            VARCHAR(64) NULL
claimed_at                 DATETIME(6) NULL
claimed_by                 VARCHAR(64) NULL
created_at                 DATETIME(6) NOT NULL
delivered_at               DATETIME(6) NULL
```

```text
UNIQUE (invoice_link_id, aggregate_sequence) pro invoice eventy
FK organization_link_id → revizior_organization_links(id)
FK invoice_link_id → revizior_invoice_links(id) nullable
INDEX (state, next_attempt_at)
INDEX (claimed_at)
```

Payload je immutable snapshot. Dispatcher jej nikdy neregeneruje z aktuální faktury.

## 3.3 Kanonický hash

Vytvořit jednu službu `CanonicalPayloadHasher`, sdílenou idempotencí a payload hashováním:

- rekurzivně seřadí object keys;
- zachová pořadí arrays;
- normalizuje UUID lowercase canonical form;
- datumy na přesný kontraktní ISO formát;
- decimal hodnoty jsou canonical strings podle field schema, nikdy float;
- rozlišuje `null`, chybějící klíč a prázdný string podle kontraktu;
- serializuje bez locale závislosti;
- SHA-256 nad UTF-8 bytes.

Kanonické fixture sdílí oba repozitáře. Nelze použít prosté `json_encode()` nad nahodilým PHP
array a očekávat stabilní hash po refaktoru.

## 3.4 Transakční hranice

### Provisioning

Jedna transakce:

```text
idempotency row
+ supplier
+ organization link
+ owner user/link
+ user_supplier membership
+ audit activity
+ organization event outbox (pokud se posílá)
```

### Client upsert

```text
client create/update
+ contacts
+ external client link/payload hash
+ audit activity
```

### Invoice draft

```text
idempotency row
+ invoice header/items/totals/exchange rate
+ external invoice link
+ source references
+ activity
+ event sequence/outbox
+ stored idempotent response
```

### Invoice/payment změna

```text
existing business write
+ event sequence increment
+ event outbox
```

Pokud stávající repository metody samy commitují, refaktorovat transaction ownership. Žádný
integrační use-case nesmí emulovat atomickou operaci několika oddělenými commity.

## 3.5 Concurrency

- organization/client/invoice unique constraints jsou poslední obrana proti race;
- idempotency row se zakládá unikátním insertem a při kolizi se načte `FOR UPDATE`;
- stejný in-progress request může krátce čekat nebo vrátit `409/425 request_in_progress` s
  `Retry-After`; nesmí souběžně pokračovat;
- invoice event sequence se inkrementuje pod row lockem;
- outbox claim používá `SELECT ... FOR UPDATE SKIP LOCKED`, pokud podporováno cílovou MariaDB,
  nebo bezpečný atomický claim update;
- worker po pádu uvolní stale claim po definovaném timeoutu;
- attachment final move proběhne až po digest checku a DB transaction koordinuje link.

## 3.6 Threat model

### T1 — Cross-tenant IDOR

Útočník mění supplier/client/invoice ID v URL nebo SSO targetu.

Ochrana:

- supplier odvozen z podepsaného organization subjectu;
- external link repositories vždy filtrují organization link;
- business resource ownership se ověřuje proti supplieru;
- response při cizím resource je 404/generic forbidden;
- povinné dvou-tenantové integrační testy pro každý endpoint.

### T2 — Service token replay/forgery

Ochrana:

- asymetrický podpis, exact issuer/audience/algorithm/kid;
- maximální TTL 60 s;
- persistentní unique `jti` consumption;
- scope + subject/path binding;
- TLS;
- key rotation a emergency revocation.

### T3 — SSO replay/open redirect/session fixation

Ochrana:

- one-time `jti`;
- strict relative target allowlist + ownership lookup;
- exact return host allowlist;
- session ID rotation po SSO;
- host-only Secure HttpOnly SameSite cookie;
- redakce query stringu v proxy/app logu;
- revoked membership/session version check.

### T4 — Idempotency abuse

Ochrana:

- key scoped na organization + operation;
- kanonický request hash;
- key length/format limit a hash storage;
- conflict na jiný payload;
- unique external business key;
- rate limit per organization.

### T5 — Malicious attachment

Ochrana:

- server-to-server only;
- body limit před parsováním;
- generated storage path;
- MIME/magic/digest/size check;
- antivirová kontrola, pokud je v deploymentu k dispozici;
- private storage a autorizované downloady;
- žádné URL fetchování/SSRF.

### T6 — Forged webhook nebo payload tampering

Ochrana:

- detached JWS nad exact raw body;
- ReviziOR má allowlist veřejných klíčů/kid;
- immutable event ID/payload hash;
- žádné secret v URL;
- delivery přes HTTPS;
- alert na opakované 401/403 callback responses.

### T7 — Privilege escalation přes `supplier_owner`

Ochrana:

- supplier role oddělena od global role;
- integrační API odmítá `admin`;
- platform endpoints kontrolují global permission;
- action-level permission policy;
- test každé citlivé platform route pro supplier owner denial.

### T8 — Leakage přes logy/backupy

Ochrana:

- log allowlist místo dumpování requestu;
- token/ticket/signature/query redaction;
- šifrovaný transport a přístupově omezené backupy;
- restore prostředí s oddělenými klíči a bez rozesílání e-mailů;
- audit přístupů k produkčním backupům.

## 3.7 Rate limits a limity payloadu

Výchozí hodnoty konfigurovatelně:

```text
capabilities              120/min/platform
provision organization     10/min/platform
organization/user sync    120/min/platform
client upsert              300/min/organization
price resolve              300/min/organization
invoice draft               60/min/organization
reconciliation             300/min/organization
SSO                         60/min/user + 300/min/IP
attachment                   20/min/organization
```

Limity nejsou produktové kvóty; chrání integraci. `429` vrací `Retry-After`. Body limity se
vynucují před JSON decode/file buffering.

## 3.8 Observabilita

### Structured log fields

```text
service=myinvoice
component=revizior_integration
request_id
event_id nullable
organization_uuid_hash
supplier_id
operation
result
error_code
http_status
latency_ms
retry_count nullable
```

Nelogovat klientské payloady, e-mail, DIČ, adresy, položky faktury, tokeny ani PDF.

### Metriky

```text
revizior_integration_requests_total{operation,status}
revizior_integration_request_duration_seconds{operation}
revizior_service_token_denied_total{reason}
revizior_sso_total{result,reason}
revizior_idempotency_replay_total{operation,result}
revizior_outbox_pending_total
revizior_outbox_oldest_age_seconds
revizior_outbox_delivery_total{result,status_class}
revizior_outbox_delivery_duration_seconds
revizior_attachment_bytes_total
revizior_tenant_denial_total{operation}
```

Kardinalitu držet nízkou; nedávat organization UUID, invoice ID ani request ID do labels.

### Health/readiness

- liveness: proces odpovídá;
- readiness: MariaDB, potřebná migrace/schema version, private storage, replay store a signing
  config jsou dostupné;
- webhook destination nedostupná nesmí shodit readiness fakturační služby, ale musí být vidět
  jako degraded dependency + outbox alert;
- `/capabilities` vrací managed ready pouze při úplné konfiguraci.

## 3.9 Worker a cron

Přidat cross-platform příkazy/wrappery:

```text
api/bin/revizior-dispatch-outbox.php
api/bin/revizior-cleanup-integration-state.php
api/bin/revizior-integration-status.php
api/bin/revizior-retry-event.php
cmd/cron-revizior-outbox.sh + .ps1/.cmd
cmd/cron-revizior-cleanup.sh + .ps1/.cmd
```

- dispatcher lze bezpečně spouštět paralelně;
- status ukazuje počty/nejstarší event bez PII;
- retry konkrétního eventu vyžaduje platform admina/CLI a audit;
- cleanup maže pouze expirované nonces, bezpečně staré delivery metadata a idempotency záznamy,
  jejichž odstranění neporuší deduplikaci;
- žádný cron nepotvrzuje právní/daňový krok bez existujícího MyInvoice pravidla.

## 3.10 Deployment

Doporučené komponenty:

```text
reverse proxy / TLS
MyInvoice PHP runtime
Vue static build
MariaDB
Redis (sessions/rate limit/cache; replay DB zůstává autoritativní)
private document storage
outbox worker/cron
mailer
monitoring/log shipping
```

`fakturace.revizior.cz` má vlastní DB, storage a secrets; nesdílí PostgreSQL ReviziORu.

Nasazení:

1. backup DB/storage;
2. deploy backward-compatible provider kódu;
3. `migrate.php --status`, poté migrace;
4. build Vue (`pnpm build`);
5. smoke readiness/capabilities;
6. spustit outbox worker;
7. až poté povolit capability v ReviziORu;
8. monitorovat 5xx/outbox/tenant denial.

In-app update a MyÚčto upgrade jsou v managed mode blokované backendem i UI.

## 3.11 Backup a obnova

Před produkčním launch musí existovat a být ověřeno:

- pravidelný plný backup MariaDB;
- binlog/PITR nebo ekvivalent pro cílové RPO nejvýše 15 minut;
- verzovaný backup dokument storage;
- backup konfigurace bez přimíchání privátních klíčů do běžného artefaktu;
- samostatný bezpečný backup/escrow signing klíčů nebo definovaný rotační recovery postup;
- čtvrtletní restore test na izolovaném prostředí;
- obnovené prostředí neposílá reálné e-maily ani webhooky, dokud se explicitně neodemkne;
- cílové RTO nejvýše 4 hodiny pro počáteční produkční režim, nebo explicitně schválená změna.

Outbox po obnově může znovu doručit eventy; consumer je proto povinně idempotentní.

## 3.12 Incident runbooky

Minimální runbooky:

1. kompromitovaný ReviziOR service public/private key;
2. kompromitovaný MyInvoice webhook signing key;
3. podezření na cross-tenant přístup;
4. outbox backlog / callback dlouhodobě nefunguje;
5. poškozená nebo nedostupná MariaDB;
6. document storage nedostupný;
7. chybný upstream merge v daňové/fakturační logice;
8. SSO loop nebo masové replay denial;
9. neúmyslné odesílání e-mailů ze staging/restore prostředí.

Runbook obsahuje izolaci, rotaci, audit rozsahu, komunikaci, recovery a post-incident test.

## 3.13 Data lifecycle a GDPR

ReviziOR může deaktivovat organizaci nebo uživatele, ale MyInvoice nesmí automaticky smazat
faktury a audit jen proto, že byl zrušen SaaS účet. Retence finančních dokladů podléhá právní a
smluvní politice, kterou musí před produkcí potvrdit právní/daňový specialista.

Technické zásady:

- revoke identity ≠ delete historical invoice;
- externí links lze anonymizovat/odpojit pouze řízeným procesem bez porušení integrity dokladu;
- export tenantových dat musí zahrnout faktury, PDF, přílohy, klienty, platby a relevantní audit;
- delete request vytvoří report, co bylo smazáno, anonymizováno nebo zachováno z právního důvodu;
- event payloady obsahují pouze minimum potřebné pro ReviziOR read model;
- provozní logy mají omezenou retenci a neslouží jako účetní archiv.

## 3.14 Licenční a provenance gate

Před produkčním použitím je blokující R0 úkol:

- kořenový `LICENSE` deklaruje MIT;
- `api/composer.json` aktuálně deklaruje `proprietary`;
- ověřit původ všech částí, které bude managed fork distribuovat/provozovat;
- sjednotit metadata pouze na základě doloženého licenčního stavu;
- zachovat copyright notices a third-party licences;
- nevkládat do forku kód z proprietární části MyÚčto bez samostatného oprávnění;
- vytvořit `THIRD_PARTY_NOTICES`/SBOM podle release procesu.

Tento plán sám neurčuje právní závěr; nesoulad nesmí zůstat nevyřešený.

## 3.15 Upstream merge strategie

- nakonfigurovat/udržovat upstream `radekhulan/myinvoice`;
- přebírat změny často v malých samostatných PR;
- managed overlay držet v prefixovaných třídách/tabulkách/config větvích;
- společné refaktory (`ClientWriter`, `InvoiceDraftCreator`) musí být malé a užitečné i
  standalone actions;
- před merge spustit upstream testy, managed integration testy, frontend build a contract
  fixtures;
- konflikty v daňové logice se neřeší mechanicky — vyžadují vědomý review;
- evidovat ověřený upstream commit/tag v release metadatech;
- nikdy automaticky merge + deploy bez CI a review.

## 3.16 Akceptační kritéria

- čistá i upgrade migrace projde opakovaně;
- DB constraint znemožní dvě organization vazby na jeden supplier a dvě invoice vazby na jeden
  doklad;
- business write + link + outbox jsou atomické;
- souběžné idempotentní requesty nevytvoří duplicitu;
- replay nonce přežije restart procesu/cache a platný token nelze použít podruhé;
- threat-model testy pokrývají dva tenanty a všechny dynamické resources;
- outbox worker lze bezpečně spouštět paralelně a po pádu obnoví stale claim;
- health/readiness/metrics neobsahují PII;
- backup a restore test je zdokumentovaný a ověřený;
- managed update nelze spustit z UI ani API;
- licenční nesoulad je před produkcí uzavřen doloženým rozhodnutím.
