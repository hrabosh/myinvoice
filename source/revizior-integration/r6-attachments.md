# R6 slice 1 — přílohy dokladu

> Stav: hotovo — `attachments=true` po zeleném cross-repo smoke testu
> Datum: 2026-09-03

## Endpoint

```text
PUT /api/integrations/revizior/v1/organizations/{organizationUuid}/invoice-drafts/{externalInvoiceKey}/attachments/{externalAttachmentKey}
Scope: attachment:write · Content-Type: application/pdf
Digest: sha-256=<base64> (povinná) · X-File-Name: <zobrazovaný název> (volitelná)
```

Scope je **vlastní**, ne `invoice:write`: příloha se streamuje na disk, takže
token na úpravu dokladu na ni stačit nemá. Consumer scope doplnil
(`InvoicingAssertionScope::ATTACHMENT_WRITE`).

## Kontroly v pořadí, na kterém záleží

1. `Content-Type` musí být `application/pdf`;
2. deklarovaná délka proti limitu 20 MiB (dřív, než se cokoli čte);
3. organizace, doklad a jeho vlastnictví; storno přílohu nepřijímá;
4. **existující vazba** — stejný klíč a digest vrátí původní přílohu bez
   přenosu i bez zápisu; jiný digest je `409`;
5. tělo se čte po 256 KiB blocích do dočasného souboru a průběžně se hashuje
   (překročení limitu utne přenos, celý soubor se nikdy nedrží v paměti);
6. skutečně přečtená délka proti `Content-Length`;
7. digest proti obsahu;
8. `%PDF-` magic bytes **a** `finfo` MIME;
9. teprve pak přesun na místo a zápis do DB v jedné transakci.

Selhání kterékoli kontroly nenechá v úložišti soubor ani řádek v DB.

## Jméno souboru

V úložišti je vždy `revizior-{externalAttachmentKey}.pdf` — generuje ho
poskytovatel. `X-File-Name` slouží jen jako zobrazovaný název a prochází
sanitizací (`basename`, bílá listina znaků, vynucená přípona), takže
`../../../../etc/passwd` skončí jako `passwd.pdf`.

## Vazba

Migrace `0158_revizior_attachment_links.sql`: unikát na
`(invoice_link_id, external_attachment_key)` i na `attachment_id`, FK na
`invoice_attachments` s `ON DELETE CASCADE` (smazání dokladu uklidí i vazbu).
Audit `revizior.invoice.attachment_stored` nese klíč, digest a velikost —
nikdy obsah.

## Cross-repo smoke (2026-09-03)

Přes reálnou cestu: podklad z podepsané revizní zprávy → koncept → fronta
consumeru nahrála PDF.

| Krok | Výsledek |
|---|---|
| `probe` | `attachments` ano |
| nahrání | `invoice_attachments` 1 řádek, 193 B, `application/pdf`, `uploaded_by` NULL |
| úložiště | `/data/storage/invoices/sup-1/attachments/5/revizior-<key>.pdf`, začíná `%PDF-1.7` |
| vazba | `revizior_attachment_links` s klíčem a digestem |
| consumer | zdroj ve stavu `uploaded`; ostatní zprávy bez PDF zůstaly `skipped` |

Smoke odhalil chybu **na straně consumeru**: jeho zdroj přílohy volal `rewind()`
na streamu z S3, který seek neumí — na produkci by se příloha nenahrála nikdy.
Opraveno tam (buffer do `php://temp`).

## Ověření

- integrační `AttachmentTest` nad MariaDB a skutečným úložištěm (13 assertions ×
  6 testů): uložení a idempotentní opakování, konflikt digestu, podvržený
  content type, zkrácené tělo, chybějící `Digest`, překročený limit, traversal
  v názvu, neznámý doklad;
- architektonický kontrakt: scope, streamování, žádné `getContents()`, migrace;
- celá suite a PHPStan level 0 — viz záznam v `docs/AI_CHANGELOG.md` consumeru.

## Co z R6 zbývá

~~Tenantová obrazovka fakturační identity~~ — hotovo, viz
[`r6-managed-ui.md`](r6-managed-ui.md). Zbývá odkaz „Otevřít revizi
v ReviziORu" u dokladu se zdrojovou vazbou.
