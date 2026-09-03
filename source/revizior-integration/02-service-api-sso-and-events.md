# 2. Service API, SSO a event kontrakt

## 2.1 Umístění integrační vrstvy

ReviziOR-specific kód držet aditivně a pohromadě:

```text
api/src/Action/Integration/Revizior/
api/src/Http/Integration/Revizior/
api/src/Middleware/ReviziorServiceAuthMiddleware.php
api/src/Repository/Integration/Revizior/
api/src/Service/Integration/Revizior/
api/src/Service/Integration/Revizior/Event/
api/src/Service/Integration/Revizior/Sso/
api/tests/{Unit,Integration}/Integration/Revizior/
web/src/features/revizior-managed/
```

Nevytvářet druhý invoice/client/price-list repository. Integration repositories vlastní pouze
externí vazby, idempotenci, replay záznamy a outbox. Business zápis jde přes existující doménu.

## 2.2 Refaktor actions na znovupoužitelné application služby

Část orchestrace je dnes přímo v HTTP actions. Integrační endpoint ji nesmí kopírovat ani volat
lokální HTTP API. Před implementací endpointu vytáhnout malé služby, které používají původní i
nová action.

### Klient

Navržená služba:

```php
final class ClientWriter
{
    public function create(int $supplierId, ClientWriteData $data, ActorContext $actor): array;
    public function update(int $supplierId, int $clientId, ClientWriteData $data, ActorContext $actor): array;
}
```

Musí zachovat:

- `Validation::client()`;
- supplier scope;
- email contacts;
- activity log;
- současné databázové invarianty.

`CreateClientAction`/`UpdateClientAction` se stanou mapováním HTTP → DTO → služba → JSON.

### Cena

Navržená fasáda nad současným repository/resolverem:

```php
final class PriceResolutionService
{
    public function resolve(PriceResolutionRequest $request): ResolvedPriceCollection;
}
```

Použije `PriceListItemResolver` a stejnou logiku klientských výjimek, měny, rozhodného data a
`prices_include_vat` jako editor. Integrace nesmí implementovat vlastní výběr ceny.

### Invoice draft

Navržená služba:

```php
final class InvoiceDraftCreator
{
    public function create(InvoiceDraftData $data, ActorContext $actor): array;
}
```

Musí v jedné business transakci zachovat tok současného `CreateInvoiceAction`:

1. `InvoiceDefaults`;
2. `InvoiceValidation`;
3. supplier/client ownership;
4. VAT classification defaults;
5. `InvoiceRepository::createDraft()`;
6. položky;
7. `InvoiceCalculator::recompute()`;
8. exchange rate application;
9. activity log.

Původní action začne používat stejnou službu. Integrační use-case navíc obalí volání externí
vazbou a idempotency recordem v transakci popsané níže.

## 2.3 Base path a verzování

```text
/api/integrations/revizior/v1
```

Breaking změna vytváří `/v2`; v1 zůstává po přechodnou dobu funkční. Response vrací:

```text
X-Revizior-Contract-Version: 1.0
X-Request-Id: <request UUID>
```

Vytvořit samostatný strojově validovaný kontrakt:

```text
api/openapi-revizior-integration.yaml
```

Tento spec není přimíchán do běžného PAT „Try it out“ klienta. Lze jej vystavit jen platform
adminovi nebo držet jako build artefakt. CI ověřuje YAML, duplicitní klíče, `$ref` a fixture
kompatibilitu s `hrabosh/backend`.

## 2.4 Service autentizace

Všechny endpointy pod base path vyžadují:

```http
Authorization: Bearer <short-lived signed JWT/JWS>
X-Request-Id: <UUID>
```

Použít udržovanou JOSE/JWT knihovnu; nepodepisovat ani neověřovat token ručně přes volná
`openssl_*` volání roztroušená v actions. Přidání dependency musí projít licenčním a
bezpečnostním auditem.

### Algoritmus a klíče

- asymetrický podpis, preferovaně `PS256` nebo jiný explicitně schválený algoritmus;
- žádné `alg=none`, žádné rozhodnutí algoritmu pouze podle token headeru;
- povolený algoritmus je svázaný s nakonfigurovaným `kid`;
- privátní klíč drží ReviziOR, MyInvoice pouze veřejné klíče;
- klíče mají překryvnou rotaci.

### Claims

```json
{
  "iss": "https://app.revizior.cz",
  "aud": "https://fakturace.revizior.cz/api/integrations/revizior/v1",
  "sub": "<organization UUID nebo platform provisioning subject>",
  "jti": "<UUID>",
  "iat": 1788120000,
  "nbf": 1788120000,
  "exp": 1788120060,
  "scope": ["client:write", "invoice:write"],
  "request_id": "<UUID>"
}
```

