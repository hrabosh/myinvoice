<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Doručení událostí z outboxu do ReviziORu (R5, §2.16).
 *
 * ## Claim, ne „vyber a doufej"
 *
 * Řádky se nejdřív atomicky označí vlastním `claimed_by`, teprve pak se
 * odesílají. Dva paralelní workery si tak nemůžou vzít tentýž event; padlý
 * worker se pozná podle starého `claimed_at` a jeho práce se po `claim_ttl`
 * uvolní.
 *
 * ## Co je opakovatelné
 *
 * Síťová chyba, 408, 425, 429 a 5xx = zkusit znovu s exponenciálním backoffem
 * a jitterem (respektuje `Retry-After`). Ostatní 4xx = `failed` a alert:
 * opakovat request, který protistrana odmítla jako neplatný, jen plní frontu.
 *
 * ## Payload se nikdy nepřepočítává
 *
 * Odesílá se přesně to, co se uložilo při business změně. Kdyby se snapshot
 * generoval při odeslání, doručila by se po výpadku aktuální podoba faktury —
 * a consumer by dostal události, které si navzájem odporují.
 */
final class ReviziorOutboxDispatcher
{
    public const DEFAULT_BATCH = 50;
    private const CLAIM_TTL_SECONDS = 300;
    private const MAX_BACKOFF_SECONDS = 3600;

    public function __construct(
        private readonly Connection $db,
        private readonly ReviziorEventSigner $signer,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function isConfigured(): bool
    {
        return $this->callbackUrl() !== '' && $this->signer->isConfigured();
    }

    /**
     * @return array{claimed:int, delivered:int, retried:int, failed:int}
     */
    public function dispatch(int $limit = self::DEFAULT_BATCH, ?string $workerId = null): array
    {
        $result = ['claimed' => 0, 'delivered' => 0, 'retried' => 0, 'failed' => 0];
        if (!$this->isConfigured()) {
            $this->logger->warning('ReviziOR outbox dispatcher not configured');

            return $result;
        }
        $workerId ??= gethostname() . ':' . getmypid();
        $rows = $this->claim($limit, substr($workerId, 0, 64));
        $result['claimed'] = count($rows);

        foreach ($rows as $row) {
            $outcome = $this->deliver($row);
            ++$result[$outcome];
        }

        return $result;
    }

    /** @return array{pending:int, failed:int, delivered:int, oldest_pending_age_seconds:?int} */
    public function status(): array
    {
        $pdo = $this->db->pdo();
        $counts = ['pending' => 0, 'failed' => 0, 'delivered' => 0];
        $statement = $pdo->query('SELECT state, COUNT(*) AS n FROM revizior_event_outbox GROUP BY state');
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['state']] = (int) $row['n'];
        }
        $oldest = $pdo->query(
            "SELECT TIMESTAMPDIFF(SECOND, MIN(created_at), UTC_TIMESTAMP(6)) FROM revizior_event_outbox WHERE state = 'pending'"
        )->fetchColumn();

