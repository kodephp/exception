<?php

declare(strict_types=1);

namespace Kode\Exception\Formatter;

use Kode\Exception\KodeException;
use Throwable;

/**
 * JSON 响应格式化器
 * 将异常格式化为标准 JSON 响应结构
 */
class JsonResponseFormatter implements ResponseFormatterInterface
{
    protected bool $isProduction;
    protected bool $includeTrace;

    public function __construct(bool $isProduction = false, bool $includeTrace = false)
    {
        $this->isProduction = $isProduction;
        $this->includeTrace = $includeTrace;
    }

    public function format(Throwable $exception): array
    {
        if ($exception instanceof KodeException) {
            $response = [
                'code' => $exception->getErrorCode(),
                'msg' => $exception->getErrorMsg(),
                'type' => $exception->getErrorType(),
                'trace_id' => $exception->getTraceId(),
                'location' => $exception->getLocation(),
            ];

            $context = $exception->getErrorContext();
            if (!empty($context)) {
                $response['context'] = $context;
            }

            if ($this->includeTrace) {
                $response['chain'] = $exception->getCallChain();
            }

            return $response;
        }

        return [
            'code' => 'E9999',
            'msg' => $this->isProduction ? '系统异常' : $exception->getMessage(),
            'type' => 'system',
            'trace_id' => $this->generateTraceId(),
            'location' => [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ],
        ];
    }

    protected function generateTraceId(): string
    {
        return sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            time(),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffffffffffff)
        );
    }

    public function getContentType(): string
    {
        return 'application/json';
    }
}