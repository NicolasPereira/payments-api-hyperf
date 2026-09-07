<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\User\CreateUserUseCase;
use App\Domain\Shared\Exception\DomainException;
use App\Infrastructure\Http\ExceptionMapper;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

class UserController extends AbstractController
{
    #[Inject]
    private CreateUserUseCase $createUserUseCase;

    #[Inject]
    private ExceptionMapper $exceptionMapper;

    public function create(): PsrResponseInterface
    {
        try {
            $data = $this->request->getParsedBody();
            if (!is_array($data)) {
                $raw = (string) $this->request->getBody();
                $data = json_decode($raw, true);
                if (!is_array($data)) {
                    $data = [];
                }
            }

            // Thin controller validation per Constitution I — delegate domain validation to UseCase/VOs
            $fullName = $data['full_name'] ?? null;
            $document = $data['document'] ?? null;
            $email = $data['email'] ?? null;
            $password = $data['password'] ?? null;
            $type = $data['type'] ?? null;

            $missing = [];
            foreach (['full_name' => $fullName, 'document' => $document, 'email' => $email, 'password' => $password, 'type' => $type] as $k => $v) {
                if ($v === null || $v === '') {
                    $missing[] = $k;
                }
            }
            if ($missing !== []) {
                throw new class('Campos obrigatórios ausentes: ' . implode(', ', $missing), 'validation_error', 422) extends DomainException {
                };
            }

            if (!is_string($password) || mb_strlen($password) < 8) {
                throw new class('Senha deve ter no mínimo 8 caracteres', 'invalid_password', 422) extends DomainException {
                };
            }

            $result = $this->createUserUseCase->execute([
                'full_name' => (string) $fullName,
                'document' => (string) $document,
                'email' => (string) $email,
                'password' => (string) $password,
                'type' => (string) $type,
            ]);

            $user = $result['user'];
            $wallet = $result['wallet'];

            $payload = [
                'id' => $user->getId(),
                'full_name' => $user->getFullName(),
                'document_type' => $user->getDocumentType()->value,
                'document' => $user->getDocument()->getValue(),
                'email' => $user->getEmail()->getValue(),
                'type' => $user->getType()->value,
                'balance' => $wallet->getBalance()->getAmount(),
            ];

            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $this->response
                ->withStatus(201)
                ->withHeader('Content-Type', 'application/json')
                ->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream($json !== false ? $json : '{}'));
        } catch (DomainException $e) {
            return $this->exceptionMapper->toResponse($e, $this->response);
        } catch (\Throwable $e) {
            return $this->exceptionMapper->toResponse($e, $this->response);
        }
    }
}
