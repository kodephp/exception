<?php

declare(strict_types=1);

namespace Kode\Exception\Coroutine;

use Throwable;

/**
 * 协程异常上下文
 * 用于追踪协程内的异常信息和执行状态
 */
class CoroutineExceptionContext
{
    protected string $coroutineId;
    protected ?Throwable $exception = null;
    protected array $localContext = [];
    protected float $startTime;
    protected bool $isHandled = false;

    public function __construct(?int $coroutineId = null)
    {
        $this->coroutineId = (string)($coroutineId ?? self::generateCoroutineId());
        $this->startTime = microtime(true);
    }

    public function getCoroutineId(): string
    {
        return $this->coroutineId;
    }

    public function setException(Throwable $exception): void
    {
        $this->exception = $exception;
    }

    public function getException(): ?Throwable
    {
        return $this->exception;
    }

    public function hasException(): bool
    {
        return $this->exception !== null;
    }

    public function setLocalContext(string $key, mixed $value): void
    {
        $this->localContext[$key] = $value;
    }

    public function getLocalContext(string $key): mixed
    {
        return $this->localContext[$key] ?? null;
    }

    public function getAllLocalContext(): array
    {
        return $this->localContext;
    }

    public function markAsHandled(): void
    {
        $this->isHandled = true;
    }

    public function isHandled(): bool
    {
        return $this->isHandled;
    }

    public function getExecutionTime(): float
    {
        return microtime(true) - $this->startTime;
    }

    public function toArray(): array
    {
        return [
            'coroutine_id' => $this->coroutineId,
            'has_exception' => $this->hasException(),
            'exception_class' => $this->exception ? get_class($this->exception) : null,
            'exception_message' => $this->exception ? $this->exception->getMessage() : null,
            'is_handled' => $this->isHandled,
            'execution_time' => $this->getExecutionTime(),
            'local_context' => $this->localContext,
        ];
    }

    protected static function generateCoroutineId(): int
    {
        return mt_rand(1, PHP_INT_MAX);
    }
}