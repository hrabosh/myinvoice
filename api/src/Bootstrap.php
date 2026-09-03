<?php

declare(strict_types=1);

namespace MyInvoice;

use DI\ContainerBuilder;
use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Cache\RedisProbe;
use MyInvoice\Infrastructure\Clock\UtcClock;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\ApiScopeMiddleware;
use MyInvoice\Middleware\ApiVersionRewriteMiddleware;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\CsrfMiddleware;
use MyInvoice\Middleware\FirstRunLockMiddleware;
use MyInvoice\Middleware\IpAllowlistMiddleware;
use MyInvoice\Middleware\ManagedModeGuardMiddleware;
use MyInvoice\Middleware\RateLimitMiddleware;
use MyInvoice\Middleware\RequireMfaMiddleware;
use MyInvoice\Middleware\ReviziorServiceAuthMiddleware;
use MyInvoice\Middleware\RoleMiddleware;
use MyInvoice\Middleware\SessionLockMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Middleware\WebAuthnBodyLimitMiddleware;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Auth\DatabaseSecurityClock;
use MyInvoice\Service\Auth\SecurityClock;
use MyInvoice\Service\Auth\WebAuthnConfigProvider;
use MyInvoice\Service\Deployment\DeploymentCapabilities;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;

final class Bootstrap
{
    public static function rootDir(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return App<ContainerInterface|null> */
    public static function buildApp(): App
    {
        $rootDir = self::rootDir();
        $config  = Config::load($rootDir);
        // Eager validation: invalid managed-mode configuration must fail before
        // the application starts accepting requests.
        new DeploymentCapabilities($config);

        // Bezpečnostní guard: v produkci pepper musí být nastavený (jinak hesla nemají druhotnou ochranu)
        $env    = (string) $config->get('app.env', 'production');
        $pepper = (string) $config->get('app.pepper', '');
        if ($env === 'production' && $pepper === '') {
            throw new \RuntimeException('cfg.app.pepper není nastaven (vygeneruj: openssl rand -base64 32). V produkci je povinný.');
        }

        date_default_timezone_set((string) $config->get('app.timezone', 'Europe/Prague'));

        // PHP error log → log/php-errors.log (jinak by warnings/notices padaly do
        // system php_errors.log, který je mimo repo). Display_errors v dev=on, prod=off.
        // Pokud je nastaven MYINVOICE_DATA_DIR, ukládáme i tento log do data_dir
        // (drží všechen state pod jediným perzistentním volume).
        $logDir = ($config->dataDir() ?? $rootDir) . '/log';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        ini_set('log_errors', '1');
        ini_set('error_log', $logDir . '/php-errors.log');
        // NIKDY display_errors=on pro API endpoints — JSON response by byla kontaminována
        // deprecation/notice warningy (typicky vendor 3rd-party kód). Logujeme do souboru.
        // Dev env: warnings se objeví v log/php-errors.log + log/app-YYYY-MM-DD.log.
        ini_set('display_errors', '0');
        // Reporting: E_ALL minus E_DEPRECATED (PHP 8.5 deprecates older patterns ve vendoru,
        // které nemůžeme fixnout — nechceme je v error log spamovat).
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        $builder = new ContainerBuilder();
        $builder->useAttributes(false);
        $builder->addDefinitions([
            Config::class => $config,
            ClockInterface::class => fn () => new UtcClock(),
            SecurityClock::class => fn () => new DatabaseSecurityClock(),

            LoggerInterface::class => function (ContainerInterface $c) use ($config): LoggerInterface {
                $logger = new Logger('myinvoice');
                $path   = (string) $config->get('logging.path');
                $level  = self::resolveLogLevel((string) $config->get('logging.level', 'info'));
                $maxFiles = (int) $config->get('logging.max_files', 90);
                if (!is_dir(dirname($path))) {
                    @mkdir(dirname($path), 0755, true);
                }
                $logger->pushHandler(new RotatingFileHandler($path, $maxFiles, $level));
                return $logger;
            },

            ResponseFactory::class => fn () => new ResponseFactory(),
            Connection::class      => fn (ContainerInterface $c) => new Connection($c->get(Config::class), $c->get(LoggerInterface::class)),
            RedisProbe::class      => fn (ContainerInterface $c) => new RedisProbe($c->get(Config::class)),
            RedisFactory::class    => fn (ContainerInterface $c) => new RedisFactory($c->get(Config::class)),
            // Nativní updater si cesty (root / data dir) rozřeší sám; explicitní bind,
            // ať PHP-DI nemusí hádat volitelné string parametry konstruktoru.
            \MyInvoice\Service\Update\NativeUpdateService::class => fn () => new \MyInvoice\Service\Update\NativeUpdateService(),
            PasskeyService::class  => fn (ContainerInterface $c) => new PasskeyService(
                $c->get(WebAuthnConfigProvider::class),
            ),
            \MyInvoice\Service\Signing\SigningPassphraseProviderInterface::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Signing\SigningPassphraseProvider(
                $c->get(Config::class),
                $c->get(\MyInvoice\Service\Auth\SecretEncryption::class),
            ),
            \MyInvoice\Service\Signing\Pdf\PdfSigningService::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Signing\Pdf\PdfSigningService(
                $c->get(Config::class),
                $c->get(\MyInvoice\Service\ActivityLogger::class),
                $c->get(\MyInvoice\Service\Signing\Pdf\NativePdfSignatureBackend::class),
                $c->get(\MyInvoice\Repository\SigningProfileRepository::class),
                $c->get(\MyInvoice\Service\Signing\SigningPassphraseProviderInterface::class),
            ),
            \MyInvoice\Service\Mail\Mailer::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Mail\Mailer(
                $c->get(Config::class),
                $c->get(LoggerInterface::class),
                $c->get(Connection::class),
                $c->get(\MyInvoice\Repository\EmailTemplateRepository::class),
                $c->get(\MyInvoice\Service\Signing\Email\EmailSigningService::class),
                $c->get(\MyInvoice\Repository\EmailProfileRepository::class),
                $c->get(\MyInvoice\Service\Mail\SentMailImapAppender::class),
            ),
            \MyInvoice\Service\Bank\EmailNotice\ImapMailboxClientInterface::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Bank\EmailNotice\WebklexImapMailboxClient(
                $c->get(\MyInvoice\Service\Bank\EmailNotice\EmailNoticeTextNormalizer::class),
            ),
            \MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeParserRepository::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeParserRepository(
                $c->get(Connection::class),
                self::bankEmailNoticeParsers($c, $config),
            ),
            \MyInvoice\Service\Bank\StatementMatcher::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Bank\StatementMatcher(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Service\Invoice\FinalFromProformaCreator::class),
                // #127 — automatické párování (GPC import, e-mailové avízo, cron) musí
                // poslat děkovný e-mail za úhradu stejně jako ruční mark-paid/manualMatch.
                $c->get(\MyInvoice\Service\Mail\PaymentThanksMailer::class),
                // #89 — evidence plateb (exact i částečné úhrady přes invoice_payments)
                // + auto DRAFT daňového dokladu k přijaté platbě u částečně uhrazené proformy.
                $c->get(\MyInvoice\Service\Invoice\InvoicePaymentService::class),
                $c->get(\MyInvoice\Service\Invoice\PaymentTaxDocumentCreator::class),
                // Aktivita dokladu — „payment_matched" záznam u auto-spárování platby
                // (vidět v aktivitě vystavené i přijaté faktury).
                $c->get(\MyInvoice\Service\ActivityLogger::class),
            ),

            // IpMatcher má v konstruktoru volitelný `?Config $config = null`. Autowiring
            // takový parametr neresolvuje (dosadí default null), takže clientIpFromRequest()
            // by ignorovalo cfg.ip_allowlist.trusted_proxies a vždy vracelo REMOTE_ADDR.
            // Za reverse proxy → audit log a brute-force lockout vidí IP proxy místo
            // reálného klienta. Explicitní injekce Configu to opravuje.
            IpMatcher::class       => fn (ContainerInterface $c) => new IpMatcher($c->get(Config::class)),

            // Kniha jízd — registry parserů detailních výpisů tankování. Pořadí = priorita:
            // konkrétní vendor parsery → AI fallback → univerzální summary (vždy uspěje).
            // PŘIDÁNÍ NOVÉ TANKOVACÍ SPOLEČNOSTI: vytvoř třídu implements FuelStatementParser
            // a vlož ji do tohoto pole PŘED AiFuelStatementParser.
            \MyInvoice\Service\Logbook\Fuel\FuelStatementParserRegistry::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Logbook\Fuel\FuelStatementParserRegistry([
                $c->get(\MyInvoice\Service\Logbook\Fuel\AxigonStatementParser::class),
                $c->get(\MyInvoice\Service\Logbook\Fuel\AiFuelStatementParser::class),
                $c->get(\MyInvoice\Service\Logbook\Fuel\SummaryFuelParser::class),
            ]),

            // "Upload PDF" bankovních výpisů — registry bank-specifických PDF parserů
            // (banky bez GPC/ABO exportu). PŘIDÁNÍ NOVÉ BANKY: nová třída implements
            // BankStatementPdfParserInterface a vlož ji do tohoto pole.
            \MyInvoice\Service\Bank\Pdf\BankStatementPdfParserRegistry::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Bank\Pdf\BankStatementPdfParserRegistry([
                $c->get(\MyInvoice\Service\Bank\Pdf\CreditasStatementPdfParser::class),
                $c->get(\MyInvoice\Service\Bank\Pdf\CsobStatementPdfParser::class),
                $c->get(\MyInvoice\Service\Bank\Pdf\KbStatementPdfParser::class),
                $c->get(\MyInvoice\Service\Bank\Pdf\RaiffeisenbankStatementPdfParser::class),
            ]),
        ]);

        $container = $builder->build();
        AppFactory::setContainer($container);

        $app = AppFactory::create();

        Routes::register($app);

        // Slim 4 LIFO: poslední `add()` = NEJVĚTŠÍ vrstva = běží JAKO PRVNÍ.
        // Cílový order běhu (outside → inside):
        //   IpAllowlist → ManagedModeGuard → ReviziorServiceAuth → FirstRunLock → Auth → SessionLock → RequireMfa → Role → SupplierScope → ApiScope → RateLimit → CSRF → WebAuthnBodyLimit → Routing → BodyParsing → Action
        // → add() v opačném pořadí (innermost první):
        $app->addBodyParsingMiddleware();                            // innermost
        $app->addRoutingMiddleware();
        $app->add($container->get(WebAuthnBodyLimitMiddleware::class)); // limit raw WebAuthn credential před JSON parsingem
        $app->add($container->get(CsrfMiddleware::class));           // potřebuje session z Auth (bearer skip)
        $app->add($container->get(RateLimitMiddleware::class));      // chrání forgot/setup/login/ARES + per-user/per-token limity
        $app->add($container->get(ApiScopeMiddleware::class));       // bearer-only: enforce read / read_write scope
        $app->add($container->get(SupplierScopeMiddleware::class));  // multi-supplier scope (X-Supplier-Id / token's supplier_id)
        $app->add($container->get(RoleMiddleware::class));           // RBAC — kontrola role po Auth
        $app->add($container->get(RequireMfaMiddleware::class));     // assurance + povinný MFA setup (bearer skip)
        $app->add($container->get(SessionLockMiddleware::class));    // autoritativní idle/manual lock browser session
        $app->add($container->get(AuthMiddleware::class));           // načte session nebo bearer token
        $app->add($container->get(FirstRunLockMiddleware::class));   // 423 pokud users prázdná
        $app->add($container->get(ReviziorServiceAuthMiddleware::class)); // oddělený service assertion, nikdy PAT/session
        $app->add($container->get(ManagedModeGuardMiddleware::class)); // managed feature/API denylist
        $app->add($container->get(IpAllowlistMiddleware::class));    // outermost user mw
        $app->add(new ApiVersionRewriteMiddleware());                // /api/v1/* → /api/* před vším ostatním

        $displayErrors = (bool) $config->get('app.debug', false);
        $app->addErrorMiddleware($displayErrors, true, true, $container->get(LoggerInterface::class));

        return $app;
    }

