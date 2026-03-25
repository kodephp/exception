<?php

declare(strict_types=1);

namespace Kode\Exception\Coroutine;

use Error;
use Fiber;
use Kode\Exception\ExceptionInterface;
use Kode\Exception\RuntimeException;
use Throwable;

/**
 * 协程异常处理器
 * 支持 Swoole/Fiber 原生协程环境的异常处理
 */
class CoroutineExceptionHandler
{
    /** @var CoroutineExceptionContext 协程上下文 */
    protected CoroutineExceptionContext $context;
    /** 所有协程上下文存储 */
    protected static array $coroutineContexts = [];
    /** 当前处理器 */
    protected static ?self $currentHandler = null;
    /** 是否可恢复失败 */
    protected bool $isRecoverableFailure = false;

    public function __construct(?CoroutineExceptionContext $context = null)
    {
        $this->context = $context ?? new CoroutineExceptionContext();
    }

    /** 获取当前协程上下文 */
    public static function getCurrentContext(): ?CoroutineExceptionContext
    {
        $coroutineId = self::getCurrentCoroutineId();
        return self::$coroutineContexts[$coroutineId] ?? null;
    }

    /** 设置当前协程上下文 */
    public static function setCurrentContext(CoroutineExceptionContext $context): void
    {
        self::$coroutineContexts[$context->getCoroutineId()] = $context;
    }

    /** 获取当前协程 ID */
    public static function getCurrentCoroutineId(): string
    {
        if (function_exists('swooleCoroutine_getuid')) {
            return (string) swooleCoroutine_getuid();
        }

        if (function_exists('Fiber::getCurrent')) {
            $fiber = Fiber::getCurrent();
            return $fiber ? (string) spl_object_id($fiber) : 'main';
        }

        return 'main';
    }

    /** 是否在协程中执行 */
    public static function isInCoroutine(): bool
    {
        if (function_exists('swooleCoroutine_getuid')) {
            return swooleCoroutine_getuid() >= 0;
        }

        if (function_exists('Fiber::getCurrent')) {
            return Fiber::getCurrent() !== null;
        }

        return false;
    }

    /** 可调用处理 */
    public function __invoke(Throwable $exception): void
    {
        $this->handle($exception);
    }

    /** 处理异常 */
    public function handle(Throwable $exception): void
    {
        $previousHandler = self::$currentHandler;
        self::$currentHandler = $this;

        try {
            $this->context->setException($exception);

            if ($exception instanceof ExceptionInterface) {
                $this->logException($exception);
                $this->handleExceptionType($exception);
            } else {
                $this->handleUnknownException($exception);
            }

            $this->context->markAsHandled();
        } finally {
            self::$currentHandler = $previousHandler;
        }
    }

    protected function handleExceptionType(ExceptionInterface $exception): void
    {
        if ($exception instanceof RuntimeException && !$exception->isRecoverable()) {
            $this->isRecoverableFailure = true;
            $this->handleNonRecoverable($exception);
        }
    }

    protected function handleNonRecoverable(ExceptionInterface $exception): void
    {
        if ($this->context->getExecutionTime() > 5.0) {
            throw $exception;
        }
    }

    protected function handleUnknownException(Throwable $exception): void
    {
        $this->isRecoverableFailure = true;
    }

    protected function logException(ExceptionInterface $exception): void
    {
        $logData = [
            'coroutine_id' => $this->context->getCoroutineId(),
            'trace_id' => $exception->getTraceId(),
            'error_code' => $exception->getErrorCode(),
            'message' => $exception->getMessage(),
            'context' => $exception->getContext(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'execution_time' => $this->context->getExecutionTime(),
        ];
    }

    /** 获取协程上下文 */
    public function getContext(): CoroutineExceptionContext
    {
        return $this->context;
    }

    /** 是否可恢复失败 */
    public function isRecoverableFailure(): bool
    {
        return $this->isRecoverableFailure;
    }

    /** 安全运行协程代码 */
    public static function runSafely(callable $callback, ?callable $exceptionCallback = null): mixed
    {
        $handler = new self();
        self::setCurrentContext($handler->getContext());

        try {
            return $callback();
        } catch (Throwable $e) {
            $handler->handle($e);

            if ($exceptionCallback !== null) {
                return $exceptionCallback($e, $handler->getContext());
            }

            return null;
        } finally {
            $handler->getContext()->markAsHandled();
        }
    }

    /** 清除协程上下文 */
    public static function clearContext(): void
    {
        $coroutineId = self::getCurrentCoroutineId();
        unset(self::$coroutineContexts[$coroutineId]);
    }

    /** 获取所有协程上下文 */
    public static function getAllContexts(): array
    {
        return self::$coroutineContexts;
    }
}