Validace:

- exact issuer/audience;
- TTL maximálně 60 sekund;
- povolený clock skew nejvýše 30 sekund;
- `request_id` musí odpovídat hlavičce;
- `jti` se přijme atomicky právě jednou přes replay store;
- `sub` musí odpovídat organization UUID v cestě, kromě explicitního platform subjectu pro
  provisioning;
- scope musí pokrývat operaci;
- chybějící/nesprávný claim = 401 nebo 403 se stabilním kódem;
- token se nikdy neloguje.

### Scopes v1

```text
capabilities:read
organization:provision
organization:write
user:write
client:write
price:read
invoice:write
invoice:read
attachment:write
```

Provisioning scope smí použít pouze nakonfigurovaný platform subject. Tenantový subject nemůže
provisionovat jinou organization UUID.

## 2.5 Společný response/error formát

Success:

```json
{
  "specVersion": "1.0",
  "data": {},
  "meta": {
    "contractVersion": "1.0",
    "requestId": "<UUID>"
  }
}
```

Error:

```json
{
  "specVersion": "1.0",
  "error": {
    "code": "validation_failed",
    "message": "Požadavek nelze zpracovat.",
    "fields": {"vatStatus": "required"},
    "retryable": false
  },
  "meta": {
    "contractVersion": "1.0",
    "requestId": "<UUID>"
  }
}
```

Všechny v1 JSON requesty, response a eventy nesou top-level `specVersion: "1.0"`. Je to
explicitní discriminator kontraktních fixtures; `meta.contractVersion` navíc identifikuje
skutečně obslouženou verzi provideru a zůstává součástí response obálky.

Stabilní kódy minimálně:

```text
service_token_missing
service_token_invalid
service_token_expired
service_token_replayed
service_scope_insufficient
organization_subject_mismatch
organization_not_provisioned
organization_suspended
organization_link_conflict
onboarding_incomplete
user_link_conflict
user_membership_inactive
client_link_conflict
client_validation_failed
price_not_found
price_not_available_for_currency
idempotency_key_missing
idempotency_conflict
invoice_validation_failed
invoice_not_found
invoice_not_editable
attachment_invalid
attachment_too_large
attachment_digest_mismatch
provider_temporarily_unavailable
```

Interní exception message/SQL se nikdy nevrací klientovi.

## 2.6 Capabilities

```http
GET /api/integrations/revizior/v1/capabilities
Scope: capabilities:read
```

Response:

```json
{
  "specVersion": "1.0",
  "data": {
    "contractVersion": "1.0",
    "deploymentMode": "revizior_managed",
    "features": {
      "organizationProvisioning": true,
      "userProvisioning": true,
      "clientUpsert": true,
      "priceResolution": true,
      "invoiceDraft": true,
      "attachments": true,
      "sso": true,
      "proforma": true,
      "creditNote": true,
      "partialPayments": true,
      "eventOutbox": true
    },
    "limits": {
      "maxItemsPerInvoice": 500,
      "maxAttachmentBytes": 20971520,
      "maxRequestBytes": 2097152
    }
  },
  "meta": {"contractVersion": "1.0", "requestId": "..."}
}
```

ReviziOR smí feature aktivovat jen při kompatibilní verzi a požadovaných capabilities.

## 2.7 Provisioning organizace

```http
POST /api/integrations/revizior/v1/organizations/{organizationUuid}/provision
Authorization: Bearer ...
Idempotency-Key: provision:{organizationUuid}:v1
Scope: organization:provision
```

Payload:

```json
{
  "specVersion": "1.0",
  "organization": {
    "name": "Revize Elektro s.r.o.",
    "registrationNumber": "12345678",
    "vatNumber": "CZ12345678",
    "vatStatus": null,
    "address": {
      "street": "Hlavní 1",
      "city": "Brno",
      "postalCode": "60200",
      "countryCode": "CZ"
    },
    "language": "cs",
    "active": true,
    "sourceUpdatedAt": "2026-08-30T18:00:00Z"
  },
  "owner": {
    "userUuid": "...",
    "email": "owner@example.test",
    "name": "Jan Novák",
    "role": "supplier_owner",
    "active": true
  }
}
```

`vatStatus: null` zůstane nevyplněný a onboarding jej vyžádá. Provider neodvozuje právní režim
z `vatNumber`.

Response:

