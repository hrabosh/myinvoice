<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class ReviziorOrganizationUpdateRequestValidator
{
    /**
     * @param array<string,mixed> $body
     * @return array{uuid:string,name:string,registrationNumber:?string,vatNumber:?string,vatStatus:?string,street:string,city:string,postalCode:string,countryCode:string,language:string,active:bool,sourceUpdatedAt:string}
     */
    public function validate(string $organizationUuid, array $body): array
    {
        $fields = [];
        $organizationUuid = strtolower(trim($organizationUuid));
        if (!$this->isUuid($organizationUuid)) {
            $fields['organizationUuid'] = 'must_be_uuid';
        }

        $this->rejectUnknown($body, ['specVersion', 'organization'], '', $fields);
        if (($body['specVersion'] ?? null) !== ReviziorContract::VERSION) {
            $fields['specVersion'] = 'must_equal_1.0';
        }
        $organization = isset($body['organization']) && is_array($body['organization'])
            ? $body['organization']
            : [];
        if ($organization === []) {
            $fields['organization'] = 'required_object';
        }
        $this->rejectUnknown($organization, [
            'name', 'registrationNumber', 'vatNumber', 'vatStatus', 'address',
            'language', 'active', 'sourceUpdatedAt',
        ], 'organization.', $fields);

        $name = $this->requiredString($organization, 'name', 'organization.name', 190, $fields);
        $registrationNumber = $this->nullableString(
            $organization,
            'registrationNumber',
            'organization.registrationNumber',
            20,
            $fields,
        );
        $vatNumber = $this->nullableString($organization, 'vatNumber', 'organization.vatNumber', 20, $fields);
        if (!array_key_exists('vatStatus', $organization)) {
            $fields['organization.vatStatus'] = 'required_nullable_string';
        }
        $vatStatus = $organization['vatStatus'] ?? null;
        if ($vatStatus !== null && !in_array($vatStatus, ['payer', 'non_payer', 'identified_person'], true)) {
            $fields['organization.vatStatus'] = 'invalid_value';
        }

        $address = isset($organization['address']) && is_array($organization['address'])
            ? $organization['address']
            : [];
        if ($address === []) {
            $fields['organization.address'] = 'required_object';
        }
        $this->rejectUnknown(
            $address,
            ['street', 'city', 'postalCode', 'countryCode'],
            'organization.address.',
            $fields,
        );
        $street = $this->requiredString($address, 'street', 'organization.address.street', 190, $fields);
        $city = $this->requiredString($address, 'city', 'organization.address.city', 120, $fields);
        $postalCode = $this->requiredString($address, 'postalCode', 'organization.address.postalCode', 10, $fields);
        $countryCode = $this->requiredString($address, 'countryCode', 'organization.address.countryCode', 2, $fields);
        if ($countryCode !== strtoupper($countryCode) || preg_match('/^[A-Z]{2}$/D', $countryCode) !== 1) {
            $fields['organization.address.countryCode'] = 'must_be_iso_3166_alpha_2';
        }

        $language = $organization['language'] ?? null;
        if (!in_array($language, ['cs', 'en'], true)) {
            $fields['organization.language'] = 'invalid_value';
            $language = '';
        }
        if (!array_key_exists('active', $organization) || !is_bool($organization['active'])) {
            $fields['organization.active'] = 'required_boolean';
        }
        $active = is_bool($organization['active'] ?? null) ? $organization['active'] : false;
        $sourceUpdatedAt = $this->dateTime($organization['sourceUpdatedAt'] ?? null, $fields);

        if ($fields !== []) {
            throw ReviziorProvisioningException::validation($fields);
        }

        return [
            'uuid' => $organizationUuid,
            'name' => $name,
            'registrationNumber' => $registrationNumber,
            'vatNumber' => $vatNumber,
            'vatStatus' => is_string($vatStatus) ? $vatStatus : null,
            'street' => $street,
            'city' => $city,
            'postalCode' => $postalCode,
            'countryCode' => $countryCode,
            'language' => $language,
            'active' => $active,
            'sourceUpdatedAt' => $sourceUpdatedAt,
        ];
    }

    /** @param array<string,mixed> $data @param array<string,string> $fields */
    private function requiredString(array $data, string $key, string $path, int $max, array &$fields): string
    {
        if (!array_key_exists($key, $data) || !is_string($data[$key])) {
            $fields[$path] = 'required_string';
            return '';
        }
        $value = trim($data[$key]);
        if ($value === '') {
            $fields[$path] = 'required';
        } elseif (mb_strlen($value) > $max) {
            $fields[$path] = 'too_long';
        }
        return $value;
    }

    /** @param array<string,mixed> $data @param array<string,string> $fields */
    private function nullableString(array $data, string $key, string $path, int $max, array &$fields): ?string
    {
        if (!array_key_exists($key, $data)) {
            $fields[$path] = 'required_nullable_string';
            return null;
        }
        if ($data[$key] === null) {
            return null;
        }
        if (!is_string($data[$key])) {
            $fields[$path] = 'must_be_string_or_null';
            return null;
        }
        $value = trim($data[$key]);
        if (mb_strlen($value) > $max) {
            $fields[$path] = 'too_long';
        }
        return $value === '' ? null : $value;
    }

    /** @param array<string,string> $fields */
    private function dateTime(mixed $value, array &$fields): string
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1
        ) {
            $fields['organization.sourceUpdatedAt'] = 'must_be_rfc3339';
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
            $fields['organization.sourceUpdatedAt'] = 'must_be_rfc3339';
            return '';
        }
    }

    /** @param array<string,mixed> $data @param list<string> $allowed @param array<string,string> $fields */
    private function rejectUnknown(array $data, array $allowed, string $prefix, array &$fields): void
    {
        foreach (array_keys($data) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                $fields[$prefix . (string) $key] = 'unknown_field';
            }
        }
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
            $value,
        ) === 1;
    }
}
