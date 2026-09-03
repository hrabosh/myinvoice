# MyInvoice.cz — Source dokumentace

> Specifikace fakturačního systému pro doménu **myinvoice.cz** (prod) a **dev.myinvoice.cz** (vývoj).
> Tato složka obsahuje **pouze dokumentaci** — žádné soubory odsud nejdou do produkce.

## Co je MyInvoice.cz

Multi-supplier fakturační systém (jedna instalace = N dodavatelů / IČO s izolovanými daty):
- **Backend:** PHP 8.5 + Slim 4.13 + PHP-DI 7 + Twig 3.10 + Monolog 3.7 + MariaDB 10.6+ (instance `c:/inetpub/MariaDB`, db `myinvoice`)
- **Frontend:** Vue 3.5 + Vite 8 + Tailwind 4 + Pinia 3 + vue-router 5 + vue-i18n 11 + VueUse 14 + axios 1.16 + TypeScript 5.7 (Composition API)
- **PDF:** mPDF 8.2, **QR platba:** rikudou/czqrpayment 5 (CZK SPAYD) + smhg/sepa-qr-data 3 (EUR SEPA EPC) + chillerlan/php-qrcode 6
- **Mail:** Symfony Mailer 8 (SMTP + DKIM)
- **Grafy:** Chart.js 4 + vue-chartjs 5
- **Build / testy:** Composer 2, pnpm 10 + Node.js 22+, PHPUnit 13, PHPStan 2, vue-tsc 2
- **Exporty pro účetní:** PDF ZIP, **ISDOC 6.0.2**, **Pohoda XML** (Stormware data package), **Stereo XML**
- **Hosting:** IIS i Apache (oba podporované)
- **Konfigurace:** `cfg.php` v rootu repa (+ `cfg.local.php` pro per-env override)
- **Logy:** `log/` v rootu, denní rotace 90 dní
- **Statika (logo, fonty, faktura CSS):** `styles/` v rootu

## Klíčové funkce

1. **First-run setup wizard** — vytvoření admin účtu + prvního dodavatele přes ARES + volitelná sample data
2. **Multi-supplier** — N dodavatelů (firem / IČO) v jedné instalaci, izolovaná data, přepínač v horní liště UI
3. **Klienti** s ARES (IČO) + VIES (DIČ) lookupem, hlavní email + 1-3 fakturační
4. **Zakázky** 1:N pod klientem (název, splatnost, hodinová sazba, limity rozpočtu)
5. **Typy dokladů**: Faktura, **Zálohová faktura (proforma)**, Dobropis (opravný daňový doklad), Storno
6. **Vystavení daňového dokladu z proformy** s automatickým odečtem zaplacené zálohy
7. Faktury se snapshotem dodavatele/odběratele/banky pro neměnnost po vystavení
8. **Klonování** faktury z předchozí + **hromadná akce „Vystavit znovu"** s auto-inkrementem měsíce v popiscích (`3/2026 → 4/2026`)
9. **Výkaz víceprací** jako 2. strana PDF, propojená s položkou faktury
10. **PDF s QR kódem** pro platbu (CZK SPAYD / EUR SEPA EPC)
11. **Email s PDF přílohou** přes Twig šablony + per-dodavatel `From:` jméno a `Reply-To:` adresa
12. **Upomínky** — manuální tlačítko, hromadná akce, nebo cron `cron-send-reminders.php`
13. **GPC import** bankovních výpisů (KB/FIO/ČSOB/RB/ČS) s SHA256 dedupe + auto-matching
14. **Exporty pro účetní:** PDF ZIP po měsících, **ISDOC 6.0.2**, **Pohoda XML** (per-dodavatel kódy: středisko, činnost, předkontace), **Stereo XML**
15. Reverse charge přepínatelný per klient
16. Číselník DPH s validitou v čase, měny **CZK + EUR** (rozšiřitelný), per-dodavatel bankovní účty
17. **Brute-force ochrana** přes Redis (fallback MariaDB MEMORY) + **MFA**
    pomocí passkeys/WebAuthn nebo TOTP + serverový zámek PWA session
18. **IP allowlist** s podporou IPv4/IPv6 + CIDR (`/24`, `/56`, `/64` …)
19. Activity log všech mutací finančních dat (per-supplier scoped)
20. **CZ + EN** lokalizace UI, faktur i e-mailových šablon

## Dokumenty

| # | Soubor | Obsah |
|---|---|---|
| 01 | [`01-spec.md`](01-spec.md) | **Funkční a technická specifikace** — co systém umí, doménový model, use-cases, bezpečnost, otevřené otázky |
| 02 | [`02-database.md`](02-database.md) | **Databázové schéma** — všechny tabulky, indexy, FK, invariants, příklady dat (vat_rates, currencies) |
| 03 | [`03-architecture.md`](03-architecture.md) | **Architektura a struktura repa** — adresářová struktura, request flow, IIS/Apache config, `cfg.php` formát, závislosti, deployment |
| 04 | [`04-api.md`](04-api.md) | **REST API specifikace** — všechny endpointy, request/response příklady, rate limity, příklad full-flow |
| 05 | [`05-design.md`](05-design.md) | **Design system** — paleta (emerald/zinc), typografie (Inter + Geist Mono), komponenty, wireframy, SVG logo (zdroj v `/styles/logo.svg`) |
| 06 | [`06-roadmap.md`](06-roadmap.md) | **Plán implementace** — milníky M0-M6, akceptační kritéria, rizika, mimo-scope |

