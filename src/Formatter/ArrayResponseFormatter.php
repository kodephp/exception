<?php

declare(strict_types=1);

namespace Kode\Exception\Formatter;

use Kode\Exception\ExceptionInterface;
use Throwable;

/**
 * 数组响应格式化器
 * 将异常格式化为数组结构
 */
class ArrayResponseFormatter implements ResponseFormatterInterface
{
    /** 是否生产模式 */
    protected bool $isProduction;

    public function __construct(bool $isProduction = false)
    {
        $this->isProduction = $isProduction;
    }

    public function format(Throwable $exception): array
    {
        if ($exception instanceof ExceptionInterface) {
            return $exception->toArray();
        }

        return [
            'error_code' => 'INTERNAL_ERROR',
            'message' => $this->isProduction ? 'An internal error occurred' : $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'timestamp' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    public function getContentType(): string
    {
        return 'application/x-array+json';
    }
}