    /**
     * Resolve class names ze slotů cfg.bank_email.notice_parsers na instance.
     * Validaci (interface, prázdný/duplicitní key) dělá konstruktor
     * BankEmailNoticeParserRepository — tady se jen vypínají sloty (null/false/'').
     *
     * @return list<object>
     */
    private static function bankEmailNoticeParsers(ContainerInterface $container, Config $config): array
    {
        $classes = $config->get('bank_email.notice_parsers', []);
        if (!is_array($classes) || $classes === []) {
            throw new \RuntimeException('cfg.bank_email.notice_parsers musí být neprázdná mapa parser slot => class.');
        }

        $parsers = [];
        foreach ($classes as $class) {
            if ($class === null || $class === false || trim((string) $class) === '') {
                continue; // slot vypnutý přes cfg.php
            }
            $parsers[] = $container->get(trim((string) $class));
        }

        return $parsers;
    }

    private static function resolveLogLevel(string $level): \Monolog\Level
    {
        return match (strtolower($level)) {
            'debug'   => \Monolog\Level::Debug,
            'info'    => \Monolog\Level::Info,
            'notice'  => \Monolog\Level::Notice,
            'warning' => \Monolog\Level::Warning,
            'error'   => \Monolog\Level::Error,
            default   => \Monolog\Level::Info,
        };
    }
}
