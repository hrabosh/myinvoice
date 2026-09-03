<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Integration\Revizior;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Integration\Revizior\ReviziorInvoiceSourceReader;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Skládání odkazu do ReviziORu.
 *
 * Origin pochází z konfigurace instalace; kdyby se bral z requestu, vznikl by
 * z odkazu na detailu dokladu open redirect na doméně poskytovatele.
 */
final class ReviziorInvoiceSourceReaderTest extends TestCase
{
    private const REPORT = '01a04451-ef8c-70fa-b39d-38179ac1700e';

    public function testReportGetsAnAbsoluteLinkBuiltFromTheConfiguredOrigin(): void
    {
        // `app_url` nese i cestu modulu — odkaz na revizi musí vyjít z originu.
        $url = $this->url('revision_report', self::REPORT, 'https://app.revizior.cz/fakturace');

        self::assertSame('https://app.revizior.cz/revize/zpravy/' . self::REPORT, $url);
    }

    public function testOtherSourceTypesHaveNoLinkYet(): void
    {
        foreach (['revision_schedule', 'revision_job', 'manual'] as $type) {
            self::assertNull($this->url($type, self::REPORT, 'https://app.revizior.cz'), $type);
        }
    }

    public function testMalformedUuidOrMissingConfigurationYieldsNoLink(): void
    {
        self::assertNull($this->url('revision_report', '../../etc/passwd', 'https://app.revizior.cz'));
        self::assertNull($this->url('revision_report', self::REPORT, ''));
        self::assertNull($this->url('revision_report', self::REPORT, 'not-a-url'));
    }

    public function testPortIsKeptSoALocalConsumerStillWorks(): void
    {
        self::assertSame(
            'http://localhost:8090/revize/zpravy/' . self::REPORT,
            $this->url('revision_report', self::REPORT, 'http://localhost:8090/faktury'),
        );
    }

    private function url(string $type, string $uuid, string $appUrl): ?string
    {
        $reader = new ReviziorInvoiceSourceReader(
            $this->createStub(Connection::class),
            new Config(['deployment' => ['revizior' => ['app_url' => $appUrl]]]),
        );
        $method = new ReflectionMethod($reader, 'url');

        /** @var string|null $url */
        $url = $method->invoke($reader, $type, $uuid);

        return $url;
    }
}