        return $counts + ['oldest_pending_age_seconds' => $oldest === null || $oldest === false ? null : (int) $oldest];
    }

    /** Vrátí `failed` event zpět do fronty (CLI `retry`). */
    public function requeue(string $eventId): bool
    {
        $statement = $this->db->pdo()->prepare(
            "UPDATE revizior_event_outbox
                SET state = 'pending', next_attempt_at = UTC_TIMESTAMP(6), claimed_at = NULL, claimed_by = NULL
              WHERE id = ? AND state = 'failed'"
        );
        $statement->execute([$eventId]);

        return $statement->rowCount() > 0;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function claim(int $limit, string $workerId): array
    {
        $limit = max(1, min(500, $limit));
        $pdo = $this->db->pdo();
        // Claim je jeden UPDATE: `SELECT … FOR UPDATE` + `UPDATE` by mezi
        // dotazy pustil druhého workera na tytéž řádky.
        $pdo->prepare(
            "UPDATE revizior_event_outbox
                SET claimed_at = UTC_TIMESTAMP(6), claimed_by = ?
              WHERE state = 'pending'
                AND next_attempt_at <= UTC_TIMESTAMP(6)
                AND (claimed_at IS NULL OR claimed_at < UTC_TIMESTAMP(6) - INTERVAL ? SECOND)
              ORDER BY created_at
              LIMIT ?"
        )->execute([$workerId, self::CLAIM_TTL_SECONDS, $limit]);

        $statement = $pdo->prepare(
            "SELECT id, event_type, payload_json, delivery_attempts, aggregate_id, aggregate_sequence
               FROM revizior_event_outbox
              WHERE state = 'pending' AND claimed_by = ?
              ORDER BY created_at
              LIMIT ?"
        );
        $statement->execute([$workerId, $limit]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return 'delivered'|'retried'|'failed'
     */
    private function deliver(array $row): string
    {
        $body = (string) $row['payload_json'];
        $attempts = (int) $row['delivery_attempts'] + 1;

        try {
            [$status, $retryAfter] = $this->post($body);
        } catch (Throwable $e) {
            $this->logger->warning('ReviziOR event delivery failed', [
                'event_id' => $row['id'],
                'event_type' => $row['event_type'],
                'attempt' => $attempts,
                'error' => 'network',
            ]);

            return $this->scheduleRetry($row, $attempts, null, 'network', null);
        }

        if ($status >= 200 && $status < 300) {
            $this->db->pdo()->prepare(
                "UPDATE revizior_event_outbox
                    SET state = 'delivered', delivery_attempts = ?, last_http_status = ?, last_error_code = NULL,
                        delivered_at = UTC_TIMESTAMP(6), claimed_at = NULL, claimed_by = NULL
                  WHERE id = ?"
            )->execute([$attempts, $status, $row['id']]);

            return 'delivered';
        }

        if (in_array($status, [408, 425, 429], true) || $status >= 500) {
            return $this->scheduleRetry($row, $attempts, $status, 'http_' . $status, $retryAfter);
        }

        // Ostatní 4xx: protistrana request odmítla jako neplatný. Opakování
        // nepomůže; dead letter je viditelný ve `status` a v alertu.
        $this->db->pdo()->prepare(
            "UPDATE revizior_event_outbox
                SET state = 'failed', delivery_attempts = ?, last_http_status = ?, last_error_code = ?,
                    claimed_at = NULL, claimed_by = NULL
              WHERE id = ?"
        )->execute([$attempts, $status, 'http_' . $status, $row['id']]);
        $this->logger->error('ReviziOR event dead-lettered', [
            'event_id' => $row['id'],
            'event_type' => $row['event_type'],
            'http_status' => $status,
        ]);

        return 'failed';
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return 'retried'|'failed'
     */
    private function scheduleRetry(array $row, int $attempts, ?int $status, string $errorCode, ?int $retryAfter): string
    {
        $maxAttempts = max(1, (int) $this->config->get('deployment.revizior.callback.max_attempts', 12));
        if ($attempts >= $maxAttempts) {
            $this->db->pdo()->prepare(
                "UPDATE revizior_event_outbox
                    SET state = 'failed', delivery_attempts = ?, last_http_status = ?, last_error_code = ?,
                        claimed_at = NULL, claimed_by = NULL
                  WHERE id = ?"
            )->execute([$attempts, $status, $errorCode, $row['id']]);
            $this->logger->error('ReviziOR event gave up after max attempts', [
                'event_id' => $row['id'],
                'attempts' => $attempts,
            ]);

            return 'failed';
        }

        $delay = $retryAfter !== null && $retryAfter > 0
            ? min($retryAfter, self::MAX_BACKOFF_SECONDS)
            : self::backoffSeconds($attempts);
        $this->db->pdo()->prepare(
            "UPDATE revizior_event_outbox
                SET state = 'pending', delivery_attempts = ?, last_http_status = ?, last_error_code = ?,
                    next_attempt_at = UTC_TIMESTAMP(6) + INTERVAL ? SECOND, claimed_at = NULL, claimed_by = NULL
              WHERE id = ?"
        )->execute([$attempts, $status, $errorCode, $delay, $row['id']]);

        return 'retried';
    }

    /** Exponenciální backoff s jitterem: 5 s, 10 s, 20 s … max hodina. */
    public static function backoffSeconds(int $attempt): int
    {
        $base = min(self::MAX_BACKOFF_SECONDS, 5 * (2 ** max(0, $attempt - 1)));
        $jitter = (int) round($base * 0.2);

        return max(1, $base - $jitter + random_int(0, 2 * $jitter));
    }

    /**
     * @return array{0:int, 1:?int} HTTP status a `Retry-After` v sekundách
     */
    private function post(string $body): array
    {
        $handle = curl_init($this->callbackUrl());
        if ($handle === false) {
            throw new \RuntimeException('curl_init selhalo.');
        }
        $responseHeaders = [];
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => (int) $this->config->get('deployment.revizior.callback.timeout_seconds', 10),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-MyInvoice-Key-Id: ' . $this->signer->keyId(),
                'X-MyInvoice-Signature: ' . $this->signer->sign($body),
                'X-Request-Id: ' . \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
            ],
            CURLOPT_HEADERFUNCTION => static function ($_, string $header) use (&$responseHeaders): int {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($header);
            },
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false || $status === 0) {
            throw new \RuntimeException('Callback selhal: ' . ($error !== '' ? 'network' : 'unknown'));
        }
        $retryAfter = isset($responseHeaders['retry-after']) && ctype_digit($responseHeaders['retry-after'])
            ? (int) $responseHeaders['retry-after']
            : null;

        return [$status, $retryAfter];
    }

    private function callbackUrl(): string
    {
        return trim((string) $this->config->get('deployment.revizior.callback.url', ''));
    }
}
