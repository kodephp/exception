<?php

declare(strict_types=1);

namespace Kode\Exception\Util;

use Throwable;

/**
 * 异常追踪溯源分析器
 * 用于分析异常堆栈，找到问题根源
 */
class ExceptionTraceAnalyzer
{
    /** 查找原始异常来源 */
    public static function findOriginalSource(Throwable $exception): array
    {
        $trace = $exception->getTrace();
        $sourceFile = $exception->getFile();
        $sourceLine = $exception->getLine();

        $projectRoots = self::getProjectRoots();

        foreach ($trace as $index => $frame) {
            if (!isset($frame['file'])) {
                continue;
            }

            if (!self::isInProject($frame['file'], $projectRoots)) {
                continue;
            }

            if ($frame['file'] === $sourceFile && $frame['line'] === $sourceLine) {
                continue;
            }

            return [
                'file' => $frame['file'],
                'line' => $frame['line'],
                'function' => $frame['function'] ?? 'unknown',
                'class' => $frame['class'] ?? null,
                'type' => $frame['type'] ?? null,
                'args' => self::sanitizeArgs($frame['args'] ?? []),
                'depth' => $index,
            ];
        }

        return [
            'file' => $sourceFile,
            'line' => $sourceLine,
            'function' => 'main',
            'depth' => 0,
        ];
    }

    /** 格式化异常堆栈用于显示 */
    public static function formatTraceForDisplay(Throwable $exception, int $maxLength = 50): string
    {
        $lines = [];
        $trace = $exception->getTrace();

        $lines[] = sprintf(
            '%s: %s in %s on line %d',
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        );

        foreach ($trace as $index => $frame) {
            if ($index >= $maxLength) {
                $lines[] = sprintf('... and %d more frames', count($trace) - $maxLength);
                break;
            }

            $location = isset($frame['file'])
                ? sprintf('%s:%d', self::shortenPath($frame['file']), $frame['line'])
                : '[internal]';

            $call = self::formatCall($frame);

            $lines[] = sprintf('#%d %s %s', $index, $location, $call);
        }

        return implode("\n", $lines);
    }

    /** 格式化函数调用 */
    protected static function formatCall(array $frame): string
    {
        $call = '';

        if (isset($frame['class'])) {
            $call .= $frame['class'] . ($frame['type'] ?? '::');
        }

        $call .= $frame['function'] ?? 'unknown';
        $call .= '()';

        return $call;
    }

    /** 缩短路径显示 */
    protected static function shortenPath(string $path): string
    {
        $parts = explode(DIRECTORY_SEPARATOR, $path);
        $shortened = array_map(function ($part) {
            return strlen($part) > 12 ? substr($part, 0, 8) . '...' : $part;
        }, $parts);

        return implode(DIRECTORY_SEPARATOR, $shortened);
    }

    /** 清理参数（脱敏处理） */
    protected static function sanitizeArgs(array $args): array
    {
        return array_map(function ($arg) {
            if (is_object($arg)) {
                return get_class($arg) . '#' . spl_object_id($arg);
            }

            if (is_array($arg)) {
                return 'array[' . count($arg) . ']';
            }

            if (is_string($arg)) {
                return strlen($arg) > 50 ? substr($arg, 0, 47) . '...' : $arg;
            }

            return var_export($arg, true);
        }, $args);
    }

    /** 是否在项目目录内 */
    protected static function isInProject(string $file, array $projectRoots): bool
    {
        foreach ($projectRoots as $root) {
            if (str_starts_with($file, $root)) {
                return true;
            }
        }

        return false;
    }

    /** 获取项目根目录 */
    protected static function getProjectRoots(): array
    {
        $roots = [
            __DIR__ . '/../../',
            getcwd(),
        ];

        if (isset($_SERVER['DOCUMENT_ROOT'])) {
            $roots[] = $_SERVER['DOCUMENT_ROOT'];
        }

        return array_unique($roots);
    }
}