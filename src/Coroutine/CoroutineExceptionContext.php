<?php

declare(strict_types=1);

namespace Kode\Exception\Coroutine;

use Kode\ExceptionManager;
use Kode\Exception\ExceptionInterface;
use Throwable;

/**
 * 协程异常上下文
 * 用于追踪协程内的异常信息和执行状态
 */
class CoroutineExceptionContext
{
    /** 协程 ID */
    protected string $coroutineId;
    /** 捕获的异常 */
    protected ?Throwable $exception = null;
    /** 局部上下文数据 */
    protected array $localContext = [];
    /** 开始时间 */
    protected float $startTime;
    /** 是否已处理 */
    protected bool $isHandled = false;

    public function __construct(?int $coroutineId = null)
    {
        $this->coroutineId = (string)($coroutineId ?? self::generateCoroutineId());
        $this->startTime = microtime(true);
    }

    /** 获取协程 ID */
    public function getCoroutineId(): string
    {
        return $this->coroutineId;
    }

    /** 设置异常 */
    public function setException(Throwable $exception): void
    {
        $this->exception = $exception;
    }

    /** 获取异常 */
    public function getException(): ?Throwable
    {
        return $this->exception;
    }

    /** 是否有异常 */
    public function hasException(): bool
    {
        return $this->exception !== null;
    }

    /** 设置局部上下文 */
    public function setLocalContext(string $key, mixed $value): void
    {
        $this->localContext[$key] = $value;
    }

    /** 获取局部上下文 */
    public function getLocalContext(string $key): mixed
    {
        return $this->localContext[$key] ?? null;
    }

    /** 获取所有局部上下文 */
    public function getAllLocalContext(): array
    {
        return $this->localContext;
    }

    /** 标记为已处理 */
    public function markAsHandled(): void
    {
        $this->isHandled = true;
    }

    /** 是否已处理 */
    public function isHandled(): bool
    {
        return $this->isHandled;
    }

    /** 获取执行时间（秒） */
    public function getExecutionTime(): float
    {
        return microtime(true) - $this->startTime;
    }

    /** 转换为数组 */
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