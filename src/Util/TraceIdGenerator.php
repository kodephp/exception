<?php

declare(strict_types=1);

namespace Kode\Exception\Util;

use Kode\Exception\Support\IdGenerator;

/**
 * TraceId 生成器
 * 生成唯一的追踪 ID，用于日志关联和问题定位
 */
class TraceIdGenerator
{
    /** TraceId 前缀 */
    protected static ?string $prefix = null;
    /** 计数器 */
    protected static int $counter = 0;
    /** 上次时间戳 */
    protected static float $lastTime = 0;

    /** 生成 TraceId */
    public static function generate(): string
    {
        $prefix = self::$prefix ?? self::generatePrefix();

        return $prefix . '-' . IdGenerator::traceId();
    }

    /** 从异常生成 TraceId */
    public static function generateFromException(\Throwable $exception): string
    {
        $hash = md5(
            $exception->getFile() .
            $exception->getLine() .
            $exception->getMessage() .
            spl_object_hash($exception)
        );

        return sprintf(
            '%s-E%08x',
            self::$prefix ?? self::generatePrefix(),
            crc32($hash)
        );
    }

    /** 生成前缀 */
    protected static function generatePrefix(): string
    {
        if (isset($_SERVER['HOSTNAME'])) {
            return substr($_SERVER['HOSTNAME'], 0, 4);
        }

        return 'kode';
    }

    /** 设置前缀 */
    public static function setPrefix(string $prefix): void
    {
        self::$prefix = $prefix;
    }

    /** 重置计数器 */
    public static function reset(): void
    {
        self::$counter = 0;
        self::$lastTime = 0;
    }
}