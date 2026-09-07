<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Transfer\ExecuteTransferUseCase;
use App\Domain\Shared\Exception\DomainException;
use App\Infrastructure\Http\ExceptionMapper;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

class TransferController extends AbstractController
{
    #[Inject]
    private ExecuteTransferUseCase $useCase;

    #[Inject]
    private ExceptionMapper $exceptionMapper;

    public function transfer(): PsrResponseInterface
    {
        try {
            $data = $this->request->getParsedBody();
            if (!is_array($data)) {
                $raw = (string) $this->request->getBody();
                $decoded = json_decode($raw, true);
                $data = is_array($decoded) ? $decoded : [];
            }

            // Thin controller: validate value is string (reject number), delegate domain validation to UseCase/VO
            $value = $data['value'] ?? null;
            $payer = $data['payer'] ?? null;
            $payee = $data['payee'] ?? null;

            // Early string check for value per spec FR-008 (reject number 10.0)
            // UseCase will also enforce via TransferValue, but controller provides thin layer before delegation
            if ($value !== null && !is_string($value)) {
                throw new class('Value deve ser string decimal exata ex: "10.00"', 'transfer_validation', 422) extends DomainException {
                };
            }

            $missing = [];
            if ($value === null) {
                $missing[] = 'value';
            }
            if ($payer === null) {
                $missing[] = 'payer';
            }
            if ($payee === null) {
                $missing[] = 'payee';
            }
            if ($missing !== []) {
                throw new class('Campos obrigatórios ausentes: ' . implode(', ', $missing), 'transfer_validation', 422) extends DomainException {
                };
            }

            // Resolve correlation id from header/context
            $correlationId = $this->request->getHeaderLine('X-Correlation-Id');
            if ($correlationId === '') {
                try {
                    $correlationId = \Hyperf\Context\Context::get('correlation_id');
                    if (!is_string($correlationId)) {
                        $correlationId = '';
                    }
                } catch (\Throwable) {
                    $correlationId = '';
                }
            }

            $result = $this->useCase->execute([
                'value' => $value,
                'payer' => $payer,
                'payee' => $payee,
                'correlation_id' => $correlationId,
            ]);

            $transfer = $result['transfer'];
            $payload = [
                'id' => $transfer['id'],
                'value' => $transfer['value'],
                'payer' => $transfer['payer'],
                'payee' => $transfer['payee'],
                'status' => 'completed',
                'notification' => 'queued',
            ];

            // Also include outbox info per spec: status completed/queued
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // Use 201 per contracts/transfer.yaml (both 200/201 acceptable); use 201 for creation
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
