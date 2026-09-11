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

namespace App\Infrastructure\Http\Middleware;

use Hyperf\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CorrelationMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $id = $request->getHeaderLine('X-Correlation-Id');
        if ($id === '') {
            $id = bin2hex(random_bytes(16));
        }
        Context::set('correlation_id', $id);
        $response = $handler->handle($request->withAttribute('correlation_id', $id));

        return $response->withAddedHeader('X-Correlation-Id', $id);
    }

    public static function currentId(): ?string
    {
        return Context::get('correlation_id');
    }
}
