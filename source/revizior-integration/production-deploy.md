# Produkční nasazení `fakturace.revizior.cz`

> Stav k **2026-09-03**: běží, nasazuje se z `master` přes GitHub Actions.
> Server `revizior.cz` (Hetzner), stejný stroj jako `app.revizior.cz`.
> **Bez Dockeru** — nginx + PHP-FPM 8.5 + MariaDB přímo na stroji.

## Proč bez Dockeru

Na stroji už běží produkční ReviziOR (nginx, PHP-FPM 8.3, PostgreSQL). Druhá
kontejnerová vrstva by přidala vlastní reverse proxy, vlastní logy a vlastní
restart politiku k něčemu, co má jediný proces a jednu databázi. Fakturace se
proto nasazuje stejným způsobem jako backend: release adresář + přepnutí
symlinku.

**PHP 8.5 je na stroji navíc, vedle 8.3.** Instalace 8.5 přepnula systémové
`php` na novější verzi a backend na PHP 8.3 by se rozbil, proto je výchozí verze
vrácená zpátky:

```bash
update-alternatives --set php /usr/bin/php8.3
```

Fakturace nikdy nespoléhá na `php` bez verze — cron i deploy volají
`/usr/bin/php8.5` absolutně. **Composer taky**: běží jako
`/usr/bin/php8.5 $(command -v composer)`, jinak vyhodnotí `php: ^8.5` proti 8.3
a spadne na chybějícím `pdo_mysql`.

## Rozvržení na disku

```
/var/www/fakturace.revizior.cz/
├── repo/                     # git mirror, do něj fetchuje deploy
├── releases/20260903_202726/ # jeden build; drží se poslední tři
├── current -> releases/…     # atomicky přepínaný symlink, root nginxu
└── shared/                   # přežívá release, nikdy není v gitu
    ├── cfg.php               # konfigurace instalace (0640 deployer:www-data)
    ├── data/{log,storage,private}
    └── keys/                 # podpisové klíče integrace (0640)
```

`shared/` vlastní `deployer:www-data`. Release se linkuje na `shared/cfg.php`
a `shared/data`, takže build nikdy nepřepíše konfiguraci ani nahrané soubory.

## Klíče

Integrace používá **tři** klíčové páry a každý má jiný směr:

| Soubor v `shared/keys/` | Kdo podepisuje | Kdo ověřuje | K čemu |
|---|---|---|---|
| `revizior-service.pub` | ReviziOR | fakturace | service assertion (`kid` `revizior-service-2026-09`) |
| `revizior-sso.pub` | ReviziOR | fakturace | jednorázový SSO ticket (`revizior-sso-2026-09`) |
| `callback-private.pem` | fakturace | ReviziOR | podpis událostí z outboxu (`myinvoice-callback-2026-09`) |

Veřejná polovina `callback` páru patří do `INVOICING_WEBHOOK_PUBLIC_KEYS`
na straně ReviziORu. Privátní poloviny obou ReviziOR párů leží jen tam
(`/var/www/revizor/shared/config/invoicing/`). Rotace se dělá překryvem — obě
strany umí mapu `kid → klíč`, viz [`r7-hardening.md`](r7-hardening.md).

## PHP-FPM a nginx

Vlastní pool `/etc/php/8.5/fpm/pool.d/fakturace.conf` běží jako `deployer`
a poslouchá na `/run/php/php8.5-fpm-fakturace.sock`. Limity uploadu jsou 25 MB,
o dva vyšší než `maxAttachmentBytes` (20 MiB), aby limit vracelo API a ne server.

Vhost `/etc/nginx/sites-available/fakturace.revizior.cz` je běžný SPA + PHP
front controller. Jedna věc je nestandardní:

```nginx
location = /api/auth/revizior/sso { try_files /dev/null @api_sso; }
location @api_sso {
    access_log /var/log/nginx/fakturace-access.log fakturace_redacted;
    …fastcgi_pass…
}
```

SSO ticket chodí v query stringu, takže by se otiskl do access logu. Formát
`fakturace_redacted` query string nahradí `?<redacted>`. **`access_log` uvnitř
`location =` nestačí** — `try_files` skočí do jiného bloku a platí log toho
cílového; proto je pojmenovaná lokace.

## CI/CD

