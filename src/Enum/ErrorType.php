<?php

declare(strict_types=1);

namespace Kode\Exception\Enum;

use Psr\Log\LogLevel;

/**
 * 错误类型枚举
 *
 * 取代 v2 的 KodeException::TYPE_* 字符串常量，提供类型安全与行为聚合。
 */
enum ErrorType: string
{
    /** HTTP 协议层错误 */
    case Http = 'http';
    /** 业务逻辑错误 */
    case Business = 'business';
    /** 运行时错误（协程 / 进程 / 连接池） */
    case Runtime = 'runtime';
    /** 系统级错误（内存 / 磁盘 / 内核） */
    case System = 'system';

    /** 中文可读名称 */
    public function label(): string
    {
        return match ($this) {
            self::Http => 'HTTP 异常',
            self::Business => '业务异常',
            self::Runtime => '运行时异常',
            self::System => '系统异常',
        };
    }

    /** 默认 PSR-3 日志级别 */
    public function logLevel(): string
    {
        return match ($this) {
            self::Http => LogLevel::WARNING,
            self::Business => LogLevel::NOTICE,
            self::Runtime => LogLevel::ERROR,
            self::System => LogLevel::CRITICAL,
        };
    }

    /** 该类型默认是否可恢复 */
    public function isRecoverableByDefault(): bool
    {
        return match ($this) {
            self::Http, self::Business => true,
            self::Runtime, self::System => false,
        };
    }

    /** 默认 HTTP 状态码 */
    public function defaultHttpStatus(): int
    {
        return match ($this) {
            self::Http => 500,
            self::Business => 200,
            self::Runtime => 503,
            self::System => 500,
        };
    }

    /** 宽松解析：无法识别时回退到 System */
    public static function coerce(self|string|null $value, self $fallback = self::System): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value === null || $value === '') {
            return $fallback;
        }

        return self::tryFrom(strtolower($value)) ?? $fallback;
    }
}
