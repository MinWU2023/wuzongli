<?php
declare(strict_types=1);

namespace App\Core;

/**
 * An exception that maps directly to an HTTP response.
 */
class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $status = 500,
        string $message = '服务器开小差了',
        public readonly string $errorCode = 'server_error',
        public readonly mixed $details = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $status, $previous);
    }

    public static function notFound(string $message = '没有找到对应的内容'): self
    {
        return new self(404, $message, 'not_found');
    }

    public static function forbidden(string $message = '没有权限执行此操作'): self
    {
        return new self(403, $message, 'forbidden');
    }

    public static function validation(string $message, mixed $details = null): self
    {
        return new self(422, $message, 'validation_error', $details);
    }
}
