<?php

declare(strict_types=1);

namespace Kode\Exception\Formatter;

use Throwable;

/**
 * 响应格式化器接口
 */
interface ResponseFormatterInterface
{
    /**
     * 将异常格式化为响应数组
     *
     * @return array<string, mixed>
     */
    public function format(Throwable $exception): array;

    /** 响应 Content-Type */
    public function getContentType(): string;
}
