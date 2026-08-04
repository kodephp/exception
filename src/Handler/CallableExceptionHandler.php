<?php

declare(strict_types=1);

namespace Kode\Exception\Handler;

use Override;
use Throwable;

/**
 * 闭包异常处理器（v3 新增）
 *
 * 无需为一次性规则单独建类：
 *
 * ```php
 * $manager->addHandler(new CallableExceptionHandler(
 *     static fn (Throwable $e): bool => $e instanceof PDOException && $alert($e),
 *     priority: 200
 * ));
 * ```
 */
final class CallableExceptionHandler implements ExceptionHandlerInterface
{
    /** @param callable(Throwable):bool $callback */
    public function __construct(
        private $callback,
        private readonly int $priority = 0,
        private readonly ?string $only = null,
    ) {
    }

    #[Override]
    public function handle(Throwable $exception): bool
    {
        if ($this->only !== null && !($exception instanceof $this->only)) {
            return false;
        }

        return ($this->callback)($exception) === true;
    }

    #[Override]
    public function getPriority(): int
    {
        return $this->priority;
    }
}
