<?php

declare(strict_types=1);

namespace Kode\Exception\Logger;

use Throwable;

/**
 * 追踪信息处理器
 * 用于 Monolog 日志中处理异常追踪信息
 */
class TraceProcessor
{
    protected int $maxDepth = 10;
    protected array $skipClasses = [
        'Kode\\Exception\\',
        'Monolog\\',
        'Psr\\Log\\',
    ];

    public function __invoke(array $record): array
    {
        if (isset($record['context']['exception']) && $record['context']['exception'] instanceof Throwable) {
            $record['context']['exception_data'] = $this->processException(
                $record['context']['exception']
            );
        }

        return $record;
    }

    public function processException(Throwable $exception): array
    {
        $data = [
            'message' => $exception->getMessage(),
            'class' => get_class($exception),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];

        if ($exception instanceof \Kode\Exception\KodeException) {
            $data['code'] = $exception->getErrorCode();
            $data['msg'] = $exception->getErrorMsg();
            $data['type'] = $exception->getErrorType();
            $data['trace_id'] = $exception->getTraceId();
            $data['context'] = $exception->getErrorContext();
        }

        $data['trace'] = $this->processTrace($exception->getTrace());

        if ($previous = $exception->getPrevious()) {
            $data['previous'] = $this->processException($previous);
        }

        return $data;
    }

    protected function processTrace(array $trace): array
    {
        $processed = [];
        $depth = 0;

        foreach ($trace as $frame) {
            if ($depth >= $this->maxDepth) {
                break;
            }

            if (isset($frame['class']) && $this->shouldSkipClass($frame['class'])) {
                continue;
            }

            $processed[] = [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $this->formatFunction($frame),
                'class' => $frame['class'] ?? null,
            ];

            $depth++;
        }

        return $processed;
    }

    protected function formatFunction(array $frame): string
    {
        $function = $frame['function'] ?? '';

        if (isset($frame['class'])) {
            return $frame['class'] . ($frame['type'] ?? '::') . $function;
        }

        return $function;
    }

    protected function shouldSkipClass(string $class): bool
    {
        foreach ($this->skipClasses as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function setMaxDepth(int $depth): self
    {
        $this->maxDepth = $depth;
        return $this;
    }

    public function addSkipClass(string $classPrefix): self
    {
        $this->skipClasses[] = $classPrefix;
        return $this;
    }
}