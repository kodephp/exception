<?php

declare(strict_types=1);

namespace Kode\Exception;

use Throwable;

/**
 * 运行时异常类
 * 用于处理多进程、多线程、协程环境下的运行时错误
 */
class RuntimeException extends BaseException
{
    /** 是否可恢复 */
    protected bool $isRecoverable;
    /** 修复建议 */
    protected string $suggestion;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        bool $isRecoverable = true,
        string $suggestion = '',
        array $context = []
    ) {
        $this->isRecoverable = $isRecoverable;
        $this->suggestion = $suggestion;
        parent::__construct($message, $code, $previous, 'RUNTIME_ERROR', $context);
    }

    public function isRecoverable(): bool
    {
        return $this->isRecoverable;
    }

    public function getSuggestion(): string
    {
        return $this->suggestion;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'is_recoverable' => $this->isRecoverable,
            'suggestion' => $this->suggestion,
            'type' => 'runtime',
        ]);
    }

    public static function coroutinePanic(string $message, ?Throwable $previous = null): self
    {
        return new self(
            $message,
            0,
            $previous,
            false,
            '确保协程正确等待，检查协程中未处理的异常',
            ['component' => 'coroutine']
        );
    }

    public static function workerCrash(string $message, ?Throwable $previous = null): self
    {
        return new self(
            $message,
            0,
            $previous,
            true,
            'Worker 将被 Supervisor 重启',
            ['component' => 'worker']
        );
    }

    public static function poolExhausted(string $message, ?Throwable $previous = null): self
    {
        return new self(
            $message,
            0,
            $previous,
            true,
            '考虑增加池大小或降低并发',
            ['component' => 'pool']
        );
    }
}