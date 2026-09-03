# ReviziOR contract fixtures v1

Tento adresář je provider-side kanonický zdroj kontraktních payloadů pro MyInvoice a
`hrabosh/backend`. Všechny hodnoty jsou syntetické. Fixture se kopírují významově beze změny do
consumer testů; shodu dokládá `hashes.json`.

Kanonický hash `sha256-canonical-json-v1` vznikne takto:

1. objektové klíče se rekurzivně seřadí podle Unicode code pointu;
2. pořadí prvků v polích se zachová;
3. JSON se zapíše jako UTF-8 bez whitespace a bez escapování Unicode;
4. decimal hodnoty zůstávají stringy a částky snapshotu jsou integer minor units;
5. UUID jsou lowercase v kanonickém tvaru;
6. nad výslednými bytes se spočítá SHA-256.

Validace je bez runtime závislostí:

```bash
python3 tools/validateReviziorContract.py
```

`api/openapi-revizior-integration.yaml` používá JSON syntaxi, která je platným YAML 1.2. Díky
tomu validátor spolehlivě odmítne duplicitní klíče a zkontroluje všechny lokální `$ref` bez
přidání YAML parseru do produkční aplikace.
