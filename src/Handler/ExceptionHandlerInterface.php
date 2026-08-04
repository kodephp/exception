<?php

declare(strict_types=1);

namespace Kode\Exception\Handler;

use Throwable;

/**
 * 异常处理器接口
 *
 * 由 {@see \Kode\Exception\ExceptionManager::dispatch()} 按优先级降序调用，
 * 返回 true 表示已处理并终止责任链。
 */
interface ExceptionHandlerInterface
{
    /** 处理异常，返回是否已处理 */
    public function handle(Throwable $exception): bool;

    /** 处理器优先级，数值越大越先执行 */
    public function getPriority(): int;
}
