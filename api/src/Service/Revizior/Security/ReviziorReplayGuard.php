<?php

declare(strict_types=1);

namespace MyInvoice\Service\Revizior\Security;

use DateTimeImmutable;
use DateTimeZone;
use MyInvoice\Infrastructure\Database\Connection;
use PDOException;

final class ReviziorReplayGuard
{
    public function __construct(private readonly Connection $db) {}

    public function consume(ReviziorServiceIdentity $identity): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'DELETE FROM revizior_security_nonces
              WHERE expires_at < UTC_TIMESTAMP(6) - INTERVAL 30 SECOND
              LIMIT 100'
        )->execute();

        try {
            $statement = $pdo->prepare(
                'INSERT INTO revizior_security_nonces
                    (jti_hash, purpose, issuer, subject, expires_at, consumed_at, request_id)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), ?)'
            );
            $statement->execute([
                hash('sha256', $identity->jti),
                'service',
                $identity->issuer,
                $identity->subject,
                (new DateTimeImmutable('@' . $identity->expiresAt))
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s.u'),
                $identity->requestId,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw ReviziorServiceAuthException::unauthorized('service_token_replayed');
            }
            throw $e;
        }
    }
}