Job `deploy` v `.github/workflows/ci.yml` běží na push do `master`, až projdou
`revizior-contract`, `backend` a `frontend`. Prostředí `production` drží
`DEPLOY_HOST`, `DEPLOY_USER` (= `deployer`) a `DEPLOY_SSH_KEY`.

Postup jednoho běhu: fetch do mirroru → `git worktree`-style checkout do nového
`releases/<timestamp>` → `composer install --no-dev` → symlinky na `shared`
→ `pnpm install && pnpm build` → `generateManualHtml.php` → `migrate.php`
→ přepnutí `current` → `sudo systemctl reload php8.5-fpm` → úklid starých
releasů.

Reload FPM je jediná věc, na kterou má `deployer` root právo:

```
# /etc/sudoers.d/fakturace-deploy
deployer ALL=(ALL) NOPASSWD: /bin/systemctl reload php8.5-fpm
```

**Rollback** je přepnutí symlinku zpátky (migrace jsou dopředné, takže rollback
kódu nevrací schéma):

```bash
ln -sfn /var/www/fakturace.revizior.cz/releases/<starší> /var/www/fakturace.revizior.cz/current
sudo systemctl reload php8.5-fpm
```

## Známý stav: Redis je vypnutý

`/api/health` hlásí `"redis": false` a je to **záměr, ne porucha** — instalace
běží na náhradním úložišti v MariaDB (tabulka typu MEMORY), které brute-force
ochrana i rate limity umí použít. Na stroji přitom Redis běží, používá ho
ReviziOR.

Zapnout ho je jen konfigurace v `shared/cfg.php`, ale **musí dostat vlastní
databázi**, jinak si obě aplikace míchají klíče:

```php
'redis' => [
    'enabled' => true,
    'host'    => '127.0.0.1',
    'port'    => 6379,
    'db'      => 3,          // index 0 patří ReviziORu
    'auth'    => '…',        // heslo z REDIS_URL ReviziORu
    'prefix'  => 'myinvoice:',
],
```

Po editaci stačí `sudo systemctl reload php8.5-fpm`; `/api/health` pak vrací
`"redis": true`.

## Cron

Crontab uživatele `deployer`:

```cron
* * * * *  /usr/bin/php8.5 …/current/api/bin/cron-revizior-outbox.php   >> …/shared/data/log/outbox.log 2>&1
17 3 * * * /usr/bin/php8.5 …/current/api/bin/cron-cleanup.php           >> …/shared/data/log/cron.log 2>&1
30 7 * * * /usr/bin/php8.5 …/current/api/bin/cron-send-reminders.php    >> …/shared/data/log/cron.log 2>&1
```

Outbox jede každou minutu. Nedoručená událost se opakuje s backoffem, po
vyčerpání pokusů skončí jako dead-letter a **nezmizí** — stav se čte
`cron-revizior-outbox.php --status`.

## Ověření po nasazení

```bash
curl -s https://fakturace.revizior.cz/api/health                  # {"status":"ok",…,"db":true}
curl -s -o /dev/null -w '%{http_code}\n' \
  https://fakturace.revizior.cz/api/integrations/revizior/v1/capabilities   # 401 bez podpisu
/usr/bin/php8.5 …/current/api/bin/cron-revizior-outbox.php --status
```

Ze strany ReviziORu (`/var/www/revizor/current`, uživatel `deployer`):

```bash
php bin/console revizior:invoicing:probe --env=prod
```

Musí vypsat `organizationProvisioning`, `userProvisioning`, `clientUpsert`,
`invoiceDraft`, `attachments`, `sso`, `eventOutbox`.

## Konfigurace na straně ReviziORu

Produkční `.env.local` (`/var/www/revizor/shared/.env.local`) má blok
`###> Fakturace ###` s adresou poskytovatele, issuerem, `kid` a cestami ke
klíčům. Dvě pasti:

- **Mapa klíčů webhooku musí být v jednoduchých uvozovkách.** Bez nich dotenv
  odstraní vnitřní uvozovky JSONu, `json:` processor spadne a webhook vrací
  `500` místo `401`.
- **Po editaci `.env.local` je nutný `cache:clear --env=prod`.** Symfony si
  parametry drží ve zkompilovaném kontejneru.

Pilotní provoz omezuje `INVOICING_ALLOWED_ORGANIZATIONS` (CSV UUID organizací).
Prázdná hodnota = fakturace dostupná všem, kdo mají příslušnou schopnost.
