<?php

declare(strict_types=1);

namespace Kode\Exception\Logger;

use Kode\Exception\KodeException;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Monolog 异常日志记录器
 * 将异常记录到 Monolog 日志系统
 */
class MonologExceptionLogger
{
    protected LoggerInterface $logger;
    protected string $channel;

    public function __construct(LoggerInterface $logger, string $channel = 'exception')
    {
        $this->logger = $logger;
        $this->channel = $channel;
    }

    public static function create(Logger $monolog, string $channel = 'exception'): self
    {
        return new self($monolog->channel($channel), $channel);
    }

    public function log(Throwable $exception, array $context = []): void
    {
        $exceptionContext = $this->buildExceptionContext($exception);

        if (!empty($context)) {
            $exceptionContext['extra'] = $context;
        }

        $level = $this->determineLogLevel($exception);

        $this->logger->log($level, $exception->getMessage(), $exceptionContext);
    }

    protected function buildExceptionContext(Throwable $exception): array
    {
        $context = [
            'exception_class' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];

        if ($exception instanceof KodeException) {
            $context['code'] = $exception->getErrorCode();
            $context['msg'] = $exception->getErrorMsg();
            $context['type'] = $exception->getErrorType();
            $context['trace_id'] = $exception->getTraceId();
            $context['context'] = $exception->getErrorContext();
            $context['location'] = $exception->getLocation();
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

    protected function determineLogLevel(Throwable $exception): string
    {
        if ($exception instanceof KodeException) {
            $type = $exception->getErrorType();
            $code = $exception->getErrorCode();

            if ($type === KodeException::TYPE_HTTP) {
                if (in_array($code, [KodeException::CODE_INTERNAL_ERROR, KodeException::CODE_SERVER_ERROR])) {
                    return 'error';
                }
                return 'warning';
            }

            if ($type === KodeException::TYPE_RUNTIME) {
                $ctx = $exception->getErrorContext();
                return isset($ctx['suggestion']) ? 'error' : 'critical';
            }

            return 'error';
        }

        return 'critical';
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }
}