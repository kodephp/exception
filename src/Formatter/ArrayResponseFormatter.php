<?php

declare(strict_types=1);

namespace Kode\Exception\Formatter;

use Kode\Exception\KodeException;
use Throwable;

/**
 * 数组响应格式化器
 * 将异常格式化为数组结构
 */
class ArrayResponseFormatter implements ResponseFormatterInterface
{
    protected bool $isProduction;

    public function __construct(bool $isProduction = false)
    {
        $this->isProduction = $isProduction;
    }

    public function format(Throwable $exception): array
    {
        if ($exception instanceof KodeException) {
            return $exception->toArray();
        }

        return [
            'code' => 'E9999',
            'msg' => $this->isProduction ? '系统异常' : $exception->getMessage(),
            'type' => 'system',
            'location' => [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ],
        ];
    }

    public function getContentType(): string
    {
        return 'application/x-array+json';
    }
}