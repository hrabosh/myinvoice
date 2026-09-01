# R2 tenant synchronization — implementovaný organization update a user membership

> Stav: provider endpointy a databázové invarianty hotové; `userProvisioning` čeká na dedicated scope v consumeru
> Datum: 2026-09-01

## Endpointy

```text
PUT    /api/integrations/revizior/v1/organizations/{organizationUuid}
Scope: organization:write

PUT    /api/integrations/revizior/v1/organizations/{organizationUuid}/users/{userUuid}
DELETE /api/integrations/revizior/v1/organizations/{organizationUuid}/users/{userUuid}
Scope: user:write
```

Všechny tři operace vyžadují tenantový service assertion, jehož `sub` se přesně shoduje
s `organizationUuid` v cestě. Platform subject ani scope pro jinou operaci tenantová data
neotevře. DELETE je idempotentní: už neaktivní nebo neexistující user link v existující
organizaci vrací `204` bez další změny.

## Synchronizace organizace

- mění jen živé údaje `supplier`; snapshoty historických dokladů zůstávají beze změny;
- `vatStatus: null` znovu znamená neznámý režim, nikdy odhad z DIČ;
- `active=false` přepne organization link na `suspended`, reaktivace jej vrátí do
  `onboarding` nebo `active` podle onboarding state;
- přechod do `suspended` atomicky odebere tenant memberships a zvýší session version;
  reaktivace obnoví pouze memberships stále aktivních external user linků;
- starší `sourceUpdatedAt` je bezpečný no-op, takže opožděná zpráva nevrátí starší data;
- zápis supplieru, organization linku a activity logu je jedna transakce.

## Synchronizace uživatele

- externí user UUID je primární identita; e-mail se používá jen pro bezpečné připojení
  existujícího aktivního neadmin účtu;
- kolize e-mailu nebo interní identity s jiným external linkem vrací
  `409 user_link_conflict`;
- povolené role jsou pouze `supplier_owner`, `accountant` a `readonly`;
- globální `users.role` se nikdy nepovýší a nový účet vzniká jako `readonly`;
- aktivní snapshot atomicky upsertuje `user_suppliers` a `revizior_user_links`;
- suspendovaná organizace nepřijme nový aktivní membership, revoke zůstává dostupný;
- změna role, revoke nebo reaktivace zvýší `session_version`;
- revoke odstraní pouze tenant membership, zachová uživatele i historické reference a
  další memberships;
- opakovaný stejný payload podle canonical SHA-256 nevytváří další audit noise;
- starší `sourceUpdatedAt` je no-op.

Migrace `0153_revizior_user_provisioning.sql` přidává nullable canonical `payload_hash`.
NULL zachovává upgrade kompatibilitu ownerů vytvořených starším organization provisioningem.

## Capability gate a consumer návaznost

Provider zatím ponechává `userProvisioning=false`. Consumer v aktuálním stavu posílá na user
PUT/DELETE scope `organization:write`, zatímco kanonický kontrakt od začátku vyžaduje užší
`user:write`. Provider z bezpečnostních důvodů širší scope jako alias nepřijímá.

Před zapnutím capability musí ReviziOR backend:

1. přidat `user:write` do enumu service assertion scopes;
2. použít jej výhradně v `syncUser()` a `revokeUser()`;
3. aktualizovat scope unit testy;
4. projít cross-repo upsert → role change → revoke → retry smoke testem.

Teprve potom se `userProvisioning` přepne na `true`; backend následně může spustit
`revizior:invoicing:sync-members` pro backfill existujících členů.
