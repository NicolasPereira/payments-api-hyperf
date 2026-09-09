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
use App\Infrastructure\Http\Security\InputSanitizer;
use App\Infrastructure\Http\Security\RateLimiter;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class UserController extends AbstractController
{
    #[Inject]
    private CreateUserUseCase $createUserUseCase;

    #[Inject]
    private ExceptionMapper $exceptionMapper;

    #[Inject]
    private RateLimiter $rateLimiter;

    #[Inject]
    private LoggerInterface $logger;

    public function create(): PsrResponseInterface
    {
        try {
            // T062 Rate limiting per Constitution V: abuse-prone registration 60 req/min per IP
            $clientIp = $this->request->getServerParams()['remote_addr'] ?? $this->request->getHeaderLine('X-Forwarded-For') ?: 'unknown';
            $clientIp = is_string($clientIp) ? $clientIp : 'unknown';
            $rateKey = 'users:' . $clientIp;
            if (! $this->rateLimiter->allow($rateKey, 60, 60)) {
                $retryAfter = $this->rateLimiter->ttl($rateKey);
                throw new class('Muitas requisições, tente novamente em ' . $retryAfter . 's', 'rate_limited', 429) extends DomainException {
                };
            }

            // T062 Replay protection: detect duplicate X-Correlation-Id reuse within 60s window
            $correlationId = $this->request->getHeaderLine('X-Correlation-Id');
            if ($correlationId === '') {
                try {
                    $cid = Context::get('correlation_id');
                    $correlationId = is_string($cid) ? $cid : '';
                } catch (Throwable) {
                    $correlationId = '';
                }
            }
            if ($correlationId !== '') {
                // Use RateLimiter's Redis to store replay fingerprint (reuse allow with low limit)
                $replayKey = 'replay:users:' . hash('sha256', $correlationId);
                if (! $this->rateLimiter->allow($replayKey, 1, 60)) {
                    // Duplicate correlation_id within window — log without PII, reject as replay
                    $this->logger->warning('replay protection hit', ['correlation_id' => $correlationId, 'route' => '/users']);
                    throw new class('Requisição duplicada detectada (replay protection)', 'replay_detected', 422) extends DomainException {
                    };
                }
            }

            $data = $this->request->getParsedBody();
            if (! is_array($data)) {
                $raw = (string) $this->request->getBody();
                $data = json_decode($raw, true);
                if (! is_array($data)) {
                    $data = [];
                }
            }

            // T062 Input validation boundaries (Constitution V) — thin controller, no business logic, only limits
            // Log sanitized input without PII/secrets
            $this->logger->debug('user create request', InputSanitizer::forLog(['route' => '/users'] + $data));
            $boundaryErrors = InputSanitizer::validateUserInput($data);
            if ($boundaryErrors !== []) {
                $msg = 'Validação falhou: ' . implode('; ', array_map(fn ($k, $v) => $k . ' ' . $v, array_keys($boundaryErrors), $boundaryErrors));
                throw new class($msg, 'validation_error', 422) extends DomainException {
                };
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

            if (! is_string($password) || mb_strlen($password) < 8) {
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
                ->withBody(new SwooleStream($json !== false ? $json : '{}'));
        } catch (DomainException $e) {
            return $this->exceptionMapper->toResponse($e, $this->response);
        } catch (Throwable $e) {
            return $this->exceptionMapper->toResponse($e, $this->response);
        }
    }
}
