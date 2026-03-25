<?php

declare(strict_types=1);

namespace Kode\Exception\Util;

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
        $timestamp = microtime(true);
        $pid = getmypid();

        if ($timestamp === self::$lastTime) {
            self::$counter++;
        } else {
            self::$counter = 0;
            self::$lastTime = $timestamp;
        }

        $prefix = self::$prefix ?? self::generatePrefix();

        return sprintf(
            '%s-%08x-%04x-%04x-%012x',
            $prefix,
            (int) $timestamp,
            $pid & 0xFFFF,
            (self::$counter & 0x3FFF) | 0x4000,
            self::generateRandom()
        );
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

    /** 生成随机数 */
    protected static function generateRandom(): int
    {
        return mt_rand(0, 0xFFFFFFFFFFFF);
    }
}