<?php

declare(strict_types=1);

namespace Kode\Exception\Tracer;

use Kode\Exception\KodeException;

/**
 * 异常位置定位器
 * 精确找到异常发生的文件和行号
 */
class ExceptionLocator
{
    /** 源代码根目录 */
    protected array $sourceRoots = [];
    /** 跳过调试的类前缀 */
    protected array $skipPrefixes = [
        'Kode\\Exception\\',
        'Monolog\\',
        'Psr\\Log\\',
        'Symfony\\',
        'Laravel\\',
    ];

    public function __construct(array $sourceRoots = [])
    {
        $this->sourceRoots = $sourceRoots ?: $this->detectSourceRoots();
    }

    /** 检测源代码目录 */
    protected function detectSourceRoots(): array
    {
        $roots = [];

        if (defined('APP_ROOT')) {
            $roots[] = constant('APP_ROOT');
        }
        if (defined('SRC_ROOT')) {
            $roots[] = constant('SRC_ROOT');
        }
        if (isset($_SERVER['DOCUMENT_ROOT'])) {
            $roots[] = dirname($_SERVER['DOCUMENT_ROOT']);
        }
        $roots[] = getcwd();

        return array_filter(array_unique(array_map(function ($root) {
            return $root ? realpath((string)$root) : null;
        }, $roots)));
    }

    /** 定位异常根源 */
    public function locate(\Throwable $exception): ExceptionLocation
    {
        $trace = $exception->getTrace();
        $sourceFile = $exception->getFile();
        $sourceLine = $exception->getLine();

        $projectFrame = $this->findProjectFrame($trace);
        $externalFrame = $this->findExternalFrame($trace);
        $frames = $this->buildLocatedFrames($trace);

        return new ExceptionLocation(
            $sourceFile,
            $sourceLine,
            $exception->getMessage(),
            get_class($exception),
            $projectFrame,
            $externalFrame,
            $frames,
            $this->calculateSeverity($exception)
        );
    }

    /** 查找项目内的堆栈帧 */
    protected function findProjectFrame(array $trace): ?FrameInfo
    {
        foreach ($trace as $frame) {
            if (!isset($frame['file'])) {
                continue;
            }

            if ($this->isProjectSource($frame['file'])) {
                if (!$this->shouldSkipFrame($frame)) {
                    return new FrameInfo(
                        $frame['file'],
                        $frame['line'] ?? 0,
                        $frame['function'] ?? 'unknown',
                        $frame['class'] ?? null,
                        $frame['type'] ?? null
                    );
                }
            }
        }

        return null;
    }

    /** 查找外部库的堆栈帧 */
    protected function findExternalFrame(array $trace): ?FrameInfo
    {
        foreach ($trace as $frame) {
            if (!isset($frame['file'])) {
                continue;
            }

            if (!$this->isProjectSource($frame['file'])) {
                return new FrameInfo(
                    $frame['file'],
                    $frame['line'] ?? 0,
                    $frame['function'] ?? 'unknown',
                    $frame['class'] ?? null,
                    $frame['type'] ?? null
                );
            }
        }

        return null;
    }

    /** 构建定位后的堆栈帧列表 */
    protected function buildLocatedFrames(array $trace): array
    {
        $frames = [];
        $depth = 0;
        $maxDepth = 15;

        foreach ($trace as $frame) {
            if ($depth >= $maxDepth) {
                break;
            }

            $isProject = isset($frame['file']) && $this->isProjectSource($frame['file']);

            $frames[] = new FrameInfo(
                $frame['file'] ?? 'unknown',
                $frame['line'] ?? 0,
                $frame['function'] ?? 'unknown',
                $frame['class'] ?? null,
                $frame['type'] ?? null,
                $isProject
            );

            $depth++;
        }

        return $frames;
    }

    /** 判断是否为项目源代码 */
    protected function isProjectSource(string $file): bool
    {
        foreach ($this->sourceRoots as $root) {
            if (str_starts_with($file, (string)$root)) {
                return true;
            }
        }

        return false;
    }

    /** 判断是否应跳过该帧 */
    protected function shouldSkipFrame(array $frame): bool
    {
        if (!isset($frame['class'])) {
            return false;
        }

        foreach ($this->skipPrefixes as $prefix) {
            if (str_starts_with($frame['class'], $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** 计算异常严重程度 */
    protected function calculateSeverity(\Throwable $exception): string
    {
        if ($exception instanceof \Error) {
            return 'critical';
        }

        if ($exception instanceof \TypeError) {
            return 'critical';
        }

        if ($exception instanceof \ArgumentCountError) {
            return 'error';
        }

        if ($exception instanceof KodeException) {
            if ($exception->isHttp()) {
                $statusCode = $exception->getHttpStatusCode();
                if ($statusCode >= 500) {
                    return 'error';
                }
                if ($statusCode >= 400) {
                    return 'warning';
                }
                return 'info';
            }

            if ($exception->isRuntime()) {
                return $exception->isRecoverable() ? 'error' : 'critical';
            }

            if ($exception->isSystem()) {
                return 'critical';
            }
        }

        return 'error';
    }
}

/**
 * 堆栈帧信息
 */
class FrameInfo
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $function,
        public readonly ?string $class = null,
        public readonly ?string $type = null,
        public readonly bool $isProjectSource = true
    ) {}

    public function getFullFunctionName(): string
    {
        if ($this->class) {
            return $this->class . ($this->type ?? '::') . $this->function;
        }
        return $this->function;
    }

    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'function' => $this->function,
            'class' => $this->class,
            'type' => $this->type,
            'is_project_source' => $this->isProjectSource,
        ];
    }
}

/**
 * 异常位置结果
 */
class ExceptionLocation
{
    public function __construct(
        public readonly string $throwFile,
        public readonly int $throwLine,
        public readonly string $message,
        public readonly string $class,
        public readonly ?FrameInfo $projectFrame,
        public readonly ?FrameInfo $externalFrame,
        public readonly array $frames,
        public readonly string $severity
    ) {}

    public function getSourceFile(): string
    {
        if ($this->projectFrame !== null) {
            return $this->projectFrame->file;
        }
        if ($this->externalFrame !== null) {
            return $this->externalFrame->file;
        }
        return $this->throwFile;
    }

    public function getSourceLine(): int
    {
        if ($this->projectFrame !== null) {
            return $this->projectFrame->line;
        }
        if ($this->externalFrame !== null) {
            return $this->externalFrame->line;
        }
        return $this->throwLine;
    }

    public function toArray(): array
    {
        return [
            'throw_file' => $this->throwFile,
            'throw_line' => $this->throwLine,
            'message' => $this->message,
            'class' => $this->class,
            'severity' => $this->severity,
            'project_frame' => $this->projectFrame?->toArray(),
            'external_frame' => $this->externalFrame?->toArray(),
            'frames' => array_map(fn($f) => $f->toArray(), $this->frames),
        ];
    }

    public function __toString(): string
    {
        return sprintf(
            "%s: %s in %s on line %d",
            $this->class,
            $this->message,
            $this->getSourceFile(),
            $this->getSourceLine()
        );
    }
}