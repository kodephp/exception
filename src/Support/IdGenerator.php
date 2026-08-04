<?php

declare(strict_types=1);

namespace Kode\Exception\Support;

use Random\Engine\Secure;
use Random\Randomizer;

/**
 * 追踪标识生成器
 *
 * 全部改用 CSPRNG（\Random\Randomizer + Secure 引擎），
 * 取代 v2 的 mt_rand，避免可预测与高并发碰撞。
 * 生成结果兼容 W3C Trace Context：trace-id 32 位小写十六进制，span-id 16 位。
 */
final class IdGenerator
{
    /** W3C trace-id 长度（十六进制字符数） */
    public const int TRACE_ID_LENGTH = 32;
    /** W3C span-id 长度（十六进制字符数） */
    public const int SPAN_ID_LENGTH = 16;

    private static ?Randomizer $randomizer = null;

    /** 生成 32 位十六进制 trace-id */
    public static function traceId(): string
    {
        return bin2hex(self::randomizer()->getBytes(16));
    }

    /** 生成 16 位十六进制 span-id */
    public static function spanId(): string
    {
        return bin2hex(self::randomizer()->getBytes(8));
    }

    /** 生成 RFC 4122 v4 UUID */
    public static function uuid4(): string
    {
        $bytes = self::randomizer()->getBytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /** 生成服务实例 ID */
    public static function instanceId(string $prefix = 'i'): string
    {
        $host = gethostname();

        return sprintf(
            '%s-%s-%d',
            $prefix,
            $host !== false && $host !== '' ? $host : 'host',
            getmypid() ?: 0
        );
    }

    /** trace-id 合法性校验（32 位十六进制且非全零） */
    public static function isValidTraceId(string $traceId): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $traceId) === 1
            && $traceId !== str_repeat('0', self::TRACE_ID_LENGTH);
    }

    /** span-id 合法性校验（16 位十六进制且非全零） */
    public static function isValidSpanId(string $spanId): bool
    {
        return preg_match('/^[0-9a-f]{16}$/', $spanId) === 1
            && $spanId !== str_repeat('0', self::SPAN_ID_LENGTH);
    }

    private static function randomizer(): Randomizer
    {
        return self::$randomizer ??= new Randomizer(new Secure());
    }
}