```json
{
  "specVersion": "1.0",
  "data": {
    "organizationUuid": "...",
    "supplierId": "123",
    "status": "onboarding",
    "onboardingState": "incomplete",
    "configurePath": "/settings/supplier",
    "payloadHash": "sha256:..."
  },
  "meta": {"contractVersion": "1.0", "requestId": "..."}
}
```

Idempotence a supplier insert/link/membership musí být jedna DB transakce.

## 2.8 Synchronizace organizace a uživatelů

```http
PUT /organizations/{organizationUuid}
Scope: organization:write

PUT /organizations/{organizationUuid}/users/{userUuid}
DELETE /organizations/{organizationUuid}/users/{userUuid}
Scope: user:write
```

Organization update mění pouze živé supplier nastavení povolené kontraktem; nevytváří nový
supplier a nemění snapshot vystavených faktur.

User upsert přijímá pouze role:

```text
supplier_owner
accountant
readonly
```

`admin` je v integračním payloadu neplatná hodnota. Revoke zachová historické odkazy, zruší
membership a invaliduje tenantové session.

## 2.9 Upsert klienta

```http
PUT /organizations/{organizationUuid}/clients/{clientUuid}
Scope: client:write
```

Payload používá explicitní pole a nullable hodnoty. Response:

```json
{
  "specVersion": "1.0",
  "data": {
    "clientUuid": "...",
    "externalClientId": "456",
    "operation": "created",
    "payloadHash": "sha256:..."
  }
}
```

Pravidla:

- lookup nejprve podle `revizior_client_links`;
- bez linku vznikne nový client přes společný `ClientWriter`;
- žádné automatické spojení podle IČO/e-mailu;
- stejný kanonický payload hash = `unchanged` bez zbytečného write/audit noise;
- update proběhne jen v supplieru z organization linku;
- archivační stav se přenáší explicitně;
- historie vystavených dokladů je chráněna jejich snapshoty.

## 2.10 Price resolution

```http
POST /organizations/{organizationUuid}/prices/resolve
Scope: price:read
```

Request:

```json
{
  "specVersion": "1.0",
  "clientUuid": "...",
  "currency": "CZK",
  "rateDate": "2026-08-30",
  "pricesIncludeVat": false,
  "items": [
    {"lineKey": "...", "code": "REV_ELEKTRO_ZAKLAD", "quantity": "1.000"}
  ]
}
```

Response per item:

```json
{
  "lineKey": "...",
  "code": "REV_ELEKTRO_ZAKLAD",
  "priceListItemId": "55",
  "description": "Pravidelná revize elektroinstalace",
  "unit": "ks",
  "quantity": "1.000",
  "unitPrice": "4500.00",
  "currency": "CZK",
  "pricesIncludeVat": false,
  "vatRate": "21.00",
  "source": "customer_override",
  "effectiveAt": "2026-08-30"
}
```

Pokud jedna položka chybí, endpoint vrátí per-item error; nesmí ji převést na cenu 0. ReviziOR
může nechat uživatele zadat ruční cenu, která bude následně validována invoice draft use-casem.

## 2.11 Idempotentní invoice draft

```http
POST /organizations/{organizationUuid}/invoice-drafts
Idempotency-Key: invoice-draft:{externalInvoiceKey}
Scope: invoice:write
```

`externalInvoiceKey` je UUID vytvořené ReviziORem a zároveň stabilní business key.

### Transakční tok

V jedné MariaDB transakci:

1. zamknout/načíst idempotency key;
2. vypočítat kanonický request hash;
3. při existujícím completed key vrátit uloženou response;
4. při stejném key a jiném hash vrátit 409;
5. resolve organization link → supplier;
6. resolve external client link → client a ověřit supplier;
7. vytvořit draft přes společný `InvoiceDraftCreator`;
8. vytvořit `revizior_invoice_links` a source line metadata;
9. uložit serializovatelný response snapshot do idempotency recordu;
10. vložit `invoice.draft_created` do outboxu;
11. commit.

Pokud současná `InvoiceDraftCreator` používá vlastní transakční hranici, refaktorovat tak, aby
šlo všechny zápisy atomicky provést nad jedním `Connection`/transaction runnerem. Nesmí vzniknout
stav „faktura existuje, externí link ne“.

### Payload

Obsahuje klienta, typ dokladu, měnu, datumy, režim cen, položky a source references. Decimal
hodnoty jsou JSON stringy, nikoli float. MyInvoice znovu provede plnou validaci a výpočet.

### Response snapshot

