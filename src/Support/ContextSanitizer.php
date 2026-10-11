<?php

declare(strict_types=1);

namespace Kode\Exception\Support;

use Throwable;
use UnitEnum;

/**
 * 上下文脱敏器
 *
 * v2 直接把异常上下文与堆栈实参 var_export 后写入日志与 HTTP 响应，
 * 密码 / token / 身份证等敏感字段会明文外泄。此处统一在输出前遮蔽。
 */
final class ContextSanitizer
{
    /** 默认遮蔽文本 */
    public const string DEFAULT_MASK = '***';
    /** 默认递归深度上限 */
    public const int DEFAULT_MAX_DEPTH = 6;
    /** 默认字符串截断长度 */
    public const int DEFAULT_MAX_STRING = 512;

    /** @var list<string> 命中即遮蔽的键名片段（小写） */
    private array $sensitiveKeys = [
        'password', 'passwd', 'pwd', 'secret', 'token', 'authorization', 'auth',
        'api_key', 'apikey', 'access_key', 'secret_key', 'private_key',
        'credential', 'session', 'cookie', 'signature', 'sign',
        'id_card', 'idcard', 'card_no', 'bank_card', 'cvv', 'salt',
    ];

    public function __construct(
        private string $mask = self::DEFAULT_MASK,
        private int $maxDepth = self::DEFAULT_MAX_DEPTH,
        private int $maxString = self::DEFAULT_MAX_STRING,
    ) {
    }

    /** 追加自定义敏感键 */
    public function addSensitiveKeys(string ...$keys): self
    {
        foreach ($keys as $key) {
            $key = strtolower(trim($key));
            if ($key !== '' && !in_array($key, $this->sensitiveKeys, true)) {
                $this->sensitiveKeys[] = $key;
            }
        }

        return $this;
    }

    /** @return list<string> */
    public function getSensitiveKeys(): array
    {
        return $this->sensitiveKeys;
    }

    public function setMask(string $mask): self
    {
        $this->mask = $mask;

        return $this;
    }

    public function setMaxDepth(int $depth): self
    {
        $this->maxDepth = max(1, $depth);

        return $this;
    }

    /** 判断键名是否敏感 */
    public function isSensitiveKey(string $key): bool
    {
        $normalized = self::normalizeKey($key);
        $tokens = explode('_', $normalized);

        // 整键命中，或敏感词作为「连续词元」出现在键名中（如 x_api_key 命中 api_key、
        // trace_token 命中 token）。按词元比对而非子串，避免把 author / design / assign
        // 这类普通字段误判为 auth / sign 而脱敏。
        foreach ($this->sensitiveKeys as $needle) {
            if ($needle === $normalized || self::containsSequence($tokens, explode('_', $needle))) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $tokens @param list<string> $needle */
    private static function containsSequence(#[\SensitiveParameter] array $tokens, array $needle): bool
    {
        $span = count($needle);
        if ($span < 1 || $span > count($tokens)) {
            return false;
        }

        $limit = count($tokens) - $span;
        for ($i = 0; $i <= $limit; $i++) {
            if (array_slice($tokens, $i, $span) === $needle) {
                return true;
            }
        }

        return false;
    }

    /** 键名归一化：拆分驼峰、统一分隔符为下划线、转小写 */
    private static function normalizeKey(string $key): string
    {
        $split = (string) preg_replace('/(?<!^)(?=[A-Z])/', '_', $key);

        return strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $split));
    }

    /** 脱敏整个上下文数组 */
    public function sanitize(array $context): array
    {
        return $this->walk($context, 0);
    }

    /** 脱敏单个值（不含键名判断） */
    public function sanitizeValue(mixed $value): mixed
    {
        return $this->normalize($value, 0);
    }

    private function walk(array $input, int $depth): array
    {
        if ($depth >= $this->maxDepth) {
            return ['_truncated' => 'max depth reached'];
        }

        $output = [];
        foreach ($input as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $output[$key] = $this->mask;
                continue;
            }

            $output[$key] = is_array($value)
                ? $this->walk($value, $depth + 1)
                : $this->normalize($value, $depth + 1);
        }

        return $output;
    }

    private function normalize(mixed $value, int $depth): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value) => $value,
            is_string($value) => $this->truncate($value),
            is_array($value) => $this->walk($value, $depth),
            $value instanceof UnitEnum => $value::class . '::' . $value->name,
            $value instanceof Throwable => sprintf(
                '%s: %s',
                $value::class,
                $this->truncate($value->getMessage())
            ),
            is_object($value) => $value::class . '#' . spl_object_id($value),
            is_resource($value) => 'resource(' . get_resource_type($value) . ')',
            default => gettype($value),
        };
    }

    private function truncate(string $value): string
    {
        if (mb_strlen($value) <= $this->maxString) {
            return $value;
        }

        return mb_substr($value, 0, $this->maxString) . '…(+' . (mb_strlen($value) - $this->maxString) . ')';
    }
}
