<?php

declare(strict_types=1);

namespace Kode\Exception\Tracer;

use Kode\Exception\ExceptionInterface;

/**
 * 错误链路追踪器
 * 完整记录异常传播链路，精确定位问题根源
 */
class ChainTracer
{
    /** 链路节点 */
    protected array $chain = [];
    /** 当前环境信息 */
    protected array $environment = [];
    /** 开始时间 */
    protected float $startTime;

    public function __construct()
    {
        $this->startTime = microtime(true);
        $this->environment = $this->detectEnvironment();
    }

    /** 检测当前运行环境 */
    public function detectEnvironment(): array
    {
        $env = [
            'php_version' => PHP_VERSION,
            'sapi' => php_sapi_name(),
            'pid' => getmypid(),
            'timestamp' => time(),
        ];

        if (function_exists('swoole_version')) {
            $env['runtime'] = 'swoole';
            $env['swoole_version'] = swoole_version();
            $env['coroutine_id'] = swooleCoroutine_getuid();
        } elseif (function_exists('Fiber::getCurrent')) {
            $env['runtime'] = 'fiber';
            $fiber = \Fiber::getCurrent();
            $env['coroutine_id'] = $fiber ? spl_object_id($fiber) : null;
        } else {
            $env['runtime'] = 'fpm';
        }

        if (function_exists('workerman_getpid')) {
            $env['runtime'] = 'workerman';
            $env['worker_id'] = workerman_getpid();
        }

        if (isset($_SERVER['REQUEST_URI'])) {
            $env['request_uri'] = $_SERVER['REQUEST_URI'];
            $env['request_method'] = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        }

        return $env;
    }

    /** 追踪异常链路 */
    public function trace(\Throwable $exception): array
    {
        $this->chain = [];
        $this->addExceptionToChain($exception);

        $previous = $exception->getPrevious();
        while ($previous !== null) {
            $this->addExceptionToChain($previous, true);
            $previous = $previous->getPrevious();
        }

        return $this->buildChainReport();
    }

    /** 添加异常到链路 */
    protected function addExceptionToChain(\Throwable $exception, bool $isPrevious = false): void
    {
        $timestamp = 'unknown';
        if ($exception instanceof ExceptionInterface) {
            try {
                $timestamp = $exception->getTimestamp()->format('Y-m-d H:i:s.u');
            } catch (\Throwable) {
                $timestamp = date('Y-m-d H:i:s');
            }
        } else {
            $timestamp = date('Y-m-d H:i:s');
        }

        $this->chain[] = [
            'type' => $isPrevious ? 'previous' : 'current',
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $this->sanitizeTrace($exception->getTrace()),
            'timestamp' => $timestamp,
        ];
    }

    /** 清理堆栈追踪 */
    protected function sanitizeTrace(array $trace): array
    {
        return array_map(function ($frame) {
            return [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $this->formatFunction($frame),
                'args' => isset($frame['args']) ? $this->sanitizeArgs($frame['args']) : [],
            ];
        }, array_slice($trace, 0, 20));
    }

    /** 格式化函数调用 */
    protected function formatFunction(array $frame): string
    {
        $result = '';

        if (isset($frame['class'])) {
            $result .= $frame['class'] . ($frame['type'] ?? '::');
        }

        $result .= ($frame['function'] ?? 'unknown') . '()';

        return $result;
    }

    /** 清理参数 */
    protected function sanitizeArgs(array $args): array
    {
        return array_map(function ($arg) {
            if (is_object($arg)) {
                return get_class($arg) . '#' . spl_object_id($arg);
            }
            if (is_array($arg)) {
                return 'array[' . count($arg) . ']';
            }
            if (is_string($arg) && strlen($arg) > 50) {
                return substr($arg, 0, 47) . '...';
            }
            return var_export($arg, true);
        }, array_slice($args, 0, 5));
    }

    /** 构建链路报告 */
    public function buildChainReport(): array
    {
        if (empty($this->chain)) {
            return [];
        }

        $current = $this->chain[0];
        $source = $this->findSourceLocation($current['trace'] ?? []);

        return [
            'current' => $current,
            'source' => $source,
            'chain_length' => count($this->chain),
            'previous_count' => count($this->chain) - 1,
            'environment' => $this->environment,
            'execution_time' => microtime(true) - $this->startTime,
            'full_chain' => $this->chain,
        ];
    }

    /** 查找异常源头位置 */
    protected function findSourceLocation(array $trace): array
    {
        $projectRoots = $this->getProjectRoots();

        foreach ($trace as $frame) {
            if (!isset($frame['file'])) {
                continue;
            }

            if (!$this->isProjectFile($frame['file'], $projectRoots)) {
                continue;
            }

            return [
                'file' => $frame['file'],
                'line' => $frame['line'] ?? 0,
                'function' => $frame['function'] ?? 'unknown',
            ];
        }

        return [
            'file' => $this->chain[0]['file'] ?? 'unknown',
            'line' => $this->chain[0]['line'] ?? 0,
            'function' => 'main',
        ];
    }

    /** 判断是否为项目文件 */
    protected function isProjectFile(string $file, array $roots): bool
    {
        foreach ($roots as $root) {
            if (str_starts_with($file, $root)) {
                return true;
            }
        }
        return false;
    }

    /** 获取项目根目录 */
    protected function getProjectRoots(): array
    {
        $roots = [];
        if (defined('APP_ROOT')) {
            $roots[] = APP_ROOT;
        }
        if (defined('BASE_PATH')) {
            $roots[] = BASE_PATH;
        }
        if (isset($_SERVER['DOCUMENT_ROOT'])) {
            $roots[] = $_SERVER['DOCUMENT_ROOT'];
        }
        $roots[] = getcwd();
        $roots[] = __DIR__ . '/../../';

        return array_filter(array_unique($roots));
    }

    /** 获取当前环境 */
    public function getEnvironment(): array
    {
        return $this->environment;
    }

    /** 导出链路为字符串 */
    public function exportAsString(array $report): string
    {
        $lines = [];
        $lines[] = "===========================================";
        $lines[] = "异常链路追踪报告";
        $lines[] = "===========================================";
        $lines[] = "";

        if (isset($report['environment'])) {
            $lines[] = "【运行环境】";
            foreach ($report['environment'] as $key => $value) {
                $lines[] = "  {$key}: {$value}";
            }
            $lines[] = "";
        }

        $lines[] = "【源头位置】";
        if (isset($report['source'])) {
            $lines[] = sprintf(
                "  %s:%d in %s()",
                $report['source']['file'] ?? 'unknown',
                $report['source']['line'] ?? 0,
                $report['source']['function'] ?? 'unknown'
            );
        }
        $lines[] = "";

        $lines[] = "【异常链路】(共 {$report['chain_length']} 个)";
        foreach ($report['full_chain'] as $index => $item) {
            $prefix = $index === 0 ? '→' : '↳';
            $lines[] = sprintf(
                "  %s #%d %s: %s (%s:%d)",
                $prefix,
                $index,
                $item['class'],
                $item['message'],
                $item['file'],
                $item['line']
            );
        }

        $lines[] = "";
        $lines[] = sprintf("执行时间: %.4fms", $report['execution_time'] * 1000);
        $lines[] = "===========================================";

        return implode("\n", $lines);
    }
}