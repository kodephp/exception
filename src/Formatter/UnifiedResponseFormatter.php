<?php

declare(strict_types=1);

namespace Kode\Exception\Formatter;

use Kode\Exception\KodeException;
use Throwable;

/**
 * 统一响应格式化器
 * 输出格式: {success: bool, error: {code, msg, type, ...}}
 */
class UnifiedResponseFormatter implements ResponseFormatterInterface
{
    /** 是否生产模式 */
    protected bool $isProduction;
    /** 是否包含完整链路 */
    protected bool $includeTraceChain;

    public function __construct(bool $isProduction = false, bool $includeTraceChain = true)
    {
        $this->isProduction = $isProduction;
        $this->includeTraceChain = $includeTraceChain;
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
        $error = [
            'code' => $exception->getErrorCode(),
            'msg' => $exception->getErrorMsg(),
            'type' => $exception->getErrorType(),
            'trace_id' => $exception->getTraceId(),
        ];

        $context = $exception->getErrorContext();
        if (!empty($context)) {
            $error['context'] = $context;
        }

        if ($this->includeTraceChain) {
            $error['trace'] = $exception->getTraceChain();
            $error['distributed'] = $exception->getDistributedTrace();
        }

        if (!$this->isProduction) {
            $error['file'] = $exception->getFile();
            $error['line'] = $exception->getLine();
        }

        return [
            'success' => false,
            'error' => $error,
        ];
    }

    protected function formatGenericException(Throwable $exception): array
    {
        $error = [
            'code' => 'E9999',
            'msg' => $this->isProduction ? '系统异常' : $exception->getMessage(),
            'type' => 'system',
            'trace_id' => $this->generateTempTraceId(),
        ];

        if (!$this->isProduction) {
            $error['file'] = $exception->getFile();
            $error['line'] = $exception->getLine();
            $error['class'] = get_class($exception);
        }

        return [
            'success' => false,
            'error' => $error,
        ];
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

    public function getContentType(): string
    {
        return 'application/json';
    }

    public function formatChainReport(array $report): array
    {
        return [
            'success' => false,
            'error' => [
                'code' => $report['error_code'] ?? 'E9999',
                'msg' => $report['message'] ?? '链路追踪报告',
                'type' => 'chain_report',
                'trace_id' => $report['trace_id'] ?? '',
                'chain' => $report['chain'] ?? [],
                'source' => $report['source'] ?? null,
                'environment' => $report['environment'] ?? [],
            ],
        ];
    }
}