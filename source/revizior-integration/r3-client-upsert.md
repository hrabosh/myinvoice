# R3 slice 1 — upsert klienta přes sdílený ClientWriter

> Stav: hotovo — `clientUpsert=true` po zeleném cross-repo smoke testu
> Datum: 2026-09-02

## Endpoint

```text
PUT /api/integrations/revizior/v1/organizations/{organizationUuid}/clients/{clientUuid}
Scope: client:write
Subject: organizationUuid
```

## Sdílená služba

`MyInvoice\Service\Client\ClientWriter` je vytažený z `CreateClientAction` a
`UpdateClientAction` (§2.2 zadání). Obě UI actions jsou teď jen mapování
HTTP → služba → JSON; validace (`Validation::client`), e-mailové kontakty,
activity log i pořadí kroků zůstaly. Chyby vrací `ClientWriteException`
s druhem (`validation` / `integrity` / `contacts` / `not_found`), který UI mapuje
na dosavadní kódy a statusy a integrace na názvy polí z kontraktu.

`ReviziorSharedFlowCharacterizationTest` popisuje nový tok (actions bez
`Validation::client`, pořadí kroků ve writeru).

## Integrace

`ReviziorClientSynchronizer`:

- identita = `revizior_client_links` (organization link + UUID); žádné spojování
  podle IČO ani e-mailu;
- stejný kanonický hash nebo starší `sourceUpdatedAt` → `unchanged` bez zápisu
  a bez audit noise;
- nový klient vzniká přes `ClientWriter::create`, existující se mění přes
  `ClientWriter::update`, kterému se předá celý současný řádek přepsaný jen
  poli, která ReviziOR vlastní (název, IČO, DIČ, ulice, jazyk, fakturační
  kontakt). Měna, splatnost, kategorie, sazba, poznámka a další nastavení z UI
  zůstávají;
- `active=false` archivuje, `true` odarchivuje; doklady zůstávají;
- link, klient, kontakty i audit (`revizior.client.upserted`) jsou jedna
  transakce; klient jiného dodavatele → `409 client_link_conflict`, suspendovaná
  organizace → `409 organization_suspended`.

Migrace `0154_revizior_client_links.sql` (unikát na UUID i na `client_id`, FK na
`clients`).

## Adresa: doplnění kontraktu

ReviziOR zná adresu klienta jako **jeden řádek** a posílá `city`, `postalCode`
a `countryCode` jako `null`. OpenAPI proto dostalo schéma `ClientAddress`
(`street` povinný, ostatní `null`-able) — aditivní uvolnění, konzument
s úplnou adresou funguje beze změny. Význam `null` je „zdroj to neví":

- nový klient: město a PSČ zůstanou prázdné, země = země dodavatele (stejný
  default jako klientský formulář); uživatel doplní ve fakturaci před
  vystavením dokladu;
- existující klient: `null` hodnotu doplněnou ve fakturaci **nepřepíše**.

`ClientWriter` má pro tuhle cestu volbu `allowIncompleteAddress`; UI ji nikdy
nezapíná. Prázdný řetězec kontrakt odmítá (`empty_string_use_null`).

Doporučení pro consumer: strukturovaná adresa klienta v ReviziORu by tenhle
kompromis odstranila.

## Cross-repo smoke (2026-09-02)

Z ReviziOR backendu přes reálnou cestu (`revizior:invoicing:probe`,
`revizior:invoicing:sync-client`):

| Krok | Výsledek |
|---|---|
| první synchronizace | `201 created`, klient u dodavatele 1, kontakt, link, audit `client.created` + `revizior.client.upserted` |
| opakování beze změny | consumer poskytovatele vůbec nevolá (otisk sedí) |
| změna názvu v ReviziORu | `200 updated`, `client.updated`, druhý `revizior.client.upserted` |

## Ověření

- unit: validátor (6 testů), action (5), integrační `ClientSynchronizationTest`
  (4 testy / 50 assertions nad MariaDB), architektonický kontrakt;
- celá suite: 2 202 testů / 7 578 assertions, bez failure; PHPStan level 0 bez
  chyb; `tools/validateReviziorContract.py` OK;
- lokální běh testů: `cmd/local-test.sh` (PHP 8.5 kontejner nad checkoutem,
  DB `myinvoice_ci` na stacku z `docker-compose.yml`, `cfg.php` jako v CI).

## Další slice R3

1. ~~invoice draft + `GET /invoices/{externalInvoiceKey}`~~ — hotovo, viz
   [`r3-invoice-draft.md`](r3-invoice-draft.md);
2. price resolution — odloženo, consumer endpoint nevolá (cenu doplňuje uživatel).
