# 6. První spuštění (setup wizard)

Po čerstvé instalaci je celá aplikace **zamčená na setup wizard**. Žádný jiný
endpoint kromě setup endpointů a healthchecku neodpovídá. Wizard je jednorázový
— jakmile vznikne první admin účet, wizard zmizí a obnoví se až po `reset.php`.

Wizard má **3 kroky** (admin → dodavatel → sample data) a po dokončení tě
**automaticky přihlásí**.

> 🛈 V řízené instalaci **ReviziOR Fakturace** se veřejný setup wizard
> nepoužívá. Firmu i uživatele zakládá ReviziOR a aplikace nabídne bezpečný
> odkaz **Zpět do ReviziORu**. Pokud se v tomto režimu setup zobrazí, kontaktuj
> správce nasazení.

## 6.1 Krok 1 — Administrátor

![Setup wizard krok 1](img/03_setup_admin.webp)

Vytvoříš první uživatelský účet s rolí `admin` (plná práva).

| Pole | Význam |
|---|---|
| Jméno | Tvoje jméno (zobrazí se v UI a v aktivity logu) |
| E-mail | Login + adresa pro reset hesla / system notifikace |
| Heslo | Min. 12 znaků, indikátor síly (slabé / střední / silné). Bez maxima — passphrase je OK. |
| Heslo znovu | Ověřovací duplicita |
| Vyžadovat silné MFA | Po dokončení wizardu musí admin zaregistrovat passkey nebo zapnout TOTP |

Povolené jsou obě metody — uživatel si na stránce `/setup-mfa` vybere. Zúžit
výběr jde až v konfiguraci přes `auth.allowed_mfa_methods`, viz
[39. Bezpečnost](39_Bezpecnost.md).

Klikni **Další**.

> 💡 Tip: Použij passphrase 4–5 slov místo krátkého složitého hesla. „korelace
> medvědí dýně přístav 2026" je odolnější vůči brute-force než „Hu1@n!".

## 6.2 Krok 2 — Dodavatel

![Setup wizard krok 2](img/03_setup_dodavatel.webp)

Vyplníš údaje o **prvním dodavateli** (firmě nebo OSVČ), za kterého budeš
fakturovat. Můžeš jich později přidat víc — viz [35. Multi-supplier](35_Multi_supplier.md).

| Sekce | Popis |
|---|---|
| Firma / jméno OSVČ | Bude v hlavičce všech vystavených PDF |
| IČO | Klikni vedle na **Načíst z ARES** a předvyplní se název, DIČ, adresa, právní forma. ARES je oficiální veřejný registr — fungující v ČR. |
| DIČ | U OSVČ neplátce nech prázdné |
| Adresa | Ulice, město, PSČ, země — pro fakturační hlavičku |
| E-mail / telefon | Kontakt pro klienta |
| Bankovní účet | První účet pro CZK — číslo + bank kód (např. `1000000005 / 0100` pro KB) |

Klikni **Další**.

> ⚠️ Bankovní účet musí projít **mod-11 kontrolou** (povinný formát českých
> účtů). Pokud zadáš neplatné číslo, QR platba se ve faktuře nezobrazí. Příklad
> platného testovacího čísla: `1000000005 / 0100`.

## 6.3 Krok 3 — Sample data (volitelné)

![Setup wizard krok 3](img/03_setup_sample.webp)

Checkboxem si můžeš nechat vygenerovat **testovací sadu dat** pro vyzkoušení
systému před tím, než začneš fakturovat naostro:

- 5 klientů (různé země, jazyky, měny — CZ, SK, DE)
- 8 zakázek (1–3 na klienta)
- 20 vystavených faktur za poslední 2 měsíce
- 4 dobropisy

Sample data **nejdou doinstalovat zpětně** — pokud teď přeskočíš a později
zjistíš, že je chceš, dostaneš `409 setup_done` (ochrana proti přepsání reálných
faktur). Reset přes `php api/bin/reset.php` smaže všechno a wizard se objeví znovu.

**Odebrání ukázkových dat.** Jakmile sis sadu vygeneroval a chceš začít načisto
jen s vlastními daty, najdeš v **Systém → Nastavení** sekci **Ukázková data** s
tlačítkem *Odebrat ukázková data* — smaže přesně vygenerovanou sadu a tvoje
vlastní záznamy nechá být. Sekce se zobrazí jen tehdy, když nějaká ukázková data
existují. Alternativně z příkazové řádky `php api/bin/reset.php --keep-users-supplier`
smaže všechna byznys data, ale ponechá přihlášení a nastaveného dodavatele.

Klikni **Dokončit**. Pokud není silné MFA povinné, wizard tě automaticky
přihlásí a přesměruje na [Přehled (dashboard)](08_Prehled.md). Při povinném MFA
dostaneš nejprve omezenou stránku `/setup-mfa`; plný přístup vznikne až po
registraci jedné z povolených metod. Passkey vyžaduje stabilní HTTPS hostname
v `app.url` (lokálně je podporované `http://localhost`).

## 6.4 Co dál po setupu

1. Otevři **Systém → Nastavení** a doplň, co wizard nepokryl: e-mail kontakt,
   doplnění více bankovních účtů — viz [36. Nastavení](36_Nastaveni.md).
2. **Systém → Číselníky → Měny** — pokud fakturuješ i v EUR, doplň druhý účet
   (IBAN + BIC).
3. **Systém → Uživatelé** — pokud má systém používat někdo další (účetní),
   přidej ho.
4. **Systém → E-mail šablony** — uprav uvítací text e-mailů (faktury,
   upomínky).
