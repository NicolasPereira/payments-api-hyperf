<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\Infrastructure\Http;

use App\Domain\Shared\Exception\DomainException;
use Hyperf\HttpMessage\Server\Response as HttpResponse;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class ExceptionMapper
{
    public static function toStatus(Throwable $e): int
    {
        if ($e instanceof DomainException) {
            return $e->getHttpStatus();
        }

        return 500;
    }

    public static function toCode(Throwable $e): string
    {
        if ($e instanceof DomainException) {
            return $e->getBusinessCode();
        }

        return 'internal_error';
    }

    public static function toResponse(Throwable $e, ?string $correlationId = null): ResponseInterface
    {
        $status = self::toStatus($e);
        $body = (new ErrorResponse(self::toCode($e), $e->getMessage() !== '' ? $e->getMessage() : 'Unexpected error.', $correlationId))->toArray();

        return (new HttpResponse())->withStatus($status)->withAddedHeader('Content-Type', 'application/json')->withBody(new SwooleStream((string) json_encode($body)));
    }
}
