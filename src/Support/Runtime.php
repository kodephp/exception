<?php

declare(strict_types=1);

namespace Kode\Exception\Support;

use Fiber;

/**
 * 运行时环境探测
 *
 * v2 中散落在 ChainTracer / DistributedTracer / CoroutineExceptionHandler 的探测逻辑
 * 存在虚构函数（swooleCoroutine_getuid）与永假判断（function_exists('Fiber::getCurrent')），
 * 此处统一收敛为唯一可信来源。
 */
final class Runtime
{
    public const string FPM = 'fpm';
    public const string CLI = 'cli';
    public const string SWOOLE = 'swoole';
    public const string WORKERMAN = 'workerman';
    public const string ROADRUNNER = 'roadrunner';
    public const string FRANKENPHP = 'frankenphp';
    public const string FIBER = 'fiber';

    private static ?string $cachedName = null;

    /** 当前运行时名称 */
    public static function name(): string
    {
        return self::$cachedName ??= self::detect();
    }

    /** 清空探测缓存（测试或运行时切换后调用） */
    public static function reset(): void
    {
        self::$cachedName = null;
    }

    /** 是否处于协程上下文（Swoole 协程或 Fiber） */
    public static function inCoroutine(): bool
    {
        return self::swooleCoroutineId() !== null || self::fiberId() !== null;
    }

    /** 当前协程 ID，非协程环境返回 null */
    public static function coroutineId(): ?int
    {
        return self::swooleCoroutineId() ?? self::fiberId();
    }

    /** 协程上下文键：协程 ID 或 'main' */
    public static function contextKey(): string
    {
        $id = self::coroutineId();

        return $id === null ? 'main' : (string) $id;
    }

    /** 是否运行在常驻 Worker 模型中 */
    public static function isWorker(): bool
    {
        return in_array(
            self::name(),
            [self::SWOOLE, self::WORKERMAN, self::ROADRUNNER, self::FRANKENPHP],
            true
        ) || getenv('WORKER_MODE') !== false;
    }

    /** 是否为常驻内存运行（非 FPM / CLI 一次性脚本） */
    public static function isLongRunning(): bool
    {
        return self::isWorker();
    }

    /** Swoole 协程 ID，非 Swoole 协程返回 null */
    public static function swooleCoroutineId(): ?int
    {
        if (!class_exists('\Swoole\Coroutine', false)) {
            return null;
        }

        /** @var callable $getuid */
        $getuid = ['\Swoole\Coroutine', 'getuid'];
        if (!is_callable($getuid)) {
            return null;
        }

        $cid = $getuid();

        return is_int($cid) && $cid > 0 ? $cid : null;
    }

    /** 当前 Fiber 的对象 ID，不在 Fiber 中返回 null */
    public static function fiberId(): ?int
    {
        if (!class_exists(Fiber::class, false)) {
            return null;
        }

        $fiber = Fiber::getCurrent();

        return $fiber === null ? null : spl_object_id($fiber);
    }

    /** 运行时环境快照，用于链路报告 */
    public static function snapshot(): array
    {
        $snapshot = [
            'runtime' => self::name(),
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'pid' => getmypid() ?: 0,
            'memory_mb' => round(memory_get_usage(true) / 1048576, 2),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
        ];

        $coroutineId = self::coroutineId();
        if ($coroutineId !== null) {
            $snapshot['coroutine_id'] = $coroutineId;
        }

        if (isset($_SERVER['REQUEST_URI'])) {
            $snapshot['request_uri'] = (string) $_SERVER['REQUEST_URI'];
            $snapshot['request_method'] = (string) ($_SERVER['REQUEST_METHOD'] ?? 'CLI');
        }

        return $snapshot;
    }

    private static function detect(): string
    {
        // 仅当确实处于 Swoole 协程内（getuid > 0）才认定为 Swoole 运行时。
        // 不能仅凭 extension_loaded('swoole') 判定：开发机常加载扩展却运行普通 CLI 脚本。
        if (self::swooleCoroutineId() !== null) {
            return self::SWOOLE;
        }

        if (self::fiberId() !== null) {
            return self::FIBER;
        }

        if (class_exists('\Workerman\Worker', false)) {
            return self::WORKERMAN;
        }

        if (PHP_SAPI === 'frankenphp' || getenv('FRANKENPHP_CONFIG') !== false) {
            return self::FRANKENPHP;
        }

        if (getenv('RR_MODE') !== false || class_exists('\Spiral\RoadRunner\Worker', false)) {
            return self::ROADRUNNER;
        }

        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return self::CLI;
        }

        return self::FPM;
    }
}
