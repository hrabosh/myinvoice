# R2 service foundation — implementovaný bezpečnostní základ

> Stav: service assertion, replay store a fail-closed capabilities probe hotové; organization provisioning navazuje samostatným dokončeným slice
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

1. organization update a user upsert/revoke se zvýšením `session_version`;
2. migration integrační testy pro clean DB, upgrade a souběh;
3. SSO ticket s odděleným audience a signing key;
4. capability `userProvisioning`/`sso` zapnout jednotlivě až po
   zeleném cross-repo smoke testu.

Organization provisioning je dostupný. Backend stále nesmí považovat obecnou synchronizaci
uživatelů, SSO ani fakturaci za dostupnou, dokud je provider neinzeruje v capabilities.

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
