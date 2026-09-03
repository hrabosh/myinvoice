<?php

declare(strict_types=1);

namespace MyInvoice\Service\Deployment;

use MyInvoice\Infrastructure\Config\Config;

enum DeploymentMode: string
{
    case Standalone = 'standalone';
    case ReviziorManaged = 'revizior_managed';

    public static function fromConfig(Config $config): self
    {
        $value = trim((string) $config->get('deployment.mode', self::Standalone->value));

        return self::tryFrom($value)
            ?? throw new \InvalidArgumentException(sprintf(
                'cfg.deployment.mode must be one of: %s.',
                implode(', ', array_column(self::cases(), 'value')),
            ));
    }
}
