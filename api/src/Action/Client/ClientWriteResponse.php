<?php

declare(strict_types=1);

namespace MyInvoice\Action\Client;

use MyInvoice\Http\Json;
use MyInvoice\Service\Client\ClientWriteException;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Mapování chyb {@see ClientWriteException} na dosavadní JSON odpovědi UI API.
 *
 * Kódy, statusy i hlášky jsou stejné jako před vytažením `ClientWriter`
 * — frontend na ně spoléhá.
 */
final class ClientWriteResponse
{
    public static function error(Response $response, ClientWriteException $e): Response
    {
        return match ($e->kind) {
            ClientWriteException::KIND_VALIDATION => Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $e->fields]),
            ClientWriteException::KIND_INTEGRITY => Json::error($response, 'integrity_violation', $e->getMessage(), 400),
            ClientWriteException::KIND_CONTACTS => Json::error($response, 'invalid_email_contacts', $e->getMessage(), 422),
            default => Json::error($response, 'not_found', 'Klient nenalezen.', 404),
        };
    }
}
