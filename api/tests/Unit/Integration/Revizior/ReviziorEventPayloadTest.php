<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Integration\Revizior;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Integration\Revizior\ReviziorEventSigner;
use MyInvoice\Service\Integration\Revizior\ReviziorInvoiceSnapshotBuilder;
use MyInvoice\Service\Integration\Revizior\ReviziorOutboxDispatcher;
use PHPUnit\Framework\TestCase;

final class ReviziorEventPayloadTest extends TestCase
{
    public function testEventDataMatchesTheContractFixtureShape(): void
    {
        $builder = new ReviziorInvoiceSnapshotBuilder(new Config(['app' => ['url' => 'http://localhost:8080']]));
        $data = $builder->eventData([
            'id' => 789,
            'status' => 'issued',
            'varsymbol' => '20260048',
            'currency' => 'CZK',
            'currency_decimals' => 2,
            'total_with_vat' => 5445.0,
            'amount_to_pay' => 5445.0,
            'paid_total' => 0.0,
            'issue_date' => '2026-08-30',
            'due_date' => '2026-09-13',
            'paid_at' => null,
            'public_token' => null,
        ], '2026-09-01');

        $fixture = json_decode(
            (string) file_get_contents(dirname(__DIR__, 5) . '/source/revizior-integration/contract/v1/events/invoice-issued.json'),
            true,
            64,
            JSON_THROW_ON_ERROR,
        )['data'];

        self::assertSame(array_keys($fixture), array_keys($data), 'Klíče události musí odpovídat kontraktu.');
        self::assertSame('20260048', $data['invoiceNumber']);
        self::assertSame('issued', $data['status']);
        self::assertSame(544500, $data['totalMinor']);
        self::assertSame(544500, $data['amountDueMinor']);
        self::assertSame('2026-09-13', $data['dueAt']);
        self::assertNull($data['paidAt']);
        self::assertSame('/invoices/789', $data['editPath']);
    }

    /** Koncept nemá datum vystavení — nesmí se dosadit datum vystavení dokladu. */
    public function testDraftHasNoIssuedAt(): void
    {
        $builder = new ReviziorInvoiceSnapshotBuilder(new Config([]));
        $data = $builder->eventData([
            'id' => 1,
            'status' => 'draft',
            'varsymbol' => null,
            'currency' => 'CZK',
            'currency_decimals' => 2,
            'total_with_vat' => 100.0,
            'amount_to_pay' => 100.0,
            'paid_total' => 0.0,
            'issue_date' => '2026-08-30',
            'due_date' => '2026-09-13',
            'paid_at' => null,
            'public_token' => null,
        ], '2026-08-30');

        self::assertNull($data['issuedAt']);
        self::assertSame('/invoices/1/edit', $data['editPath']);
    }

    /** Podpis je nad přesnými bajty těla — consumer ověřuje raw body. */
    public function testSignatureIsVerifiableWithThePublicKey(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $privatePem);
        $path = (string) tempnam(sys_get_temp_dir(), 'cb-priv');
        file_put_contents($path, (string) $privatePem);

        try {
            $signer = new ReviziorEventSigner(new Config([
                'deployment' => ['revizior' => ['callback' => ['key_id' => 'cb-2026', 'private_key_path' => $path]]],
            ]));
            self::assertTrue($signer->isConfigured());
            self::assertSame('cb-2026', $signer->keyId());

            $body = '{"eventId":"80000000-0000-4000-8000-000000000001"}';
            $signature = $signer->sign($body);
            self::assertDoesNotMatchRegularExpression('/[+\/=]/', $signature, 'Podpis musí být base64url.');

            $raw = base64_decode(strtr($signature, '-_', '+/') . str_repeat('=', (4 - strlen($signature) % 4) % 4), true);
            self::assertNotFalse($raw);
            $publicKey = (string) openssl_pkey_get_details($key)['key'];
            self::assertSame(1, openssl_verify($body, $raw, $publicKey, OPENSSL_ALGO_SHA256));
            self::assertSame(0, openssl_verify($body . ' ', $raw, $publicKey, OPENSSL_ALGO_SHA256));
        } finally {
            unlink($path);
        }
    }

    public function testBackoffGrowsAndStaysBounded(): void
    {
        $previous = 0;
        foreach (range(1, 12) as $attempt) {
            $delay = ReviziorOutboxDispatcher::backoffSeconds($attempt);
            self::assertGreaterThan(0, $delay);
            self::assertLessThanOrEqual(3600 * 1.2, $delay);
            if ($attempt <= 8) {
                self::assertGreaterThanOrEqual($previous, $delay + 1, 'Backoff nesmí klesat.');
            }
            $previous = $delay;
        }
    }
}
