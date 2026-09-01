<?php

declare(strict_types=1);

namespace MyInvoice\Http;

use Psr\Http\Message\ResponseInterface as Response;

final class ReviziorResponse
{
    public const SPEC_VERSION = '1.0';
    public const CONTRACT_VERSION = '1.0';

    public static function success(Response $response, mixed $data, string $requestId, int $status = 200): Response
    {
        return self::headers(Json::ok($response, [
            'specVersion' => self::SPEC_VERSION,
            'data' => $data,
            'meta' => [
                'contractVersion' => self::CONTRACT_VERSION,
                'requestId' => $requestId,
            ],
        ], $status), $requestId);
    }

    /** @param array<string,string> $fields */
    public static function error(
        Response $response,
        string $code,
        string $message,
        int $status,
        string $requestId,
        bool $retryable = false,
        array $fields = [],
    ): Response {
        return self::headers(Json::ok($response, [
            'specVersion' => self::SPEC_VERSION,
            'error' => [
                'code' => $code,
                'message' => $message,
                'fields' => $fields === [] ? (object) [] : $fields,
                'retryable' => $retryable,
            ],
            'meta' => [
                'contractVersion' => self::CONTRACT_VERSION,
                'requestId' => $requestId,
            ],
        ], $status), $requestId);
    }

    private static function headers(Response $response, string $requestId): Response
    {
        return $response
            ->withHeader('X-Revizior-Contract-Version', self::CONTRACT_VERSION)
            ->withHeader('X-Request-Id', $requestId);
    }
}
