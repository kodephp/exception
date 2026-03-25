<?php

declare(strict_types=1);

namespace Kode\Exception\Formatter;

use Kode\Exception\ExceptionInterface;
use Kode\Exception\HttpException;
use Throwable;

/**
 * JSON 响应格式化器
 * 将异常格式化为标准 JSON 响应结构
 */
class JsonResponseFormatter implements ResponseFormatterInterface
{
    /** 是否生产模式 */
    protected bool $isProduction;
    /** 是否包含堆栈追踪 */
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
                'message' => $this->isProduction && !($exception instanceof ExceptionInterface)
                    ? 'An internal error occurred'
                    : $exception->getMessage(),
            ],
        ];

        if ($exception instanceof ExceptionInterface) {
            $response['error']['code'] = $exception->getErrorCode();
            $response['error']['trace_id'] = $exception->getTraceId();

            if (!empty($exception->getContext())) {
                $response['error']['context'] = $exception->getContext();
            }

            if ($exception instanceof HttpException) {
                $response['error']['http_status'] = $exception->getHttpStatusCode();
            }

            if (!$this->isProduction) {
                $response['error']['file'] = $exception->getFile();
                $response['error']['line'] = $exception->getLine();
            }
        } else {
            $response['error']['code'] = 'INTERNAL_ERROR';

            if (!$this->isProduction) {
                $response['error']['file'] = $exception->getFile();
                $response['error']['line'] = $exception->getLine();
            }
        }

        if ($this->includeTrace && !$this->isProduction) {
            $response['error']['trace'] = $this->sanitizeTrace($exception->getTrace());
        }

        return $response;
    }

    public function getContentType(): string
    {
        return 'application/json';
    }

    protected function sanitizeTrace(array $trace): array
    {
        return array_map(function ($frame) {
            return [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
            ];
        }, $trace);
    }
}