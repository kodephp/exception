<?php

declare(strict_types=1);

namespace Kode\Exception;

use Throwable;

/**
 * HTTP 异常类
 * 用于处理 HTTP 错误状态码 (400/401/403/404/422/500/502/503/504)
 */
class HttpException extends BaseException
{
    /** HTTP 状态码 */
    protected int $httpStatusCode;
    /** HTTP 响应头 */
    protected array $headers;

    public function __construct(
        int $httpStatusCode,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        array $headers = [],
        string $errorCode = 'HTTP_ERROR'
    ) {
        $this->httpStatusCode = $httpStatusCode;
        $this->headers = $headers;

        if ($message === '') {
            $message = $this->getDefaultMessage($httpStatusCode);
        }

        parent::__construct($message, $code, $previous, $errorCode, [
            'http_status' => $httpStatusCode,
        ]);
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'http_status_code' => $this->httpStatusCode,
            'headers' => $this->headers,
        ]);
    }

    protected function getDefaultMessage(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            default => 'HTTP Error',
        };
    }

    public static function badRequest(string $message = '', ?Throwable $previous = null): self
    {
        return new self(400, $message, 0, $previous, [], 'BAD_REQUEST');
    }

    public static function unauthorized(string $message = '', ?Throwable $previous = null): self
    {
        return new self(401, $message, 0, $previous, [], 'UNAUTHORIZED');
    }

    public static function forbidden(string $message = '', ?Throwable $previous = null): self
    {
        return new self(403, $message, 0, $previous, [], 'FORBIDDEN');
    }

    public static function notFound(string $message = '', ?Throwable $previous = null): self
    {
        return new self(404, $message, 0, $previous, [], 'NOT_FOUND');
    }

    public static function unprocessableEntity(string $message = '', ?Throwable $previous = null): self
    {
        return new self(422, $message, 0, $previous, [], 'UNPROCESSABLE_ENTITY');
    }

    public static function internalServerError(string $message = '', ?Throwable $previous = null): self
    {
        return new self(500, $message, 0, $previous, [], 'INTERNAL_SERVER_ERROR');
    }

    public static function serviceUnavailable(string $message = '', ?Throwable $previous = null): self
    {
        return new self(503, $message, 0, $previous, [], 'SERVICE_UNAVAILABLE');
    }
}