<?php

declare(strict_types=1);

namespace Kode\Exception\Formatter;

use Kode\Exception\Enum\ErrorCode;
use Kode\Exception\Enum\ErrorType;
use Kode\Exception\KodeException;
use Kode\Exception\Support\IdGenerator;
use Override;
use Throwable;

/**
 * 统一响应格式化器
 *
 * 输出结构：{code, msg, type, trace_id, span_id, location, context, chain, suggestion}
 *
 * 生产模式下自动收敛：隐藏堆栈实参、隐藏绝对路径、隐藏原始异常消息，
 * 避免 v2 中把服务器目录结构与函数实参直接暴露给客户端。
 */
final class UnifiedResponseFormatter implements ResponseFormatterInterface
{
    public function __construct(
        private readonly bool $isProduction = false,
        private readonly bool $includeChain = true,
        private readonly string $productionMessage = '系统繁忙，请稍后重试',
    ) {
    }

    #[Override]
    public function format(Throwable $exception): array
    {
        return $exception instanceof KodeException
            ? $this->formatKodeException($exception)
            : $this->formatGenericException($exception);
    }

    #[Override]
    public function getContentType(): string
    {
        return 'application/json; charset=utf-8';
    }

    private function formatKodeException(KodeException $exception): array
    {
        $response = [
            'code' => $exception->getErrorCode(),
            'msg' => $exception->getErrorMsg(),
            'type' => $exception->getErrorType(),
            'trace_id' => $exception->getTraceId(),
            'span_id' => $exception->getSpanId(),
        ];

        // 生产模式下系统级异常不回显内部细节
        if ($this->isProduction && $exception->isSystem()) {
            $response['msg'] = $this->productionMessage;

            return $response;
        }

        $response['location'] = $this->maskLocation($exception->getLocation());

        $context = $exception->getErrorContext();
        if ($context !== []) {
            $response['context'] = $context;
        }

        $suggestion = $exception->getSuggestion();
        if ($suggestion !== null) {
            $response['suggestion'] = $suggestion;
        }

        if ($this->includeChain && !$this->isProduction) {
            $response['chain'] = $exception->getCallChain();
        }

        return $response;
    }

    private function formatGenericException(Throwable $exception): array
    {
        $response = [
            'code' => ErrorCode::Unknown->value,
            'msg' => $this->isProduction ? $this->productionMessage : $exception->getMessage(),
            'type' => ErrorType::System->value,
            'trace_id' => KodeException::traceContext()->traceId,
            'span_id' => IdGenerator::spanId(),
        ];

        if ($this->isProduction) {
            return $response;
        }

        $response['location'] = [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'method' => $exception::class,
        ];
        $response['class'] = $exception::class;
        $response['chain'] = $this->buildSimpleChain($exception->getTrace());

        return $response;
    }

    /** @return list<array{file: string, line: int, method: string}> */
    private function buildSimpleChain(array $trace): array
    {
        $chain = [];

        foreach (array_slice($trace, 0, 10) as $frame) {
            $chain[] = [
                'file' => (string) ($frame['file'] ?? 'unknown'),
                'line' => (int) ($frame['line'] ?? 0),
                'method' => $this->formatMethod($frame),
            ];
        }

        return $chain;
    }

    private function formatMethod(array $frame): string
    {
        $method = '';
        if (isset($frame['class'])) {
            $method .= $frame['class'] . ($frame['type'] ?? '::');
        }

        return $method . ($frame['function'] ?? 'unknown') . '()';
    }

    /** 生产模式下把绝对路径收敛为文件名 */
    private function maskLocation(array $location): array
    {
        if (!$this->isProduction) {
            return $location;
        }

        $location['file'] = basename((string) ($location['file'] ?? 'unknown'));

        return $location;
    }
}
