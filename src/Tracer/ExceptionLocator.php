<?php

declare(strict_types=1);

namespace Kode\Exception\Tracer;

use ArgumentCountError;
use Error;
use Kode\Exception\KodeException;
use Psr\Log\LogLevel;
use Throwable;
use TypeError;

/**
 * 异常位置定位器
 *
 * 在完整堆栈中区分「项目代码」与「第三方库代码」，精确指向业务侧出错点。
 */
final class ExceptionLocator
{
    /** 堆栈扫描最大深度 */
    public const int MAX_DEPTH = 15;

    /** @var list<string> */
    private array $sourceRoots;

    /** @var list<string> 视为框架内部、不作为定位结果的类前缀 */
    private array $skipPrefixes = [
        'Kode\\Exception\\',
        'Monolog\\',
        'Psr\\Log\\',
        'PHPUnit\\',
        'Symfony\\Component\\ErrorHandler\\',
    ];

    /** @param list<string> $sourceRoots */
    public function __construct(array $sourceRoots = [])
    {
        $this->sourceRoots = $sourceRoots !== [] ? $this->normalizeRoots($sourceRoots) : $this->detectSourceRoots();
    }

    /** @return list<string> */
    public function getSourceRoots(): array
    {
        return $this->sourceRoots;
    }

    public function addSkipPrefix(string $prefix): self
    {
        if (!in_array($prefix, $this->skipPrefixes, true)) {
            $this->skipPrefixes[] = $prefix;
        }

        return $this;
    }

    /** 定位异常根源 */
    public function locate(Throwable $exception): ExceptionLocation
    {
        $trace = $exception->getTrace();

        return new ExceptionLocation(
            throwFile: $exception->getFile(),
            throwLine: $exception->getLine(),
            message: $exception->getMessage(),
            class: $exception::class,
            projectFrame: $this->findProjectFrame($trace),
            externalFrame: $this->findExternalFrame($trace),
            frames: $this->buildLocatedFrames($trace),
            severity: $this->calculateSeverity($exception),
        );
    }

    private function findProjectFrame(array $trace): ?FrameInfo
    {
        foreach ($trace as $frame) {
            if (!isset($frame['file'])) {
                continue;
            }

            if ($this->isProjectSource((string) $frame['file']) && !$this->shouldSkipFrame($frame)) {
                return FrameInfo::fromFrame($frame, true);
            }
        }

        return null;
    }

    private function findExternalFrame(array $trace): ?FrameInfo
    {
        foreach ($trace as $frame) {
            if (isset($frame['file']) && !$this->isProjectSource((string) $frame['file'])) {
                return FrameInfo::fromFrame($frame, false);
            }
        }

        return null;
    }

    /** @return list<FrameInfo> */
    private function buildLocatedFrames(array $trace): array
    {
        $frames = [];

        foreach (array_slice($trace, 0, self::MAX_DEPTH) as $frame) {
            $frames[] = FrameInfo::fromFrame(
                $frame,
                isset($frame['file']) && $this->isProjectSource((string) $frame['file'])
            );
        }

        return $frames;
    }

    private function isProjectSource(string $file): bool
    {
        foreach ($this->sourceRoots as $root) {
            if (str_starts_with($file, $root)) {
                return true;
            }
        }

        return false;
    }

    private function shouldSkipFrame(array $frame): bool
    {
        if (!isset($frame['class'])) {
            return false;
        }

        foreach ($this->skipPrefixes as $prefix) {
            if (str_starts_with((string) $frame['class'], $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** 计算异常严重程度（PSR-3 级别） */
    private function calculateSeverity(Throwable $exception): string
    {
        if ($exception instanceof ArgumentCountError) {
            return LogLevel::ERROR;
        }

        if ($exception instanceof TypeError || $exception instanceof Error) {
            return LogLevel::CRITICAL;
        }

        if ($exception instanceof KodeException) {
            if ($exception->isHttp()) {
                $status = $exception->getHttpStatusCode();

                return match (true) {
                    $status >= 500 => LogLevel::ERROR,
                    $status >= 400 => LogLevel::WARNING,
                    default => LogLevel::INFO,
                };
            }

            if ($exception->isRuntime()) {
                return $exception->isRecoverable() ? LogLevel::ERROR : LogLevel::CRITICAL;
            }

            if ($exception->isSystem()) {
                return LogLevel::CRITICAL;
            }

            return LogLevel::NOTICE;
        }

        return LogLevel::ERROR;
    }

    /** @return list<string> */
    private function detectSourceRoots(): array
    {
        $roots = [];

        foreach (['APP_ROOT', 'SRC_ROOT', 'BASE_PATH'] as $constant) {
            if (defined($constant)) {
                $roots[] = (string) constant($constant);
            }
        }

        if (isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '') {
            $roots[] = dirname($_SERVER['DOCUMENT_ROOT']);
        }

        $cwd = getcwd();
        if ($cwd !== false) {
            $roots[] = $cwd;
        }

        return $this->normalizeRoots($roots);
    }

    /**
     * @param list<string> $roots
     * @return list<string>
     */
    private function normalizeRoots(array $roots): array
    {
        $resolved = [];

        foreach ($roots as $root) {
            if ($root === '') {
                continue;
            }

            $real = realpath($root);
            if ($real !== false && !in_array($real, $resolved, true)) {
                $resolved[] = $real;
            }
        }

        return $resolved;
    }
}
