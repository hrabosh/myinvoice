# Third-party notices

MyInvoice contains or depends on third-party software. The application source is distributed
under the MIT License in [`LICENSE`](LICENSE); dependencies remain under their own licenses.
The exact dependency versions are locked in `api/composer.lock` and `web/pnpm-lock.yaml`.

The PHP dependency graph is predominantly MIT/BSD/ISC licensed. The following locked runtime
packages need particular notice in binary/container release review because their terms are not
MIT/BSD-style:

| Package | Locked-license metadata | Purpose |
|---|---|---|
| `enshrined/svg-sanitize` | GPL-2.0-or-later | SVG sanitization |
| `mpdf/mpdf` | GPL-2.0-only | PDF generation |
| `smalot/pdfparser` | LGPL-3.0 | PDF parsing |
| `chillerlan/php-qrcode` | MIT or Apache-2.0 | QR generation |
| `rikudou/iban` | WTFPL | IBAN handling |

The R0 audit also synchronized the Guzzle family to the advisory-free versions already present
in MyÚčto (`guzzlehttp/guzzle` 7.15.5, `guzzlehttp/promises` 2.5.3 and
`guzzlehttp/psr7` 2.13.1).

Frontend runtime dependencies are MIT licensed according to their locked package metadata;
build tooling additionally includes Apache-2.0/MPL-family packages. Release artifacts must keep
the license files shipped by Composer/npm packages and must not strip notices from bundled code.

Before each release, regenerate the authoritative inventories from the lock files after install:

```bash
cd api && composer licenses --format=json
cd web && pnpm licenses list --prod --json
```

This file is an attribution aid, not a replacement for the complete license texts contained in
the corresponding packages and not legal advice.
