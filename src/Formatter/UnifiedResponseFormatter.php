<?php

declare(strict_types=1);

namespace Kode\Exception\Formatter;

use Kode\Exception\KodeException;
use Throwable;

/**
 * 统一响应格式化器
 * 输出格式: 直接返回 {code, msg, type, trace_id, location, chain, ...}
 */
class UnifiedResponseFormatter implements ResponseFormatterInterface
{
    /** @var bool 是否生产模式 */
    protected bool $isProduction;
    /** @var bool 是否包含完整链路 */
    protected bool $includeChain;

    public function __construct(bool $isProduction = false, bool $includeChain = true)
    {
        $this->isProduction = $isProduction;
        $this->includeChain = $includeChain;
    }

    public function format(Throwable $exception): array
    {
        if ($exception instanceof KodeException) {
            return $this->formatKodeException($exception);
        }

        return $this->formatGenericException($exception);
    }

    protected function formatKodeException(KodeException $exception): array
    {
        $response = [
            'code' => $exception->getErrorCode(),
            'msg' => $exception->getErrorMsg(),
            'type' => $exception->getErrorType(),
            'trace_id' => $exception->getTraceId(),
        ];

        $response['location'] = $exception->getLocation();

        $context = $exception->getErrorContext();
        if (!empty($context)) {
            $response['context'] = $context;
        }

        if ($this->includeChain) {
            $response['chain'] = $exception->getCallChain();
        }

        return $response;
    }

    protected function formatGenericException(Throwable $exception): array
    {
        $response = [
            'code' => 'E9999',
            'msg' => $this->isProduction ? '系统异常' : $exception->getMessage(),
            'type' => 'system',
            'trace_id' => $this->generateTempTraceId(),
            'location' => [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'method' => get_class($exception),
            ],
        ];

        if (!$this->isProduction) {
            $response['class'] = get_class($exception);
            $response['chain'] = $this->buildSimpleChain($exception->getTrace());
        }

        return $response;
    }

    protected function generateTempTraceId(): string
    {
        return sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            time(),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffffffffffff)
        );
    }

    protected function buildSimpleChain(array $trace): array
    {
        $chain = [];
        foreach (array_slice($trace, 0, 10) as $frame) {
            $chain[] = [
                'file' => $frame['file'] ?? 'unknown',
                'line' => $frame['line'] ?? 0,
                'method' => $this->formatMethod($frame),
            ];
        }
        return $chain;
    }

    protected function formatMethod(array $frame): string
    {
        $method = '';
        if (isset($frame['class'])) {
            $method .= $frame['class'] . ($frame['type'] ?? '::');
        }
        $method .= ($frame['function'] ?? 'unknown') . '()';
        return $method;
    }

    public function getContentType(): string
    {
        return 'application/json';
    }
}