<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Validace payloadu `PUT /organizations/{uuid}/clients/{uuid}` (kontrakt v1).
 *
 * Adresa: `street` je povinný, `city`/`postalCode`/`countryCode` smí být
 * `null`. ReviziOR zná adresu klienta jako jeden řádek a zbytek nedomýšlí;
 * `null` tu znamená „zdroj to neví", ne „prázdné" — synchronizátor takové
 * pole u existujícího klienta nepřepisuje.
 */
final class ReviziorClientRequestValidator
{
    private const ALLOWED = ['specVersion', 'companyName', 'registrationNumber', 'vatNumber', 'address', 'contacts', 'language', 'active', 'sourceUpdatedAt'];
    private const ADDRESS_ALLOWED = ['street', 'city', 'postalCode', 'countryCode'];
    private const CONTACT_ALLOWED = ['type', 'email', 'name'];
    private const MAX_CONTACTS = 3;

    /**
     * @param array<string,mixed> $body
     * @return array{
     *   organizationUuid:string, clientUuid:string, companyName:string,
     *   registrationNumber:?string, vatNumber:?string,
     *   street:string, city:?string, postalCode:?string, countryCode:?string,
     *   contacts:list<array{type:string,email:string,name:?string}>,
     *   language:string, active:bool, sourceUpdatedAt:string
     * }
     */
    public function validate(string $organizationUuid, string $clientUuid, array $body): array
    {
        $fields = [];
        $organizationUuid = $this->uuid($organizationUuid, 'organizationUuid', $fields);
        $clientUuid = $this->uuid($clientUuid, 'clientUuid', $fields);
        $this->rejectUnknown($body, self::ALLOWED, '', $fields);

        if (($body['specVersion'] ?? null) !== ReviziorContract::VERSION) {
            $fields['specVersion'] = 'must_equal_1.0';
        }
        $companyName = $this->requiredString($body, 'companyName', 190, $fields);
        $registrationNumber = $this->nullableString($body, 'registrationNumber', 20, $fields);
        $vatNumber = $this->nullableString($body, 'vatNumber', 20, $fields);

        $street = '';
        $city = $postalCode = $countryCode = null;
        $address = $body['address'] ?? null;
        if (!is_array($address)) {
            $fields['address'] = 'required_object';
        } else {
            $this->rejectUnknown($address, self::ADDRESS_ALLOWED, 'address.', $fields);
            $street = $this->requiredString($address, 'street', 190, $fields, 'address.');
            $city = $this->nullableString($address, 'city', 120, $fields, 'address.');
            $postalCode = $this->nullableString($address, 'postalCode', 10, $fields, 'address.');
            $countryCode = $this->nullableString($address, 'countryCode', 2, $fields, 'address.');
            if ($countryCode !== null && preg_match('/^[A-Z]{2}$/D', $countryCode) !== 1) {
                $fields['address.countryCode'] = 'invalid_country_code';
                $countryCode = null;
            }
        }

        $contacts = [];
        if (!array_key_exists('contacts', $body) || !is_array($body['contacts']) || !array_is_list($body['contacts'])) {
            $fields['contacts'] = 'required_array';
        } elseif (count($body['contacts']) > self::MAX_CONTACTS) {
            $fields['contacts'] = 'too_many';
        } else {
            foreach ($body['contacts'] as $i => $contact) {
                $prefix = "contacts.{$i}.";
                if (!is_array($contact)) {
                    $fields["contacts.{$i}"] = 'required_object';
                    continue;
                }
                $this->rejectUnknown($contact, self::CONTACT_ALLOWED, $prefix, $fields);
                $type = $contact['type'] ?? null;
                if ($type !== 'billing') {
                    $fields[$prefix . 'type'] = 'invalid_value';
                }
                $email = strtolower(trim((string) ($contact['email'] ?? '')));
                if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 190) {
                    $fields[$prefix . 'email'] = 'invalid_email';
                }
                $name = $this->nullableString($contact, 'name', 120, $fields, $prefix);
                $contacts[] = ['type' => 'billing', 'email' => $email, 'name' => $name];
            }
        }

        $language = $body['language'] ?? null;
        if (!is_string($language) || !in_array($language, ['cs', 'en'], true)) {
            $fields['language'] = 'invalid_value';
            $language = 'cs';
        }
        if (!array_key_exists('active', $body) || !is_bool($body['active'])) {
            $fields['active'] = 'required_boolean';
        }
        $active = is_bool($body['active'] ?? null) ? $body['active'] : false;
        $sourceUpdatedAt = $this->dateTime($body['sourceUpdatedAt'] ?? null, $fields);

        if ($fields !== []) {
            throw ReviziorProvisioningException::clientValidation($fields);
        }

        return [
            'organizationUuid' => $organizationUuid,
            'clientUuid' => $clientUuid,
            'companyName' => $companyName,
            'registrationNumber' => $registrationNumber,
            'vatNumber' => $vatNumber,
            'street' => $street,
            'city' => $city,
            'postalCode' => $postalCode,
            'countryCode' => $countryCode,
            'contacts' => $contacts,
            'language' => $language,
            'active' => $active,
            'sourceUpdatedAt' => $sourceUpdatedAt,
        ];
    }

    /** @param array<string,string> $fields */
    private function uuid(string $value, string $field, array &$fields): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) !== 1) {
            $fields[$field] = 'must_be_uuid';
            return '';
        }
        return $value;
    }

    /** @param array<string,mixed> $body @param array<string,string> $fields */
    private function requiredString(array $body, string $key, int $maxLength, array &$fields, string $prefix = ''): string
    {
        if (!array_key_exists($key, $body) || !is_string($body[$key])) {
            $fields[$prefix . $key] = 'required_string';
            return '';
        }
        $value = trim($body[$key]);
        if ($value === '') {
            $fields[$prefix . $key] = 'required';
        } elseif (mb_strlen($value) > $maxLength) {
            $fields[$prefix . $key] = 'too_long';
        }
        return $value;
    }

    /**
     * Klíč musí být přítomný (kontrakt používá explicitní `null`), hodnota
     * `null` nebo neprázdný řetězec. Prázdný řetězec se nepřijímá — vypadal by
     * jako vyplněná hodnota.
     *
     * @param array<string,mixed> $body @param array<string,string> $fields
     */
    private function nullableString(array $body, string $key, int $maxLength, array &$fields, string $prefix = ''): ?string
    {
        if (!array_key_exists($key, $body)) {
            $fields[$prefix . $key] = 'required_nullable';
            return null;
        }
        if ($body[$key] === null) {
            return null;
        }
        if (!is_string($body[$key])) {
            $fields[$prefix . $key] = 'must_be_string_or_null';
            return null;
        }
        $value = trim($body[$key]);
        if ($value === '') {
            $fields[$prefix . $key] = 'empty_string_use_null';
            return null;
        }
        if (mb_strlen($value) > $maxLength) {
            $fields[$prefix . $key] = 'too_long';
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
            return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        } catch (Throwable) {
            $fields['sourceUpdatedAt'] = 'must_be_rfc3339';
            return '';
        }
    }

    /** @param array<string,mixed> $body @param list<string> $allowed @param array<string,string> $fields */
    private function rejectUnknown(array $body, array $allowed, string $prefix, array &$fields): void
    {
        foreach (array_keys($body) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                $fields[$prefix . (string) $key] = 'unknown_field';
            }
        }
    }
}
