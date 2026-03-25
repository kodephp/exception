<?php

declare(strict_types=1);

namespace Kode\Exception\Formatter;

use Throwable;

/**
 * 响应格式化器接口
 */
interface ResponseFormatterInterface
{
    public function format(Throwable $exception): array;

    public function getContentType(): string;
}