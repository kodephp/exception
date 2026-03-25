<?php

declare(strict_types=1);

namespace Kode\Exception\Formatter;

use Kode\Exception\ExceptionInterface;
use Kode\Exception\HttpException;
use Throwable;

/**
 * 响应格式化器接口
 */
interface ResponseFormatterInterface
{
    /** 格式化异常为数组 */
    public function format(Throwable $exception): array;

    /** 获取 Content-Type */
    public function getContentType(): string;
}