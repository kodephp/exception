<?php

declare(strict_types=1);

namespace Kode\Exception;

use Kode\Exception\Formatter\ResponseFormatterInterface;
use Kode\Exception\Formatter\UnifiedResponseFormatter;
use Kode\Exception\Logger\LoggerFactory;
use Kode\Exception\Tracer\DistributedTracer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * 统一入口类
 * 提供便捷的异常处理、日志记录、链路追踪一站式访问
 */
final class Kode
{
    /** 是否已初始化 */
    protected static bool $initialized = false;

    /**
     * 初始化异常处理系统
     *
     * @param bool $isProduction 是否生产模式
     * @param LoggerInterface|null $logger 日志记录器
     * @param ResponseFormatterInterface|null $formatter 响应格式化器
     * @param string $serviceName 服务名称
     * @return ExceptionManager
     */
    public static function init(
        bool $isProduction = false,
        ?LoggerInterface $logger = null,
        ?ResponseFormatterInterface $formatter = null,
        string $serviceName = 'kode-app'
    ): ExceptionManager {
        $manager = ExceptionManager::getInstance();

        if ($logger !== null) {
            $manager->setLogger($logger);
        }

        if ($formatter !== null) {
            $manager->setFormatter($formatter);
        }

        $manager->setProduction($isProduction);

        if ($manager->getTracer() === null) {
            $manager->createTracer($serviceName);
        }

        if (!self::$initialized) {
            $manager->register();
            self::$initialized = true;
        }

        return $manager;
    }

    /**
     * 快速抛出 HTTP 异常
     *
     * @param string $msg 错误消息
     * @param array $context 上下文
     * @param Throwable|null $previous 前一个异常
     * @throws KodeException
     */
    public static function bad(string $msg = '请求参数错误', array $context = [], ?Throwable $previous = null): never
    {
        throw KodeException::bad($msg, $context, $previous);
    }

    /**
     * 快速抛出未授权异常
     *
     * @param string $msg 错误消息
     * @param array $context 上下文
     * @param Throwable|null $previous 前一个异常
     * @throws KodeException
     */
    public static function auth(string $msg = '未授权', array $context = [], ?Throwable $previous = null): never
    {
        throw KodeException::auth($msg, $context, $previous);
    }

    /**
     * 快速抛出禁止访问异常
     *
     * @param string $msg 错误消息
     * @param array $context 上下文
     * @param Throwable|null $previous 前一个异常
     * @throws KodeException
     */
    public static function deny(string $msg = '禁止访问', array $context = [], ?Throwable $previous = null): never
    {
        throw KodeException::deny($msg, $context, $previous);
    }

    /**
     * 快速抛出资源不存在异常
     *
     * @param string $msg 错误消息
     * @param array $context 上下文
     * @param Throwable|null $previous 前一个异常
     * @throws KodeException
     */
    public static function notFound(string $msg = '资源不存在', array $context = [], ?Throwable $previous = null): never
    {
        throw KodeException::notFound($msg, $context, $previous);
    }

    /**
     * 快速抛出验证失败异常
     *
     * @param string $msg 错误消息
     * @param array $context 上下文
     * @param Throwable|null $previous 前一个异常
     * @throws KodeException
     */
    public static function invalid(string $msg = '验证失败', array $context = [], ?Throwable $previous = null): never
    {
        throw KodeException::invalid($msg, $context, $previous);
    }

    /**
     * 快速抛出服务器错误异常
     *
     * @param string $msg 错误消息
     * @param array $context 上下文
     * @param Throwable|null $previous 前一个异常
     * @throws KodeException
     */
    public static function error(string $msg = '服务器错误', array $context = [], ?Throwable $previous = null): never
    {
        throw KodeException::error($msg, $context, $previous);
    }

    /**
     * 快速抛出业务异常
     *
     * @param string $msg 错误消息
     * @param array $context 上下文
     * @param Throwable|null $previous 前一个异常
     * @throws KodeException
     */
    public static function param(string $msg, array $context = [], ?Throwable $previous = null): never
    {
        throw KodeException::param($msg, $context, $previous);
    }

    /**
     * 快速抛出实体不存在异常
     *
     * @param string $entity 实体名称
     * @param string $id 实体 ID
     * @param Throwable|null $previous 前一个异常
     * @throws KodeException
     */
    public static function missing(string $entity, string $id, ?Throwable $previous = null): never
    {
        throw KodeException::missing($entity, $id, $previous);
    }

    /**
     * 获取异常管理器
     *
     * @return ExceptionManager
     */
    public static function manager(): ExceptionManager
    {
        return ExceptionManager::getInstance();
    }

    /**
     * 获取链路追踪器
     *
     * @return DistributedTracer|null
     */
    public static function tracer(): ?DistributedTracer
    {
        return ExceptionManager::getInstance()->getTracer();
    }

    /**
     * 获取日志记录器
     *
     * @return LoggerInterface
     */
    public static function logger(): LoggerInterface
    {
        return ExceptionManager::getInstance()->getLogger();
    }

    /**
     * 格式化异常
     *
     * @param Throwable $exception
     * @return array
     */
    public static function format(Throwable $exception): array
    {
        return ExceptionManager::getInstance()->format($exception);
    }

    /**
     * 渲染异常为 JSON
     *
     * @param Throwable $exception
     * @return string
     */
    public static function render(Throwable $exception): string
    {
        return ExceptionManager::getInstance()->render($exception);
    }

    /**
     * 记录日志（快捷方法）
     *
     * @param string $level 日志级别
     * @param string $message 日志消息
     * @param array $context 上下文
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        self::logger()->log($level, $message, $context);
    }

    /**
     * 记录 Debug 日志
     *
     * @param string $message
     * @param array $context
     */
    public static function debug(string $message, array $context = []): void
    {
        self::logger()->debug($message, $context);
    }

    /**
     * 记录 Info 日志
     *
     * @param string $message
     * @param array $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::logger()->info($message, $context);
    }

    /**
     * 记录 Warning 日志
     *
     * @param string $message
     * @param array $context
     */
    public static function warning(string $message, array $context = []): void
    {
        self::logger()->warning($message, $context);
    }

    /**
     * 记录 Error 日志
     *
     * @param string $message
     * @param array $context
     */
    public static function errorLog(string $message, array $context = []): void
    {
        self::logger()->error($message, $context);
    }

    /**
     * 记录 Critical 日志
     *
     * @param string $message
     * @param array $context
     */
    public static function critical(string $message, array $context = []): void
    {
        self::logger()->critical($message, $context);
    }
}
