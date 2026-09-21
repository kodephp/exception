<?php

declare(strict_types=1);

namespace Kode\Exception\Formatter;

use Kode\Exception\Enum\ErrorCode;
use Kode\Exception\Enum\ErrorType;
use Kode\Exception\KodeException;
use Override;
use Throwable;

/**
 * 数组响应格式化器
 *
 * 输出异常的完整数组结构，供内部服务间调用或写入队列。
 */
final class ArrayResponseFormatter implements ResponseFormatterInterface
{
    public function __construct(
        private readonly bool $isProduction = false,
        private readonly string $productionMessage = '系统繁忙，请稍后重试',
    ) {
    }

    #[Override]
    public function format(Throwable $exception): array
    {
        if ($exception instanceof KodeException) {
            $data = $exception->toArray();

            if ($this->isProduction) {
                // 生产态对外只暴露定位用的 trace_id：源码绝对路径与调用链不外泄
                $location = $data['location'] ?? [];
                $data['location'] = [
                    'file' => isset($location['file']) ? basename((string) $location['file']) : '',
                    'line' => $location['line'] ?? 0,
                    'method' => $location['method'] ?? '',
                ];
                unset($data['chain']);
            }

            return $data;
        }

        return [
            'code' => ErrorCode::Unknown->value,
            'msg' => $this->isProduction ? $this->productionMessage : $exception->getMessage(),
            'type' => ErrorType::System->value,
            'trace_id' => KodeException::traceContext()->traceId,
            'location' => [
                'file' => $this->isProduction ? basename($exception->getFile()) : $exception->getFile(),
                'line' => $exception->getLine(),
                'method' => $exception::class,
            ],
        ];
    }

    #[Override]
    public function getContentType(): string
    {
        return 'application/json; charset=utf-8';
    }
}
