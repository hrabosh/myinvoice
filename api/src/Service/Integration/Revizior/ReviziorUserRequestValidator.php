<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class ReviziorUserRequestValidator
{
    /**
     * @param array<string,mixed> $body
     * @return array{organizationUuid:string,userUuid:string,email:string,name:string,role:string,active:bool,sourceUpdatedAt:string}
     */
    public function validate(string $organizationUuid, string $userUuid, array $body): array
    {
        $fields = [];
        $organizationUuid = $this->uuid($organizationUuid, 'organizationUuid', $fields);
        $pathUserUuid = $this->uuid($userUuid, 'userUuid', $fields);

        $this->rejectUnknown(
            $body,
            ['specVersion', 'userUuid', 'email', 'name', 'role', 'active', 'sourceUpdatedAt'],
            $fields,
        );
        if (($body['specVersion'] ?? null) !== ReviziorContract::VERSION) {
            $fields['specVersion'] = 'must_equal_1.0';
        }

        $bodyUserUuid = $this->uuid((string) ($body['userUuid'] ?? ''), 'body.userUuid', $fields);
        if ($pathUserUuid !== '' && $bodyUserUuid !== '' && $pathUserUuid !== $bodyUserUuid) {
            $fields['body.userUuid'] = 'must_match_path';
        }

        $email = strtolower(trim((string) ($body['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            $fields['email'] = 'invalid_email';
        }
        $name = $this->requiredString($body, 'name', 120, $fields);
        $role = $body['role'] ?? null;
        if (!is_string($role) || !in_array($role, ['supplier_owner', 'accountant', 'readonly'], true)) {
            $fields['role'] = 'invalid_value';
            $role = '';
        }
        if (!array_key_exists('active', $body) || !is_bool($body['active'])) {
            $fields['active'] = 'required_boolean';
        }
        $active = is_bool($body['active'] ?? null) ? $body['active'] : false;
        $sourceUpdatedAt = $this->dateTime($body['sourceUpdatedAt'] ?? null, $fields);

        if ($fields !== []) {
            throw ReviziorProvisioningException::validation($fields);
        }

        return [
            'organizationUuid' => $organizationUuid,
            'userUuid' => $pathUserUuid,
            'email' => $email,
            'name' => $name,
            'role' => $role,
            'active' => $active,
            'sourceUpdatedAt' => $sourceUpdatedAt,
        ];
    }

    /** @return array{organizationUuid:string,userUuid:string} */
    public function validatePath(string $organizationUuid, string $userUuid): array
    {
        $fields = [];
        $organizationUuid = $this->uuid($organizationUuid, 'organizationUuid', $fields);
        $userUuid = $this->uuid($userUuid, 'userUuid', $fields);
        if ($fields !== []) {
            throw ReviziorProvisioningException::validation($fields);
        }
        return ['organizationUuid' => $organizationUuid, 'userUuid' => $userUuid];
    }

    /** @param array<string,string> $fields */
    private function uuid(string $value, string $field, array &$fields): string
    {
        $value = strtolower(trim($value));
        if (preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
            $value,
        ) !== 1) {
            $fields[$field] = 'must_be_uuid';
            return '';
        }
        return $value;
    }

    /** @param array<string,mixed> $body @param array<string,string> $fields */
    private function requiredString(array $body, string $key, int $maxLength, array &$fields): string
    {
        if (!array_key_exists($key, $body) || !is_string($body[$key])) {
            $fields[$key] = 'required_string';
            return '';
        }
        $value = trim($body[$key]);
        if ($value === '') {
            $fields[$key] = 'required';
        } elseif (mb_strlen($value) > $maxLength) {
            $fields[$key] = 'too_long';
        }
        return $value;
    }

    /** @param array<string,string> $fields */
    private function dateTime(mixed $value, array &$fields): string
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1
        ) {
            $fields['sourceUpdatedAt'] = 'must_be_rfc3339';
            return '';
        }
        try {
            $date = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw new \InvalidArgumentException('invalid_datetime');
            }
            return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        } catch (Throwable) {
            $fields['sourceUpdatedAt'] = 'must_be_rfc3339';
            return '';
        }
    }

    /** @param array<string,mixed> $body @param list<string> $allowed @param array<string,string> $fields */
    private function rejectUnknown(array $body, array $allowed, array &$fields): void
    {
        foreach (array_keys($body) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                $fields[(string) $key] = 'unknown_field';
            }
        }
    }
}
