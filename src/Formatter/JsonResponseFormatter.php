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
 * 精简 JSON 响应格式化器
 *
 * 与 UnifiedResponseFormatter 的差异：默认不输出调用链，适合对外网关。
 */
final class JsonResponseFormatter implements ResponseFormatterInterface
{
    public function __construct(
        private readonly bool $isProduction = false,
        private readonly bool $includeTrace = false,
        private readonly string $productionMessage = '系统繁忙，请稍后重试',
    ) {
    }

    #[Override]
    public function format(Throwable $exception): array
    {
        if ($exception instanceof KodeException) {
            $response = [
                'code' => $exception->getErrorCode(),
                'msg' => $exception->getErrorMsg(),
                'type' => $exception->getErrorType(),
                'trace_id' => $exception->getTraceId(),
            ];

            if (!$this->isProduction) {
                $response['location'] = $exception->getLocation();
            }

            $context = $exception->getErrorContext();
            if ($context !== []) {
                $response['context'] = $context;
            }

            if ($this->includeTrace && !$this->isProduction) {
                $response['chain'] = $exception->getCallChain();
            }

            return $response;
        }

        return [
            'code' => ErrorCode::Unknown->value,
            'msg' => $this->isProduction ? $this->productionMessage : $exception->getMessage(),
            'type' => ErrorType::System->value,
            'trace_id' => KodeException::traceContext()->traceId,
            'span_id' => IdGenerator::spanId(),
        ];
    }

    #[Override]
    public function getContentType(): string
    {
        return 'application/json; charset=utf-8';
    }
}
