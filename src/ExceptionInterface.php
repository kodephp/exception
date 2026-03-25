<?php

declare(strict_types=1);

namespace Kode\Exception;

use Throwable;

/**
 * 异常接口
 * 所有自定义异常必须实现此接口
 */
interface ExceptionInterface extends Throwable
{
    /** 获取错误代码 */
    public function getErrorCode(): string;

    /** 获取错误上下文数据 */
    public function getContext(): array;

    /** 转换为数组格式 */
    public function toArray(): array;
}