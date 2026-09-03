# R2 tenant synchronization — implementovaný organization update a user membership

> Stav: hotovo — `userProvisioning=true` po zeleném cross-repo smoke testu
> Datum: 2026-09-02

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

## Capability gate a cross-repo smoke

Consumer přešel na kontraktní `user:write` (`InvoicingAssertionScope::USER_WRITE`, použitý
výhradně v `syncUser()` a `revokeUser()`); provider širší `organization:write` jako alias dál
nepřijímá.

Cross-repo smoke test proběhl 2026-09-02 z ReviziOR Docker sítě (`revizior-invoice.local`,
consumer `hrabosh/backend` větev `feat/invoicing-context`), celý přes reálné consumer cesty
(aktivace ve web UI, `revizior:invoicing:probe`, `revizior:invoicing:sync-members`):

| Krok | Výsledek u providera |
|---|---|
| provisioning organizace | jeden supplier, owner s globální rolí `readonly` a membership `supplier_owner`, link `onboarding/incomplete` |
| upsert 4 členů | `supplier_owner` ×2, `accountant`, `readonly`; `session_version=1` |
| změna role (manager → viewer) | `accountant` → `readonly`, `session_version=2` |
| revoke (deaktivace uživatele) | `active=0`, `revoked_at` vyplněno, membership odebrán, `session_version=2` |
| retry stejné dávky | žádný další `revizior.user.upserted` audit záznam |
| reaktivace + návrat role | membership obnoven, `session_version=3` |

Teprve po tomto smoke testu provider přepnul `userProvisioning` na `true`. Consumer si
snapshot schopností u už propojených organizací obnoví příkazem `revizior:invoicing:probe`
a členy dožene `revizior:invoicing:sync-members`.

Smoke odhalil chybu na straně consumeru, ne provideru: `probe` do té doby snapshot na vazbě
neobnovoval, takže organizace aktivovaná před zapnutím capability by ji nikdy neviděla.
Opraveno v consumeru (`RefreshInvoicingCapabilitiesHandler`).
