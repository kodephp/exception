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
        $response = [
            'success' => false,
            'error' => [
                'message' => $exception->getMessage(),
            ],
        ];

        if ($exception instanceof KodeException) {
            $response['error']['code'] = $exception->getErrorCode();
            $response['error']['msg'] = $exception->getErrorMsg();
            $response['error']['type'] = $exception->getErrorType();
            $response['error']['trace_id'] = $exception->getTraceId();

            $context = $exception->getErrorContext();
            if (!empty($context)) {
                $response['error']['context'] = $context;
            }

            $response['error']['location'] = $exception->getLocation();

            if ($this->includeTrace) {
                $response['error']['chain'] = $exception->getCallChain();
            }
        } else {
            $response['error']['code'] = 'E9999';

            if ($this->isProduction) {
                $response['error']['message'] = '系统异常';
            }
        }

        return $response;
    }

    public function getContentType(): string
    {
        return 'application/json';
    }
}