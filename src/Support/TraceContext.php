<?php

declare(strict_types=1);

namespace Kode\Exception\Support;

/**
 * W3C Trace Context 上下文
 *
 * 实现 traceparent / tracestate 规范：
 *   traceparent: {version}-{trace-id}-{parent-id}-{trace-flags}
 *   例：00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01
 *
 * 所有入参一律经过严格十六进制校验，杜绝 v2 中「请求头 trace id 直接回写响应头」
 * 造成的 CRLF 响应头注入风险。
 */
final readonly class TraceContext
{
    public const string VERSION = '00';
    public const string HEADER_TRACEPARENT = 'traceparent';
    public const string HEADER_TRACESTATE = 'tracestate';
    public const string HEADER_LEGACY_TRACE_ID = 'x-trace-id';
    public const string HEADER_LEGACY_SPAN_ID = 'x-span-id';

    /** 采样标记位 */
    public const int FLAG_SAMPLED = 0x01;

    public function __construct(
        public string $traceId,
        public string $spanId,
        public ?string $parentSpanId = null,
        public int $flags = self::FLAG_SAMPLED,
        public private(set) string $traceState = '',
    ) {
    }

    /** 新建一条根链路 */
    public static function start(bool $sampled = true): self
    {
        return new self(
            traceId: IdGenerator::traceId(),
            spanId: IdGenerator::spanId(),
            parentSpanId: null,
            flags: $sampled ? self::FLAG_SAMPLED : 0,
        );
    }

    /**
     * 从请求头恢复链路，失败返回 null。
     *
     * @param array<string, string|array<int, string>> $headers 键名大小写不敏感
     */
    public static function fromHeaders(array $headers): ?self
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower(str_replace('_', '-', (string) $key))] = is_array($value)
                ? (string) ($value[0] ?? '')
                : (string) $value;
        }

        $traceparent = $normalized[self::HEADER_TRACEPARENT] ?? null;
        if ($traceparent !== null) {
            $context = self::parseTraceparent($traceparent);
            if ($context !== null) {
                $state = $normalized[self::HEADER_TRACESTATE] ?? '';

                return $context->withTraceState(self::sanitizeTraceState($state));
            }
        }

        // 兼容旧版 X-Trace-Id / X-Span-Id（v2 格式），仅接受合法十六进制
        $legacyTraceId = strtolower(trim($normalized[self::HEADER_LEGACY_TRACE_ID] ?? ''));
        if ($legacyTraceId !== '' && IdGenerator::isValidTraceId($legacyTraceId)) {
            $legacySpanId = strtolower(trim($normalized[self::HEADER_LEGACY_SPAN_ID] ?? ''));

            return new self(
                traceId: $legacyTraceId,
                spanId: IdGenerator::spanId(),
                parentSpanId: IdGenerator::isValidSpanId($legacySpanId) ? $legacySpanId : null,
            );
        }

        return null;
    }

    /** 从 $_SERVER 恢复链路 */
    public static function fromServer(?array $server = null): ?self
    {
        $server ??= $_SERVER;
        $headers = [];

        foreach ($server as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = is_scalar($value)
                    ? (string) $value
                    : '';
            }
        }

        return self::fromHeaders($headers);
    }

    /** 解析 traceparent 头，非法返回 null */
    public static function parseTraceparent(string $traceparent): ?self
    {
        $traceparent = strtolower(trim($traceparent));

        if (preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/', $traceparent, $m) !== 1) {
            return null;
        }

        // version ff 为保留值，非法
        if ($m[1] === 'ff') {
            return null;
        }

        if (!IdGenerator::isValidTraceId($m[2]) || !IdGenerator::isValidSpanId($m[3])) {
            return null;
        }

        return new self(
            traceId: $m[2],
            spanId: IdGenerator::spanId(),
            parentSpanId: $m[3],
            flags: (int) hexdec($m[4]),
        );
    }

    /** 派生子 span，保持同一 trace-id */
    public function child(): self
    {
        return new self(
            traceId: $this->traceId,
            spanId: IdGenerator::spanId(),
            parentSpanId: $this->spanId,
            flags: $this->flags,
            traceState: $this->traceState,
        );
    }

    public function withTraceState(string $traceState): self
    {
        return new self(
            traceId: $this->traceId,
            spanId: $this->spanId,
            parentSpanId: $this->parentSpanId,
            flags: $this->flags,
            traceState: $traceState,
        );
    }

    /** 是否被采样 */
    public function isSampled(): bool
    {
        return ($this->flags & self::FLAG_SAMPLED) === self::FLAG_SAMPLED;
    }

    /** 是否为根链路 */
    public function isRoot(): bool
    {
        return $this->parentSpanId === null;
    }

    /** 序列化为 traceparent 头值 */
    public function toTraceparent(): string
    {
        return sprintf(
            '%s-%s-%s-%02x',
            self::VERSION,
            $this->traceId,
            $this->spanId,
            $this->flags & 0xFF
        );
    }

    /**
     * 供下游调用透传的请求头
     *
     * @return array<string, string>
     */
    public function toHeaders(): array
    {
        $headers = [
            'traceparent' => $this->toTraceparent(),
            'X-Trace-Id' => $this->traceId,
            'X-Span-Id' => $this->spanId,
        ];

        if ($this->traceState !== '') {
            $headers['tracestate'] = $this->traceState;
        }

        return $headers;
    }

    public function toArray(): array
    {
        return [
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
            'parent_span_id' => $this->parentSpanId,
            'sampled' => $this->isSampled(),
            'trace_state' => $this->traceState,
        ];
    }

    /** 过滤 tracestate 中的非法字符，防止头注入 */
    private static function sanitizeTraceState(string $state): string
    {
        $clean = preg_replace('/[^\x20-\x7E]/', '', trim($state)) ?? '';

        return substr(str_replace(["\r", "\n"], '', $clean), 0, 512);
    }
}
