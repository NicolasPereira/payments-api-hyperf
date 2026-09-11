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
use App\Infrastructure\Http\ExceptionMapper;
use App\Infrastructure\Http\Middleware\CorrelationMiddleware;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class UserController extends AbstractController
{
    #[Inject]
    private CreateUserUseCase $createUser;

    public function create(): ResponseInterface
    {
        $correlationId = CorrelationMiddleware::currentId();
        try {
            $data = $this->request->getParsedBody();
            if (! is_array($data)) {
                $data = json_decode((string) $this->request->getBody(), true) ?? [];
            }
            foreach (['full_name', 'document', 'email', 'password', 'type'] as $field) {
                if (! isset($data[$field]) || $data[$field] === '') {
                    throw new class("Field {$field} is required.", 'validation_error', 422) extends DomainException {
                    };
                }
            }
            if (mb_strlen((string) $data['password']) < 8) {
                throw new class('Password must be at least 8 characters.', 'invalid_password', 422) extends DomainException {
                };
            }

            $result = $this->createUser->execute([
                'full_name' => (string) $data['full_name'],
                'document' => (string) $data['document'],
                'email' => (string) $data['email'],
                'password' => (string) $data['password'],
                'type' => (string) $data['type'],
            ]);

            $user = $result['user'];
            $wallet = $result['wallet'];
            $payload = [
                'id' => $user->id,
                'full_name' => $user->fullName,
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
