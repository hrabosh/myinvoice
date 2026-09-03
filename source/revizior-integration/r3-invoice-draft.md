# R3 slice 3 — idempotentní koncept dokladu a reconciliation read

> Stav: hotovo — `invoiceDraft=true` po zeleném cross-repo smoke testu
> Datum: 2026-09-02

## Endpointy

```text
POST /api/integrations/revizior/v1/organizations/{organizationUuid}/invoice-drafts
     Scope: invoice:write · Idempotency-Key: invoice-draft:{externalInvoiceKey}
GET  /api/integrations/revizior/v1/organizations/{organizationUuid}/invoices/{externalInvoiceKey}
     Scope: invoice:read
```

## Sdílená služba

`MyInvoice\Service\Invoice\InvoiceDraftCreator` je vytažený z `CreateInvoiceAction`
(§2.2 zadání) a drží původní pořadí kroků: `InvoiceDefaults` → `InvoiceValidation`
→ vlastnictví klienta → auto-klasifikace DPH → `createDraft` + položky →
`InvoiceCalculator::recompute` → kurz k DUZP → `invoice.created`. Action je jen
mapování HTTP → služba → JSON; chyby nese `InvoiceDraftException` s druhem, který UI
mapuje na dosavadní kódy (`validation_failed`, `client_not_found`,
`varsymbol_duplicate`, `integrity_violation`). Sdílený aktor obou služeb je
`MyInvoice\Service\WriteActor`.

## Integrace

`ReviziorInvoiceDraftService::create` v jedné transakci:

1. idempotency záznam (`subject=organizationUuid`, `operation=invoice-draft`,
   hash klíče i payloadu) — stejný klíč + payload vrací uloženou odpověď (`200`),
   stejný klíč + jiný payload `409 idempotency_conflict`;
2. organization link (`404 organization_not_provisioned`, `409 organization_suspended`);
3. existující `revizior_invoice_links` pro `externalInvoiceKey`: stejný payload =
   bezpečné opakování, jiný = `409 invoice_link_conflict` — jeden ReviziOR klíč je
   nejvýš jeden doklad;
4. client link (`404 client_not_linked`, cizí dodavatel `409 client_link_conflict`);
5. překlad kontraktu → tělo pro `InvoiceDraftCreator`: měna kódem (resolve na id
   v rámci dodavatele), sazba DPH z procenta na platnou tuzemskou sazbu k DUZP
   (`invoice_validation_failed` `items.i.vatRate=unknown_vat_rate`), částky jako
   decimal stringy beze změny — počítá MyInvoice;
6. `revizior_invoice_links` (`event_sequence=0`) + `revizior_invoice_sources`
   (typ, UUID, klíč řádku, metadata jen popisek a ceníkový kód);
7. uložená odpověď (snapshot) a audit `revizior.invoice.draft_created`.

`invoices.created_by` je povinná FK; kontrakt v1 nenese, kdo koncept vyvolal, takže
se doklad připíše vlastníkovi tenantu (`supplier_owner` s aktivním linkem), případně
jinému aktivnímu členovi. Bez členství `409 user_membership_inactive`.

`ReviziorInvoiceSnapshotBuilder` dává jeden tvar pro POST, GET i budoucí outbox:
částky v minor units podle desetinných míst měny, `invoiceNumber` = `varsymbol`
(u konceptu `null`), `status` mapovaný na výčet kontraktu (`reminded` → `overdue`,
vystavený po splatnosti → `overdue`, `paid_total` → `partially_paid`), `rawStatus`
původní, `editPath` `/invoices/{id}/edit` u konceptu, `publicUrl` jen s tokenem
a mimo koncept, `pdfUrl` zatím `null`.

Migrace `0155_revizior_invoice_links.sql` (unikát `invoice_id`, unikát
`organization_link_id + external_invoice_key`, zdroje s unikátem na typ/UUID/řádek).

## Co se vědomě odložilo

- **`priceResolution`** — consumer ho nevolá: cenu doplňuje uživatel v mezikroku a
  koncept nese `unitPrice` i `vatRate` explicitně. Endpoint se doplní, až ho
  consumer začne používat (ceník v MyInvoice existuje, `PriceListItemResolver` je
  připravený).
- **`credit_note`** jako typ konceptu — dobropis vzniká z vystaveného dokladu;
  validátor ho odmítá (`invoiceType=unsupported`), dokud provider neinzeruje
  `creditNote`.
- **`pdfUrl`** — PDF vzniká až po vystavení; doplní se s outboxem (R5).

## Cross-repo smoke (2026-09-02)

Z ReviziOR backendu přes reálné UI (podklad z podepsané revizní zprávy → doplnění
ceny → potvrzení):

| Krok | Výsledek |
|---|---|
| `probe` | `invoiceDraft` ano, snapshot obnoven |
| potvrzení podkladu | `201`: doklad 1, klient 2, základ 4 500,00, DPH 945,00, celkem 5 445,00, klasifikace DPH `1`, `created_by` = owner |
| vazby | `revizior_invoice_links` seq 0, `revizior_invoice_sources` typ `revision_report` s UUID zprávy a klíčem řádku |
| idempotence | `revizior_idempotency_keys` `invoice-draft` completed 201 |
| consumer | `invoicing_external_invoice_links`: draft, 544 500 minor, `/invoices/1/edit`, sequence 0 |

Smoke odhalil chybu na consumeru (náhodný klíč řádku podkladu, cena se nikdy
nespárovala) — opraveno tam. Druhé potvrzení téže zprávy založí druhý koncept;
provider to správně nebrání (jiný `externalInvoiceKey`), consumer zatím jen varuje.

## Ověření

- unit: validátor (5), snapshot builder (9), actions (4); integrační
  `InvoiceDraftTest` (2 testy / 39 assertions nad MariaDB); architektonický
  kontrakt; charakterizace `CreateInvoiceAction` → `InvoiceDraftCreator`;
- celá suite: 2 224 testů / 7 717 assertions, bez failure; PHPStan level 0;
  `tools/validateReviziorContract.py` OK; oba OpenAPI dokumenty popisují oba
  endpointy.

## Další krok

~~R4 SSO~~ — hotovo, viz [`r4-browser-sso.md`](r4-browser-sso.md); uživatel se
z ReviziORu dostane rovnou do editoru konceptu. Potom R5 outbox
(`event_sequence` už na linku je).
