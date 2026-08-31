<?php

declare(strict_types=1);

namespace MyInvoice\Service\Deployment;

use MyInvoice\Infrastructure\Config\Config;

final class DeploymentCapabilities
{
    private readonly DeploymentMode $mode;
    private readonly string $productName;
    private readonly ?string $returnUrl;

    public function __construct(Config $config)
    {
        $this->mode = DeploymentMode::fromConfig($config);
        $this->productName = trim((string) $config->get('deployment.public_name', 'MyInvoice.cz'));
        if ($this->productName === '') {
            throw new \InvalidArgumentException('cfg.deployment.public_name must not be empty.');
        }

        $this->returnUrl = $this->resolveReturnUrl($config);
    }

    public function mode(): DeploymentMode
    {
        return $this->mode;
    }

    public function isStandalone(): bool
    {
        return $this->mode === DeploymentMode::Standalone;
    }

    public function isReviziorManaged(): bool
    {
        return $this->mode === DeploymentMode::ReviziorManaged;
    }

    public function allowsFirstRunSetup(): bool
    {
        return $this->isStandalone();
    }

    public function allowsLocalPasswordLogin(): bool
    {
        return $this->isStandalone();
    }

    public function allowsSelfUpdate(): bool
    {
        return $this->isStandalone();
    }

    public function allowsMyuctoUpgrade(): bool
    {
        return $this->isStandalone();
    }

    public function showsModule(string $module): bool
    {
        if ($this->isStandalone()) {
            return true;
        }

        return match ($module) {
            'salesInvoices', 'clients', 'projects', 'priceList', 'bank', 'documents' => true,
            'purchaseInvoices', 'tax', 'payroll', 'logbook', 'selfUpdate', 'myuctoUpgrade' => false,
            default => false,
        };
    }

    /** @return array<string, bool> */
    public function modules(): array
    {
        $modules = [];
        foreach ([
            'salesInvoices',
            'clients',
            'projects',
            'priceList',
            'bank',
            'documents',
            'purchaseInvoices',
            'tax',
            'payroll',
            'logbook',
            'selfUpdate',
            'myuctoUpgrade',
        ] as $module) {
            $modules[$module] = $this->showsModule($module);
        }

        return $modules;
    }

    /** @return array{deploymentMode:string,productName:string,modules:array<string,bool>,returnUrl:?string} */
    public function publicPayload(): array
    {
        return [
            'deploymentMode' => $this->mode->value,
            'productName' => $this->productName,
            'modules' => $this->modules(),
            'returnUrl' => $this->returnUrl,
        ];
    }

    private function resolveReturnUrl(Config $config): ?string
    {
        $value = trim((string) $config->get('deployment.revizior.app_url', ''));
        if ($value === '') {
            if ($this->isReviziorManaged()) {
                throw new \InvalidArgumentException(
                    'cfg.deployment.revizior.app_url is required in revizior_managed mode.',
                );
            }

            return null;
        }

        $parts = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException(
                'cfg.deployment.revizior.app_url must be an absolute HTTPS URL without credentials.',
            );
        }

        $allowedHosts = $config->get('deployment.revizior.allowed_return_hosts', []);
        if (!is_array($allowedHosts)) {
            throw new \InvalidArgumentException('cfg.deployment.revizior.allowed_return_hosts must be an array.');
        }
        $allowedHosts = array_values(array_filter(array_map(
            static fn (mixed $allowed): string => strtolower(trim((string) $allowed)),
            $allowedHosts,
        )));
        if ($allowedHosts !== [] && !in_array($host, $allowedHosts, true)) {
            throw new \InvalidArgumentException(
                'cfg.deployment.revizior.app_url host is not listed in allowed_return_hosts.',
            );
        }

        return rtrim($value, '/');
    }
}
