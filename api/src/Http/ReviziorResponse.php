<?php

declare(strict_types=1);

namespace MyInvoice\Http;

use Psr\Http\Message\ResponseInterface as Response;

final class ReviziorResponse
{
    public const SPEC_VERSION = '1.0';
    public const CONTRACT_VERSION = '1.0';

    public static function success(Response $response, mixed $data, string $requestId): Response
    {
        return Json::ok($response, [
            'specVersion' => self::SPEC_VERSION,
            'data' => $data,
            'meta' => [
                'contractVersion' => self::CONTRACT_VERSION,
                'requestId' => $requestId,
            ],
        ]);
    }

    public static function error(
        Response $response,
        string $code,
        string $message,
        int $status,
        string $requestId,
        bool $retryable = false,
    ): Response {
        return Json::ok($response, [
            'specVersion' => self::SPEC_VERSION,
            'error' => [
                'code' => $code,
                'message' => $message,
                'fields' => (object) [],
                'retryable' => $retryable,
            ],
            'meta' => [
                'contractVersion' => self::CONTRACT_VERSION,
                'requestId' => $requestId,
            ],
        ], $status);
    }
}