```json
{
  "specVersion": "1.0",
  "externalInvoiceKey": "...",
  "invoiceId": "789",
  "invoiceNumber": null,
  "status": "draft",
  "rawStatus": "draft",
  "currency": "CZK",
  "totalMinor": 544500,
  "amountDueMinor": 544500,
  "issueDate": "2026-08-30",
  "dueDate": "2026-09-13",
  "editPath": "/invoices/789/edit",
  "publicUrl": null,
  "pdfUrl": null,
  "sequence": 1
}
```

Číslo může být `null` podle skutečného MyInvoice pravidla pro přidělení čísla draftu. Kontrakt
nesmí nutit předčasné číslování.

## 2.12 Přílohy

```http
PUT /organizations/{organizationUuid}/invoice-drafts/{externalInvoiceKey}/attachments/{externalAttachmentKey}
Scope: attachment:write
Content-Type: application/pdf
Content-Length: ...
Digest: sha-256=<base64 digest>
X-File-Name: <safe display name, optional>
```

Pravidla:

- streamovat do dočasného privátního souboru;
- limit z capabilities;
- ověřit content length i skutečně načtené bytes;
- `finfo` + `%PDF-` magic bytes;
- ověřit SHA-256 digest před finálním přesunem;
- generovat interní storage jméno, nepoužívat user path;
- external attachment key + stejný digest = vrátit původní přílohu;
- stejný key + jiný digest = 409;
- faktura musí patřit organization supplieru a být ve stavu, který přílohu dovoluje;
- aktivita a outbox event neobsahují obsah PDF.

## 2.13 Reconciliation read

```http
GET /organizations/{organizationUuid}/invoices/{externalInvoiceKey}
Scope: invoice:read
```

Vrací aktuální normalizovaný invoice snapshot a `sequence`. ReviziOR jej používá jako opravný
mechanismus při ztraceném webhooku, ne jako primární polling každého detailu.

## 2.14 Browser SSO

Endpoint není pod service API base path:

```http
GET /api/auth/revizior/sso?ticket=<compact signed JWS>
```

Ticket má odlišné audience a ideálně i signing key než service assertion:

```json
{
  "iss": "https://app.revizior.cz",
  "aud": "https://fakturace.revizior.cz/api/auth/revizior/sso",
  "sub": "<ReviziOR user UUID>",
  "organization_id": "<ReviziOR organization UUID>",
  "jti": "<UUID>",
  "iat": 1788120000,
  "nbf": 1788120000,
  "exp": 1788120060,
  "purpose": "browser_sso",
  "target": "/invoices/789/edit",
  "return_to": "https://app.revizior.cz/fakturace/<local UUID>"
}
```

### Ověření

1. ověřit signature/issuer/audience/time/purpose;
2. atomicky spotřebovat `jti`; druhý pokus selže i když první redirect už proběhl;
3. načíst organization link a aktivní status;
4. načíst user link + aktivní membership, nikoli věřit roli z ticketu;
5. ověřit target proti typovanému allowlistu a supplier ownership cíle;
6. ověřit `return_to` proti přesnému scheme + host allowlistu;
7. rotovat/vytvořit MyInvoice session, nastavit aktuální supplier;
8. `303 See Other` na relativní target.

Target allowlist v1:

```text
/invoices
/invoices/{numericId}
/invoices/{numericId}/edit
/clients
/clients/{numericId}
/projects
/projects/{numericId}
/settings/supplier
/price-list
/bank
```

Regex nestačí: dynamický resource target se navíc načte a ověří proti supplieru. Externí URL,
`//host`, encoded traversal a neočekávané query parametry jsou odmítnuty.

Ticket ani query string se nesmí dostat do access logu; reverse proxy musí query redigovat pro
tuto cestu. Po úspěchu se provede redirect bez ticketu.

## 2.15 Event outbox

Každá relevantní změna faktury vytvoří event ve stejné DB transakci jako business změna.
Nevkládat HTTP callback do action/service transakce.

### Eventy v1

```text
organization.onboarding_completed
organization.suspended
invoice.draft_created
invoice.updated
invoice.issued
invoice.sent
invoice.payment_recorded
invoice.partially_paid
invoice.paid
invoice.overdue
invoice.cancelled
invoice.credit_note_issued
invoice.deleted_draft
```

### Sekvence

Každý linked invoice má monotónní `event_sequence`. Event builder:

1. zamkne `revizior_invoice_links` řádek;
2. inkrementuje sequence;
3. vytvoří immutable payload snapshot;
4. vloží outbox řádek;
5. business transaction commitne obojí.

