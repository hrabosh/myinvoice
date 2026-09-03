<?php

declare(strict_types=1);

namespace MyInvoice\Service\Revizior\Security;

/** Ověřené claimy jednorázového SSO ticketu. Role v nich schválně není. */
final readonly class ReviziorSsoTicket
{
    public function __construct(
        public string $issuer,
        public string $userUuid,
        public string $organizationUuid,
        public string $jti,
        public int $expiresAt,
        public string $target,
        public string $returnTo,
    ) {}
}
