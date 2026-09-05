# R4 — browser SSO

> Stav: hotovo — `sso=true` po zeleném cross-repo smoke testu
> Datum: 2026-09-02

## Endpoint

```text
GET /api/auth/revizior/sso?ticket=<compact RS256 JWS>
```

Mimo service API base path (sem chodí prohlížeč), veřejný v `AuthMiddleware`
i `RoleMiddleware`, ale dostupný **jen** v `revizior_managed` režimu — ve
standalone vrací `404`, aby endpoint neprozrazoval režim nasazení.

## Ověření ticketu

`ReviziorSsoTicketVerifier`:

- **vlastní audience** `…/api/auth/revizior/sso` (nikdy service audience) a
  vlastní `kid`; bez nakonfigurovaného SSO klíče se použije service klíč, ale
  audience se nesdílí nikdy;
- pevné RS256 + `typ: JWT`, algoritmus se z tokenu nevyjednává;
- povinné `iss`, `aud`, `sub`, `organization_id`, `jti`, `iat`, `nbf`, `exp`,
  `purpose=browser_sso`, `target`, `return_to`;
- TTL nejvýš 120 s (consumer vydává 60 s), clock skew z konfigurace, max 30 s;
- `sub` i `organization_id` musí být UUID.

## Spotřebování a session

`ReviziorSsoService` v tomhle pořadí:

1. podpis a čas;
2. **jednorázové `jti`** — `revizior_security_nonces` s hashem `purpose:jti`,
   takže service assertion a SSO ticket nesdílí jmenný prostor; druhé použití
   je `401 sso_ticket_replayed`, i když první redirect proběhl;
3. organization link (neznámá → `sso_organization_unknown`, suspendovaná →
   `sso_organization_suspended`);
4. členství z DB — aktivní `revizior_user_links` **a** aktivní účet **a** řádek
   v `user_suppliers`; role z ticketu se ignoruje (`sso_membership_inactive`);
5. `ReviziorSsoTargetPolicy` — allowlist tvarů + načtení cíle a ověření
   `supplier_id`; cizí i neexistující cíl dávají stejnou odpověď;
6. `ReviziorReturnUrlPolicy` — porovnání celého **originu**, ne hostu;
7. nová session (`auth_method=revizior_sso`, assurance `basic`) — vždy nová,
   takže cizí session v prohlížeči přechod nepřežije;
8. schválená návratová adresa se uloží na session (`sessions.revizior_return_url`,
   migrace `0156`) a `/api/auth/me` ji vrací místo hodnoty z konfigurace.
   „Zpět do ReviziORu" tak nejde přesměrovat úpravou URL;
9. `303 See Other` na relativní cestu **bez ticketu**, `Cache-Control: no-store`.

Audit: `revizior.sso.consumed` (organizace, uživatel, cíl — nikdy ticket).

## Cíle

| Kontrakt | Obrazovka | Ověření |
|---|---|---|
| `/invoices`, `/clients`, `/projects` | stejná | — |
| `/invoices/{id}`, `/invoices/{id}/edit` | stejná | `invoices.supplier_id` |
| `/clients/{id}` | stejná | `clients.supplier_id` |
| `/projects/{id}` | stejná | `clients.supplier_id` přes zakázku |
| `/price-list` | `/admin/price-list` | — |
| `/bank` | stejná | — |
| `/settings/supplier` | **není** | `403 sso_target_unavailable` |

Kontraktní cesta se překládá na skutečnou obrazovku; kontrakt tím zůstává
stabilní. `/settings/supplier` v managed UI zatím neexistuje (tenantová
obrazovka fakturační identity je R6) — odmítá se s vlastním kódem, aby
consumer poznal rozdíl mezi „nesmíš" a „ještě to tu není".

## Ticket v logu

Ticket je krátkodobé pověření v query stringu, takže `docker/nginx.conf` má pro
tuhle jednu cestu `log_format redacted` (`?<redacted>` místo query) v samostatné
named location — `access_log` se bere z location, kde request skončí, ne z té,
která ho namatchovala. Reverse proxy před instalací musí udělat totéž.

## Konfigurace

```text
MYINVOICE_REVIZIOR_SSO_AUDIENCE=https://fakturace.revizior.cz/api/auth/revizior/sso
MYINVOICE_REVIZIOR_SSO_KEY_ID=revizior-sso-2026-01
MYINVOICE_REVIZIOR_SSO_PUBLIC_KEY=/data/private/revizior-sso.pub
```

Pro lokální vývoj `MYINVOICE_REVIZIOR_ALLOW_INSECURE_RETURN=true`
a `MYINVOICE_REVIZIOR_INSECURE_RETURN_PORTS=8090`: povolí `http` návratovou
adresu **jen** na loopback hostu a **jen** mimo produkční `app.env`. Dvě
nezávislé podmínky schválně — přepnutí jedné produkci neotevře.

## Cross-repo smoke (2026-09-02)

| Krok | Výsledek |
|---|---|
| `probe` | `sso` ano |
| „Otevřít fakturaci" z ReviziORu | `303` na `/invoices`, host-only session cookie, `Cache-Control: no-store` |
| druhé použití téhož ticketu | `401 sso_ticket_replayed` |
| `/api/auth/me` s vydanou session | `owner@demo.cz`, `supplier_owner`, supplier 1, `returnUrl` ze session |
| potvrzení podkladu → editor | `303` na `/invoices/4/edit` |
| access log | `GET /api/auth/revizior/sso?<redacted>` |

Smoke odhalil dvě chyby, obě opravené: `RoleMiddleware` má vlastní allowlist
(bez něj request skončil na `401` dřív, než se ticket ověřil) a `access_log`
v `location =` se neuplatní, když `try_files` skočí do named location.

## Ověření

- unit: verifier (12 testů), return URL policy (12), architektonický kontrakt (6);
- integrační `SsoTest` nad MariaDB (5 testů): session a uložená návratová adresa,
  replay, cizí cíl a `/settings/supplier`, klient jako cíl, cizí `return_to`,
  odebrané členství a suspendovaná organizace;
- celá suite a PHPStan level 0 — viz záznam v `docs/AI_CHANGELOG.md` consumeru.

## Další krok

~~R5 event outbox~~ — hotovo, viz [`r5-event-outbox.md`](r5-event-outbox.md).
Zbývá R6: přílohy a dokončení managed UI včetně `/settings/supplier`.

## MFA: managed session stojí mimo lokální politiku

Když má instalace zapnuté povinné MFA (`auth.require_mfa`), platí to pro
**lokální přihlášení heslem** — typicky správce instalace. Session založená
spotřebovaným SSO ticketem je z té politiky vyňatá.

Důvod: managed uživatel u poskytovatele žádné heslo ani faktor nemá a mít nemá.
Jeho identitu i sílu přihlášení ručí ReviziOR, který ticket podepsal; druhý
faktor navíc by znamenal enrollment do systému, do kterého se uživatel
nepřihlašuje. Bez výjimky skončí každý business request managed uživatele na
`401 mfa_reauthentication_required` a uživatel je z fakturace zavřený ven bez
cesty zpátky — přesně to se stalo po první ostré aktivaci (2026-09-05).

Výjimka je úzká: váže se na `auth_method = 'revizior_sso'`, ne na roli ani na
tenanta. Hlídá ji `RequireMfaMiddlewareTest`.
