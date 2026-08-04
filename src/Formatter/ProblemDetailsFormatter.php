<?php

declare(strict_types=1);

namespace Kode\Exception\Formatter;

use Kode\Exception\Enum\ErrorCode;
use Kode\Exception\KodeException;
use Override;
use Throwable;

/**
 * RFC 9457 Problem Details 格式化器（v3 新增）
 *
 * 输出 application/problem+json，便于对接 OpenAPI / API 网关 / 前端统一错误处理：
 * {
 *   "type": "https://errors.example.com/E1001",
 *   "title": "请求参数错误",
 *   "status": 400,
 *   "detail": "email 字段不合法",
 *   "instance": "/api/users",
 *   "code": "E1001",
 *   "trace_id": "..."
 * }
 */
final class ProblemDetailsFormatter implements ResponseFormatterInterface
{
    public const string CONTENT_TYPE = 'application/problem+json; charset=utf-8';

    public function __construct(
        private readonly string $typeBaseUri = 'https://errors.kodephp.dev/',
        private readonly bool $isProduction = false,
        private readonly string $productionDetail = '系统繁忙，请稍后重试',
    ) {
    }

    #[Override]
    public function format(Throwable $exception): array
    {
        $isKode = $exception instanceof KodeException;
        $code = $isKode ? $exception->getErrorCode() : ErrorCode::Unknown->value;
        $enum = ErrorCode::parse($code);

        $problem = [
            'type' => $this->typeBaseUri . $code,
            'title' => $enum?->message() ?? '未知错误',
            'status' => $isKode ? $exception->getHttpStatusCode() : 500,
            'detail' => $this->resolveDetail($exception, $isKode),
            'code' => $code,
            'trace_id' => $isKode ? $exception->getTraceId() : KodeException::traceContext()->traceId,
        ];

        if (isset($_SERVER['REQUEST_URI'])) {
            $problem['instance'] = (string) $_SERVER['REQUEST_URI'];
        }

        if ($isKode) {
            $context = $exception->getErrorContext();
            if ($context !== []) {
                $problem['errors'] = $context;
            }

            $suggestion = $exception->getSuggestion();
            if ($suggestion !== null) {
                $problem['suggestion'] = $suggestion;
            }
        }

        return $problem;
    }

    #[Override]
    public function getContentType(): string
    {
        return self::CONTENT_TYPE;
    }

    private function resolveDetail(Throwable $exception, bool $isKode): string
    {
        if (!$isKode) {
            return $this->isProduction ? $this->productionDetail : $exception->getMessage();
        }

        /** @var KodeException $exception */
        if ($this->isProduction && $exception->isSystem()) {
            return $this->productionDetail;
        }

        return $exception->getErrorMsg();
    }
}
