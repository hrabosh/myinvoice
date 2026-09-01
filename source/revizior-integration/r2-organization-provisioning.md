# R2 organization provisioning — implementovaný atomický onboarding

> Stav: endpoint, persistentní idempotence a tenant owner hotové
> Datum: 2026-09-01

## Endpoint

```text
POST /api/integrations/revizior/v1/organizations/{organizationUuid}/provision
Scope: organization:provision
Subject: platform
Idempotency-Key: povinný, maximálně 255 bytes
```

Endpoint je dostupný pouze v `revizior_managed` deploymentu přes stejný krátkodobý RS256
service assertion jako capabilities. Tenantový subject nemůže založit jinou organizaci.

## Transakční invariant

Jediná databázová transakce vytvoří nebo dokončí:

- hashovaný idempotency záznam;
- supplier a jeho výchozí CZK/EUR měny;
- `revizior_organization_links` vazbu;
- owner user s globální rolí `readonly` nebo bezpečně znovupoužije existujícího neadmin uživatele;
- `user_suppliers` membership s tenantovou rolí `supplier_owner`;
- `revizior_user_links` s aktivním členstvím a `session_version=1`;
- activity log `revizior.organization.provisioned`;
- uloženou dokončenou response pro identický retry.

Selhání kteréhokoli kroku vrátí celou transakci. Souběžný duplicate insert se jednou znovu
načte a skončí buď identickou odpovědí, nebo stabilním konfliktem.

## Idempotence a hash

- raw `Idempotency-Key` se neukládá, pouze jeho SHA-256;
- stejný klíč a stejný payload vrací `200` se stejným resource;
- stejný klíč s jiným payloadem vrací `409 idempotency_conflict`;
- jiný klíč pro už existující organizaci se stejným payloadem také vrátí existující resource;
- jiný payload pro stejné organization UUID vrací `409 organization_link_conflict`;
- payload hash používá `sha256-canonical-json-v1` a shoduje se se sdílenými fixtures.

## Daňová a autorizační bezpečnost

`vatStatus: null` zůstává explicitně nevyplněný. Provider neodvozuje plátcovství z DIČ a
organization link zůstává ve stavu `onboarding/incomplete`. Nový owner nikdy nedostane globální
`admin` ani `accountant`; oprávnění k firmě plyne pouze z `supplier_owner` membershipu.
Existující globální admin nebo deaktivovaný účet se automaticky nepřipojí a vrátí
`user_link_conflict`.

## Capability

Po dokončení endpointu provider inzeruje:

```json
{"organizationProvisioning": true}
```

`userProvisioning`, `sso`, `clientUpsert`, `invoiceDraft` a ostatní navazující funkce zůstávají
`false`, dokud neprojdou vlastními end-to-end slice a cross-repo smoke testem.

## Ověření

- unit test kanonického hashe proti sdílené fixture;
- striktní validace payloadu, required-nullable polí a neznámých polí;
- middleware test platform subjectu a scope;
- action test stabilní obálky, HTTP statusů a response headers;
- MariaDB integration test create/retry/new-key/conflict a všech vazeb;
- PHPStan a oba OpenAPI kontrakty.

## Další krok

Organization update a samostatný user upsert/revoke jsou implementované v navazujícím
[`r2-tenant-synchronization.md`](r2-tenant-synchronization.md). `userProvisioning` zůstává
vypnutý do consumer přechodu z obecného `organization:write` na dedicated `user:write` scope.
