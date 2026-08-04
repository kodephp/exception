<?php

declare(strict_types=1);

namespace Kode\Exception\Tracer;

use Stringable;

/**
 * 堆栈帧信息
 *
 * v2 中该类与 ExceptionLocation 一并塞在 ExceptionLocator.php 内，违反 PSR-4，
 * 单独 use 时无法自动加载，v3 拆分为独立文件。
 */
final readonly class FrameInfo implements Stringable
{
    public function __construct(
        public string $file,
        public int $line,
        public string $function,
        public ?string $class = null,
        public ?string $type = null,
        public bool $isProjectSource = true,
    ) {
    }

    /** 从原始 debug_backtrace 帧构造 */
    public static function fromFrame(array $frame, bool $isProjectSource = true): self
    {
        return new self(
            file: (string) ($frame['file'] ?? 'unknown'),
            line: (int) ($frame['line'] ?? 0),
            function: (string) ($frame['function'] ?? 'unknown'),
            class: isset($frame['class']) ? (string) $frame['class'] : null,
            type: isset($frame['type']) ? (string) $frame['type'] : null,
            isProjectSource: $isProjectSource,
        );
    }

    public function getFullFunctionName(): string
    {
        if ($this->class !== null && $this->class !== '') {
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

    public function __toString(): string
    {
        return sprintf('%s (%s:%d)', $this->getFullFunctionName(), basename($this->file), $this->line);
    }
}
