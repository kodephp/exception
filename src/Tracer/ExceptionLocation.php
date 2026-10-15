<?php

declare(strict_types=1);

namespace Kode\Exception\Tracer;

use JsonSerializable;
use Stringable;

/**
 * 异常定位结果
 *
 * v2 中与 FrameInfo 同处 ExceptionLocator.php，违反 PSR-4，v3 拆分为独立文件。
 */
final readonly class ExceptionLocation implements JsonSerializable, Stringable
{
    /** @param list<FrameInfo> $frames */
    public function __construct(
        public string $throwFile,
        public int $throwLine,
        public string $message,
        public string $class,
        public ?FrameInfo $projectFrame,
        public ?FrameInfo $externalFrame,
        public array $frames,
        public string $severity,
    ) {
    }

    /** 最贴近业务代码的文件 */
    public function getSourceFile(): string
    {
        return $this->projectFrame->file
            ?? $this->externalFrame->file
            ?? $this->throwFile;
    }

    /** 最贴近业务代码的行号 */
    public function getSourceLine(): int
    {
        return $this->projectFrame->line
            ?? $this->externalFrame->line
            ?? $this->throwLine;
    }

    /** 是否定位到了项目内代码 */
    public function isLocatedInProject(): bool
    {
        return $this->projectFrame !== null;
    }

    public function toArray(): array
    {
        return [
            'throw_file' => $this->throwFile,
            'throw_line' => $this->throwLine,
            'source_file' => $this->getSourceFile(),
            'source_line' => $this->getSourceLine(),
            'message' => $this->message,
            'class' => $this->class,
            'severity' => $this->severity,
            'project_frame' => $this->projectFrame?->toArray(),
            'external_frame' => $this->externalFrame?->toArray(),
            'frames' => array_map(static fn (FrameInfo $f): array => $f->toArray(), $this->frames),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return sprintf(
            '%s: %s in %s on line %d',
            $this->class,
            $this->message,
            $this->getSourceFile(),
            $this->getSourceLine()
        );
    }
}