## Specializované integrační plány

| Plán | Obsah | Stav |
|---|---|---|
| [`revizior-integration/`](revizior-integration/) | **Kanonické zadání managed nasazení na `fakturace.revizior.cz`**: tenantové role, service API, idempotentní drafty, jednorázové SSO, přílohy, event outbox, bezpečnost, provoz a cross-repo roadmapa s `hrabosh/backend`. R0–R1, service auth, organization provisioning/update a user membership endpointy jsou implementované; user capability čeká na consumer scope. | 🟡 probíhá R2 |

Managed ReviziOR režim je aditivní overlay. Nemění fakturační zdroj pravdy ani základní
standalone rozhodnutí. Rozšiřuje per-supplier oprávnění o `supplier_owner`, která není globální
admin rolí, a drží integrační kód odděleně kvůli bezpečnosti a upstream mergeovatelnosti.

## Doporučený postup čtení

1. **Nový vývojář:** `00 → 01 → 03 → 02 → 04 → 05 → 06`
2. **Implementace:** vezmi M0 z `06-roadmap.md`, koukni do `03-architecture.md` (struktura) + `02-database.md` (migrace), pak `04-api.md` (endpointy)
3. **UI/UX:** `05-design.md` + wireframy v `01-spec.md` kapitola 4
4. **ReviziOR managed integrace:** nejdřív [`revizior-integration/README.md`](revizior-integration/README.md), potom dokumenty `01 → 04`; paralelně číst consumer plán v `hrabosh/backend/docs/features/revizior-invoicing/`

## Konvence

- **Jazyk:** dokumentace česky, kód anglicky (názvy tabulek, tříd, proměnných)
- **Datumy v dokumentech:** vždy absolutní (`2026-04-30`), ne relativní
- **Číslování milníků:** M0, M1, … (sequenčně, nezávisle na verzi)
- **Verzování:** SemVer pro tagy v gitu (`v0.1.0` = po M1)
- **Branching:** `main` = produkce, `develop` = staging, `feature/*` = vývoj

## Stav projektu (2026-05-02)

- 📋 **Specifikace:** ✅ kompletní (verze 0.1)
- 🏗️ **Implementace:** ✅ M0–M6 + M5b + M7 (multi-supplier) + M8 (exports) kompletní
  - M0 bootstrap, M1 auth + setup + IP allowlist, M2 klienti+ARES/VIES
  - M3 faktury draft+editor+dashboard, M4 vystavení+PDF+QR+email
  - M5 klonování+výkaz víceprací+proforma+storno/dobropis+bulk reissue
  - M5b GPC bank import + auto-matching + manuální párování
  - M6 dashboard polish + activity log + users CRUD + settings + číselníky + ZIP export
  - M7 multi-supplier (X-Supplier-Id header, SupplierScope middleware, SupplierGuard, currencies refactor s id PK)
  - M8 ISDOC + Pohoda XML exporty, upomínky (Reminder service + cron), per-dodavatel email branding
- 🚀 **Deployment:** dev na `dev.myinvoice.cz` (IIS), prod čeká na nasazení
- 🐧 **Min. MariaDB:** 10.6+ (po konsolidaci 0001_init.sql, dříve 10.10 kvůli INET6)

### Co zbývá
- PHPUnit testy pro M5–M8 akce
- GPC scanner pro auto-import z adresáře (cron-bank-scan.php zatím spouští matching ruční upload)
- Pixel-perfect snapshot testy PDF rendereru

## Rozhodnuté body

(Viz kapitola 10 v `01-spec.md` pro detaily)

| # | Téma | Volba |
|---|---|---|
| 1 | RBAC | 3 role: admin / accountant / readonly |
| 2 | Storno faktury | Storno (interní) i dobropis (opravný daňový doklad) |
| 3 | Měny | CZK + EUR (číselník rozšiřitelný) |
| 4 | Pohoda / ISDOC export | Ano (Pohoda Stormware XML + ISDOC 6.0.2); Fakturoid odmítnut (nemá stabilní XML import formát) |
| 5 | Periodické faktury | Ne automatické. Místo toho hromadné „Vystavit znovu" pro označené faktury (vytvoří koncepty) |
| 6 | Zálohové faktury | Ano, bez DUZP, varsymbol s prefixem `9` |
| 7 | Vystavení daňového dokladu z proformy | Ano, s odečtem zaplacené zálohy |
| 8 | First-run setup | Wizard při prvním spuštění (admin + dodavatel) |
| 9 | IP allowlist | Volitelný, IPv4 i IPv6 + CIDR prefixy |
