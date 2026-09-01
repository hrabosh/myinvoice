<?php

declare(strict_types=1);

namespace MyInvoice\Service\Revizior\Security;

final readonly class ReviziorServiceIdentity
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $issuer,
        public string $subject,
        public string $jti,
        public int $expiresAt,
        public array $scopes,
        public string $requestId,
    ) {}

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
