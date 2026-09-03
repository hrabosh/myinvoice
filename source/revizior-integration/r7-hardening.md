# R7 — hardening: co je ověřené

> Stav: 🟡 probíhá — ověřená část níž, zbytek je operativa v `hrabosh/backend#45`
> Datum: 2026-09-03

## Rotace podpisových klíčů bez odstávky

Verifier service assertionu i SSO ticketu přijímá **mapu** `kid => cesta k PEM`
(`service_auth.public_keys`, `sso.public_keys`, env `MYINVOICE_REVIZIOR_*_PUBLIC_KEYS`
jako JSON). Jednoduchá dvojice `key_id`/`public_key_path` platí dál a přidá se do
mapy — konfigurace se tedy měnit nemusí.

Nácvik proti běžící instalaci:

| Krok | Výsledek |
|---|---|
| přidán druhý veřejný klíč k poskytovateli | starý `200`, nový `200`, neznámý `kid` `401` |
| consumer přepnut na nový klíč | `probe` `200` |
| starý klíč odebrán | consumer dál `200`, starý klíč `401` |

Žádný krok nevyžadoval odstávku ani současnou změnu na obou stranách.

## Souběh a zátěž

Proti běžící instalaci přes podepsané HTTP volání:

| Test | Výsledek |
|---|---|
| 50 souběžných konceptů se stejným `Idempotency-Key` | 1× `201`, 49× `200`, vznikla **jedna** faktura, `event_sequence` 1 |
| 20 konceptů s různými klíči (10 paralelně) | 20× `201`, 20 faktur, 20 vazeb, 20 událostí |
| 4 paralelní outbox dispatchery nad 22 událostmi | 22 doručeno, každá právě jednou (1 pokus na událost), consumer inbox +22 |
| tentýž assertion 3× souběžně | 1× `200`, 2× `401 service_token_replayed` |
| dva tenanti se **stejným** UUID klienta | dva oddělení klienti u dvou dodavatelů |
| doklad tenanta A pod tenantem B | `404`; subjekt B na cestě A `403` |

## Limity

| Test | Výsledek |
|---|---|
| příloha 21 MB | `413` |
| tělo webhooku 300 kB (consumer) | `413` |
| webhook bez podpisu | `401` |

Rate limit webhooku u consumeru je 600 požadavků za minutu, takže dávka 40 jím
neprojde jako přetečení — limit chrání před smyčkou v outboxu, ne před běžným
provozem.

## Závislosti

`composer audit --locked` na obou stranách: bez nálezů.

## Co zbývá

Operativa, kterou nelze udělat z vývojového prostředí: threat-model review,
penetrační test, staging s oddělenými klíči, SLO a alerting nad outboxem, PITR
a nácvik obnovy, incident runbook. Vedeno v `hrabosh/backend#45`.
