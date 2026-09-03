# R2 service foundation — implementovaný bezpečnostní základ

> Stav: service assertion, replay store, capabilities probe, organization provisioning a tenant synchronization provideru hotové
> Datum: 2026-09-01

## Hotovo

- produkční dependency `web-token/jwt-framework` 4.2.2 s explicitním RS256 verifierem;
- exact issuer, audience a nakonfigurovaný `kid`; algoritmus se nevyjednává z tokenu;
- povinné `sub`, UUID `jti`, `iat`, `nbf`, `exp`, scope a shoda chráněného
  `request_id` s hlavičkou `X-Request-Id`;
- maximální TTL assertionu 60 sekund a konfigurovatelný clock skew omezený na 30 sekund;
- perzistentní one-time replay guard přes hash `jti` v MariaDB, nikoli pouze Redis;
- samostatný service-auth middleware před PAT/session middleware; běžný PAT integrační
  namespace neotevře;
- stabilní v1 success/error obálka bez interních exception message a bez logování tokenu;
- chráněný `GET /api/integrations/revizior/v1/capabilities` dostupný pouze v managed režimu;
- capabilities hlásí pouze skutečně dokončené integrační funkce; po navazujícím slice je
  `organizationProvisioning=true`, ostatní rozpracované funkce zůstávají `false`;
- idempotentní migrace `0152` zakládá organization/user links, idempotency keys a security nonces;
- OpenAPI popis oddělené service autentizace a capability response.

## Konfigurace

```text
MYINVOICE_DEPLOYMENT_MODE=revizior_managed
MYINVOICE_REVIZIOR_SERVICE_ISSUER=https://app.revizior.cz
MYINVOICE_REVIZIOR_SERVICE_AUDIENCE=https://fakturace.revizior.cz/api/integrations/revizior/v1
MYINVOICE_REVIZIOR_SERVICE_KEY_ID=revizior-service-2026-01
MYINVOICE_REVIZIOR_SERVICE_PUBLIC_KEY=/data/private/revizior-service.pub
MYINVOICE_REVIZIOR_SERVICE_CLOCK_SKEW=5
```

Provider drží pouze veřejný PEM. Privátní service klíč zůstává v ReviziOR backendu.
Soubor klíče se necommituje a musí být připojený jako secret nebo uložený v perzistentním
data volume.

## Capability probe scope

Capability probe přijímá pouze kanonický scope `capabilities:read`. Consumer přechod na tento
nejmenší scope dokončil a dřívější kompatibilní výjimka pro `organization:provision` byla
odstraněna po úspěšném cross-repo handshake testu.

## Další slice R2

1. ~~doplnit v consumeru dedicated `user:write` scope a dokončit cross-repo membership smoke~~ — hotovo 2026-09-02;
2. ~~zapnout `userProvisioning`~~ — hotovo; consumer backfill přes `revizior:invoicing:sync-members`;
3. ~~R3: upsert klienta, koncept dokladu, reconciliation read~~ — hotovo 2026-09-02, viz [`r3-client-upsert.md`](r3-client-upsert.md) a [`r3-invoice-draft.md`](r3-invoice-draft.md);
4. migration integrační testy pro clean DB, upgrade a souběh;
5. SSO ticket s odděleným audience a signing key;
6. capability `sso` zapnout až po
   zeleném cross-repo smoke testu.

Organization provisioning, organization update i user upsert/revoke jsou dostupné a inzerované
(`organizationProvisioning`, `userProvisioning`), viz
[`r2-tenant-synchronization.md`](r2-tenant-synchronization.md). SSO ani fakturace zatím
dostupné nejsou.

## Ověření slice

- focused R2 security/config testy: 17 testů / 83 assertions;
- unit + architecture PHPUnit: 1 724 testů / 5 654 assertions, bez failure
  (67 DB-dependent skipů, 1 existující deprecation);
- PHPStan level 0: bez chyb;
- `composer validate` a `composer audit --locked`: bez chyb a advisories;
- oba OpenAPI soubory: strict parsing, bez duplicitních klíčů a dangling local `$ref`;
- produkční Docker image včetně frontend buildu a manuálu: zelený;
- migrace `0152`: aplikovaná přes `migrate.php`, opakovaný běh bez pending migrací;
- cross-network smoke z ReviziOR Docker sítě: první podepsaný probe `200`, opakování
  stejného `jti` `401 service_token_replayed`; health z backend kontejneru je zelený.