Událost bez ReviziOR external linku se do ReviziOR outboxu nevkládá. Běžné standalone faktury
tedy nejsou ovlivněné.

### Envelope

```json
{
  "specVersion": "1.0",
  "eventId": "<UUID>",
  "eventType": "invoice.issued",
  "occurredAt": "2026-08-30T18:15:00Z",
  "organizationId": "<ReviziOR UUID>",
  "supplierId": "123",
  "aggregate": {
    "type": "invoice",
    "id": "789",
    "externalKey": "<ExternalInvoiceKey UUID>",
    "sequence": 4
  },
  "data": {
    "invoiceNumber": "20260048",
    "status": "issued",
    "rawStatus": "issued",
    "currency": "CZK",
    "totalMinor": 544500,
    "amountDueMinor": 544500,
    "issuedAt": "2026-08-30T18:14:55Z",
    "dueAt": "2026-09-13",
    "paidAt": null,
    "editPath": "/invoices/789/edit",
    "publicUrl": null,
    "pdfUrl": null
  }
}
```

Event používá minor units. Payload je snapshot v outboxu; dispatcher jej po pozdější změně
faktury neregeneruje.

## 2.16 Podepisování a doručení eventů

Callback:

```http
POST https://app.revizior.cz/api/internal/v1/invoicing/events
Content-Type: application/json
X-MyInvoice-Key-Id: <kid>
X-MyInvoice-Signature: <detached JWS over exact raw body>
X-Request-Id: <delivery UUID>
```

Použít standardní detached JWS podporovaný zvolenou JOSE knihovnou. Consumer ověřuje přesné raw
bytes, algoritmus, `kid` a nakonfigurovaný veřejný klíč. Privátní webhook key existuje pouze v
MyInvoice secrets.

Dispatcher:

- dávkuje pending outbox řádky pomocí bezpečného row locking/claim mechanismu;
- connect timeout 3 s, request timeout 10 s;
- 2xx = delivered;
- 408, 425, 429, 5xx a síťová chyba = retry;
- respektuje `Retry-After`;
- exponenciální backoff s jitterem a maximálním intervalem;
- 4xx kromě 408/425/429 = non-retryable/dead letter + alert;
- ukládá attempts, next_attempt_at, last status/error category;
- neloguje payload s PII;
- má CLI pro stav, retry a bezpečné replay eventu.

ReviziOR musí být idempotentní podle `eventId`; MyInvoice může tentýž event doručit vícekrát.

## 2.17 Napojení eventů do existujících use-cases

Nevkládat outbox pouze do HTTP actions. Event musí vzniknout bez ohledu na vstupní kanál:

- vystavení z UI i API;
- odeslání e-mailem;
- manuální a importovaná platba;
- bankovní matching;
- cron overdue/upomínky, pokud mění stav;
- storno/dobropis/proforma flow.

Přidat centralizovaný `ReviziorInvoiceEventPublisher`, který je volán v application službách po
úspěšné změně. Publisher nejprve ověří, zda invoice má aktivní ReviziOR link; standalone invoice
je no-op.

## 2.18 Contract a bezpečnostní testy

Povinné testy:

- valid/invalid/expired/wrong-audience service JWT;
- replay `jti`;
- missing/insufficient scope;
- organization subject mismatch;
- idempotence se stejným/different payloadem;
- souběžný create draft;
- rollback při chybě po vytvoření invoice před linkem;
- client/invoice ID z cizího supplieru;
- decimal a rounding fixture přes existující invoice calculator;
- SSO replay, expired ticket, open redirect, encoded traversal, target cizího tenant;
- revoked membership a již existující session;
- attachment MIME/magic/digest/size/traversal/retry;
- outbox v business transakci;
- dispatcher retry/dead letter;
- monotónní sequence;
- event payload fixtures shodné s `hrabosh/backend`.

## 2.19 Akceptační kritéria

- běžný PAT nemůže volat `/api/integrations/revizior/*` ani SSO endpoint jako service API;
- integrační action neobsahuje kopii invoice math/VAT/client write logiky;
- invoice a externí link vzniknou atomicky;
- retry po ztracené response vrátí původní invoice;
- stejné UUID z jiného tenant subjectu je odmítnuto;
- browser nevidí service assertion ani webhook secret;
- SSO vytvoří vlastní bezpečnou session a druhé použití ticketu selže;
- všechny změnové kanály linked invoice produkují sekvenovaný outbox event;
- výpadek callbacku nezablokuje fakturační transakci a neztratí event;
- standalone endpoints a jejich testy zůstávají funkční.
