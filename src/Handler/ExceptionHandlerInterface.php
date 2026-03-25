<?php

declare(strict_types=1);

namespace Kode\Exception\Handler;

use Throwable;

/**
 * 异常处理器接口
 */
interface ExceptionHandlerInterface
{
    /** 处理异常，返回是否已处理 */
    public function handle(Throwable $exception): bool;

    /** 获取处理器优先级 */
    public function getPriority(): int;
}