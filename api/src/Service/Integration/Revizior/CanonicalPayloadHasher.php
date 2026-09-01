<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class CanonicalPayloadHasher
{
    public function hash(mixed $payload): string
    {
        return hash('sha256', $this->encode($payload));
    }

    public function prefixedHash(mixed $payload): string
    {
        return 'sha256:' . $this->hash($payload);
    }

    public function encode(mixed $payload): string
    {
        return json_encode(
            $this->normalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private function normalize(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new InvalidArgumentException('Canonical JSON nepovoluje floating-point hodnoty.');
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
            }

            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalize($item);
            }
            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        if (preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',
            $value,
        ) === 1) {
            return strtolower($value);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) === 1) {
            $date = new DateTimeImmutable($value);
            $utc = $date->setTimezone(new DateTimeZone('UTC'));
            $microseconds = $utc->format('u');
            return $utc->format('Y-m-d\TH:i:s')
                . ($microseconds === '000000' ? '' : '.' . rtrim($microseconds, '0'))
                . 'Z';
        }

        return $value;
    }
}
