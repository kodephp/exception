<?php

declare(strict_types=1);

namespace Kode\Exception\Coroutine;

use Error;
use Fiber;
use Kode\Exception\KodeException;
use Throwable;

/**
 * 协程异常处理器
 * 支持 Swoole/Fiber 原生协程环境的异常处理
 */
class CoroutineExceptionHandler
{
    protected CoroutineExceptionContext $context;
    protected static array $coroutineContexts = [];
    protected static ?self $currentHandler = null;
    protected bool $isRecoverableFailure = false;

    public function __construct(?CoroutineExceptionContext $context = null)
    {
        $this->context = $context ?? new CoroutineExceptionContext();
    }

    public static function getCurrentContext(): ?CoroutineExceptionContext
    {
        $coroutineId = self::getCurrentCoroutineId();
        return self::$coroutineContexts[$coroutineId] ?? null;
    }

    public static function setCurrentContext(CoroutineExceptionContext $context): void
    {
        self::$coroutineContexts[$context->getCoroutineId()] = $context;
    }

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

    public function __invoke(Throwable $exception): void
    {
        $this->handle($exception);
    }

    public function handle(Throwable $exception): void
    {
        $previousHandler = self::$currentHandler;
        self::$currentHandler = $this;

        try {
            $this->context->setException($exception);

            if ($exception instanceof KodeException) {
                $this->logException($exception);
            }

            $this->context->markAsHandled();
        } finally {
            self::$currentHandler = $previousHandler;
        }
    }

    protected function logException(KodeException $exception): void
    {
    }

    public function getContext(): CoroutineExceptionContext
    {
        return $this->context;
    }

    public function isRecoverableFailure(): bool
    {
        return $this->isRecoverableFailure;
    }

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

    public static function clearContext(): void
    {
        $coroutineId = self::getCurrentCoroutineId();
        unset(self::$coroutineContexts[$coroutineId]);
    }

    public static function getAllContexts(): array
    {
        return self::$coroutineContexts;
    }
}