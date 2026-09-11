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

namespace App\Controller;

use App\Application\User\CreateUserUseCase;
use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\Exception\UnauthorizedException;
use App\Http\Requests\CreateUserRequest;
use App\Infrastructure\Http\ExceptionMapper;
use App\Infrastructure\Http\Middleware\CorrelationMiddleware;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HyperfResponseInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class UserController extends AbstractController
{
    public function __construct(
        ContainerInterface $container,
        RequestInterface $request,
        HyperfResponseInterface $response,
        private readonly CreateUserUseCase $createUser,
        private readonly CreateUserRequest $formRequest,
    ) {
        parent::__construct($container, $request, $response);
    }

    public function create(): ResponseInterface
    {
        $correlationId = CorrelationMiddleware::currentId();
        try {
            if (! $this->formRequest->authorize()) {
                throw new UnauthorizedException();
            }
            $data = $this->request->getParsedBody();
            if (! is_array($data)) {
                $data = json_decode((string) $this->request->getBody(), true) ?? [];
            }
            $validated = $this->formRequest->validate($data);

            $result = $this->createUser->execute($validated);

            $user = $result['user'];
            $wallet = $result['wallet'];
            $payload = [
                'id' => $user->id,
                'full_name' => $user->fullName->getValue(),
                'document_type' => $user->type->getDocumentType()->value,
                'document' => $user->document->getValue(),
                'email' => $user->email->getValue(),
                'type' => $user->type->value,
                'balance' => $wallet->balance()->amount(),
            ];

            return $this->response
                ->withStatus(201)
                ->withHeader('Content-Type', 'application/json')
                ->withBody(new SwooleStream((string) json_encode($payload)));
        } catch (DomainException $e) {
            return $this->jsonError($e, $correlationId);
        } catch (Throwable $e) {
            return $this->jsonError($e, $correlationId);
        }
    }

    private function jsonError(Throwable $e, ?string $correlationId): ResponseInterface
    {
        $mapped = ExceptionMapper::toResponse($e, $correlationId);
        $status = $mapped->getStatusCode();
        $body = (string) $mapped->getBody();

        return $this->response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new SwooleStream($body));
    }
}
