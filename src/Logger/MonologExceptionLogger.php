<?php

declare(strict_types=1);

namespace Kode\Exception\Logger;

use Kode\Exception\ExceptionInterface;
use Kode\Exception\HttpException;
use Kode\Exception\RuntimeException;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Monolog 异常日志记录器
 * 将异常记录到 Monolog 日志系统
 */
class MonologExceptionLogger
{
    /** @var LoggerInterface 日志记录器 */
    protected LoggerInterface $logger;
    /** 日志通道名 */
    protected string $channel;

    public function __construct(LoggerInterface $logger, string $channel = 'exception')
    {
        $this->logger = $logger;
        $this->channel = $channel;
    }

    /** 创建日志记录器实例 */
    public static function create(Logger $monolog, string $channel = 'exception'): self
    {
        return new self($monolog->channel($channel), $channel);
    }

    /** 记录异常 */
    public function log(Throwable $exception, array $context = []): void
    {
        $exceptionContext = $this->buildExceptionContext($exception);

        if (!empty($context)) {
            $exceptionContext['extra'] = $context;
        }

        $level = $this->determineLogLevel($exception);

        $this->logger->log($level, $exception->getMessage(), $exceptionContext);
    }

    /** 构建异常上下文 */
    protected function buildExceptionContext(Throwable $exception): array
    {
        $context = [
            'exception_class' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ];

        if ($exception instanceof ExceptionInterface) {
            $context['error_code'] = $exception->getErrorCode();
            $context['trace_id'] = $exception->getTraceId();
            $context['exception_context'] = $exception->getContext();
            $context['timestamp'] = $exception->getTimestamp()->format('Y-m-d H:i:s.u');

            if ($exception instanceof HttpException) {
                $context['http_status'] = $exception->getHttpStatusCode();
                $context['http_headers'] = $exception->getHeaders();
            }

            if ($exception instanceof RuntimeException) {
                $context['is_recoverable'] = $exception->isRecoverable();
                $context['suggestion'] = $exception->getSuggestion();
            }
        }

        if ($previous = $exception->getPrevious()) {
            $context['previous_exception'] = [
                'class' => get_class($previous),
                'message' => $previous->getMessage(),
                'file' => $previous->getFile(),
                'line' => $previous->getLine(),
            ];
        }

        return $context;
    }

    /** 确定日志级别 */
    protected function determineLogLevel(Throwable $exception): string
    {
        if ($exception instanceof HttpException) {
            $statusCode = $exception->getHttpStatusCode();

            if ($statusCode >= 500) {
                return 'error';
            }

            if ($statusCode >= 400) {
                return 'warning';
            }

            return 'info';
        }

        if ($exception instanceof RuntimeException) {
            if ($exception->isRecoverable()) {
                return 'error';
            }

            return 'critical';
        }

        if ($exception instanceof ExceptionInterface) {
            return 'error';
        }

        return 'critical';
    }

    /** 获取日志记录器 */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /** 获取日志通道 */
    public function getChannel(): string
    {
        return $this->channel;
    }
}