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

namespace App\Application\Notification;

use App\Domain\Contracts\NotifierPort;
use App\Domain\Contracts\NotifyRequest;
use App\Infrastructure\Persistence\NotificationOutboxRepository;
use Closure;
use Hyperf\Context\Context;
use Hyperf\DbConnection\Db;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerProvider;
use Psr\Log\LoggerInterface;
use ReflectionFunction;
use RuntimeException;
use Throwable;

/**
 * T053 ProcessNotificationOutboxUseCase
 * Claim pending, chama NotifierPort, registra last_response, incrementa attempts,
 * available_at backoff 10s/60s/300s, sent/pending/failed per data-model.md:94.
 * T056 correlação/tracing com correlation_id + OTEL spans notify.outbox.*.
 */
final class ProcessNotificationOutboxUseCase
{
    private const LEASE_SECONDS = 30;

    /** @var array<int,int> backoff segundos por attempt (1-indexed) */
    private const BACKOFF = [
        1 => 10,
        2 => 60,
        3 => 300,
    ];

    public function __construct(
        private readonly NotificationOutboxRepository $outboxRepository,
        private readonly NotifierPort $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Processa o próximo pendente elegível. Retorna row processada ou null se nenhum.
     *
     * @return null|array{outbox_id:int, transfer_id:int, status:string, attempts:int, http_status:null|int}
     */
    public function processNext(?string $correlationId = null): ?array
    {
        $correlationId = $correlationId !== null && $correlationId !== '' ? $correlationId : $this->resolveCorrelationId();

        return $this->withSpan('notification.outbox.process', $correlationId, function () use ($correlationId) {
            // Claim one pending → processing with lease 30s
            $claimed = $this->claimNextPending($correlationId);
            if ($claimed === null) {
                $this->logger->debug('outbox no pending to claim', ['correlation_id' => $correlationId]);
                return null;
            }

            $outboxId = (int) $claimed->id;
            $transferId = (int) $claimed->transfer_id;
            $payeeId = (int) $claimed->payee_id;
            $payloadRaw = $claimed->payload;
            $attemptsBefore = (int) $claimed->attempts;

            $payload = [];
            if (is_string($payloadRaw)) {
                $decoded = json_decode($payloadRaw, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            } elseif (is_array($payloadRaw)) {
                $payload = $payloadRaw;
            }

            // Se correlation do transfer diferente, preferir correlation do outbox/transfer? Usar correlationId passado.
            $transferCorrelation = $this->fetchTransferCorrelation($transferId) ?? $correlationId;

            $notifyRequest = new NotifyRequest($transferId, $payeeId, $transferCorrelation, $payload);

            $this->logger->info('outbox notifier call start', [
                'outbox_id' => $outboxId,
                'transfer_id' => $transferId,
                'payee_id' => $payeeId,
                'attempt' => $attemptsBefore + 1,
                'correlation_id' => $transferCorrelation,
            ]);

            $result = $this->withSpan('notification.outbox.notify', $transferCorrelation, function () use ($notifyRequest) {
                return $this->notifier->notify($notifyRequest);
            }, ['outbox_id' => $outboxId, 'transfer_id' => $transferId]);

            $now = date('Y-m-d H:i:s');
            $rawResponse = $result->rawResponse ?? '';
            // Truncate last_response to avoid storing huge payloads/secrets
            $lastResponse = mb_substr($rawResponse !== '' ? $rawResponse : ($result->errorMessage ?? ''), 0, 2000);
            if ($lastResponse === '') {
                $lastResponse = sprintf('HTTP %d', $result->httpStatus);
            }

            if ($result->isSent()) {
                Db::table('notification_outbox')->where('id', $outboxId)->update([
                    'status' => NotificationOutboxRepository::STATUS_SENT,
                    'last_response' => $lastResponse,
                    'sent_at' => $now,
                    'lease_until' => null,
                    'updated_at' => $now,
                ]);
                $this->logger->info('outbox sent', [
                    'outbox_id' => $outboxId,
                    'transfer_id' => $transferId,
                    'http_status' => $result->httpStatus,
                    'correlation_id' => $transferCorrelation,
                    'span.name' => 'notification.outbox.sent',
                ]);
                return [
                    'outbox_id' => $outboxId,
                    'transfer_id' => $transferId,
                    'status' => NotificationOutboxRepository::STATUS_SENT,
                    'attempts' => $attemptsBefore + 1, // increment logically for sent? Keep DB attempts as before? Spec: attempts increment on try; sent after increment? We'll keep attempts incremented on notify.
                    'http_status' => $result->httpStatus,
                ];
            }

            // Failed: increment attempts, decide pending vs failed
            $newAttempts = $attemptsBefore + 1;

            if ($newAttempts >= 3) {
                Db::table('notification_outbox')->where('id', $outboxId)->update([
                    'status' => NotificationOutboxRepository::STATUS_FAILED,
                    'attempts' => $newAttempts,
                    'last_response' => $lastResponse,
                    'available_at' => $now,
                    'lease_until' => null,
                    'updated_at' => $now,
                ]);
                $this->logger->warning('outbox failed after max retries', [
                    'outbox_id' => $outboxId,
                    'transfer_id' => $transferId,
                    'attempts' => $newAttempts,
                    'last_response' => $lastResponse,
                    'correlation_id' => $transferCorrelation,
                    'exception.type' => 'notifier_failed',
                ]);
                return [
                    'outbox_id' => $outboxId,
                    'transfer_id' => $transferId,
                    'status' => NotificationOutboxRepository::STATUS_FAILED,
                    'attempts' => $newAttempts,
                    'http_status' => $result->httpStatus,
                ];
            }

            $backoffSeconds = self::BACKOFF[$newAttempts] ?? 60;
            $availableAt = date('Y-m-d H:i:s', time() + $backoffSeconds);
            Db::table('notification_outbox')->where('id', $outboxId)->update([
                'status' => NotificationOutboxRepository::STATUS_PENDING,
                'attempts' => $newAttempts,
                'last_response' => $lastResponse,
                'available_at' => $availableAt,
                'lease_until' => null,
                'updated_at' => $now,
            ]);
            $this->logger->info('outbox pending retry scheduled', [
                'outbox_id' => $outboxId,
                'transfer_id' => $transferId,
                'attempts' => $newAttempts,
                'available_at' => $availableAt,
                'backoff_seconds' => $backoffSeconds,
                'correlation_id' => $transferCorrelation,
            ]);

            return [
                'outbox_id' => $outboxId,
                'transfer_id' => $transferId,
                'status' => NotificationOutboxRepository::STATUS_PENDING,
                'attempts' => $newAttempts,
                'http_status' => $result->httpStatus,
            ];
        }, ['correlation_id' => $correlationId]);
    }

    /**
     * Processa por ID explícito (usado em testes).
     *
     * @return array{outbox_id:int, status:string, attempts:int}
     */
    public function processById(int $outboxId, ?string $correlationId = null): array
    {
        $correlationId = $correlationId !== null && $correlationId !== '' ? $correlationId : $this->resolveCorrelationId();
        $row = Db::table('notification_outbox')->where('id', $outboxId)->first();
        if ($row === null) {
            throw new RuntimeException(sprintf('Outbox %d not found', $outboxId));
        }
        // Force claim if pending
        $now = date('Y-m-d H:i:s');
        $leaseUntil = date('Y-m-d H:i:s', time() + self::LEASE_SECONDS);
        // If already processing with valid lease, don't re-claim unless expired
        if ($row->status === NotificationOutboxRepository::STATUS_PROCESSING && $row->lease_until !== null && strtotime((string) $row->lease_until) > time()) {
            throw new RuntimeException(sprintf('Outbox %d already processing with valid lease', $outboxId));
        }
        // Claim
        Db::table('notification_outbox')->where('id', $outboxId)->update([
            'status' => NotificationOutboxRepository::STATUS_PROCESSING,
            'lease_until' => $leaseUntil,
            'updated_at' => $now,
        ]);
        // Now call processNext logic would double claim; instead directly notify using same path but avoid re-claim.
        // To reuse notify logic, set status processing and call notify part.
        // Simpler: reset to pending available now then call processNext which will claim it.
        Db::table('notification_outbox')->where('id', $outboxId)->update([
            'status' => NotificationOutboxRepository::STATUS_PENDING,
            'available_at' => $now,
            'lease_until' => null,
            'updated_at' => $now,
        ]);
        $result = $this->processNext($correlationId);
        if ($result === null) {
            throw new RuntimeException('processNext returned null for forced id');
        }
        return $result;
    }

    /**
     * Libera leases expiradas: processing → pending quando lease_until <= now.
     * Retorna quantidade liberada.
     */
    public function releaseExpiredLeases(): int
    {
        $now = date('Y-m-d H:i:s');
        $affected = Db::table('notification_outbox')
            ->where('status', NotificationOutboxRepository::STATUS_PROCESSING)
            ->where('lease_until', '<=', $now)
            ->update([
                'status' => NotificationOutboxRepository::STATUS_PENDING,
                'lease_until' => null,
                'updated_at' => $now,
            ]);
        if ($affected > 0) {
            $this->logger->info('outbox leases expired released', ['count' => $affected, 'correlation_id' => $this->resolveCorrelationId()]);
        }
        return (int) $affected;
    }

    /**
     * Claim próximo pending elegível atomically.
     */
    private function claimNextPending(string $correlationId): ?object
    {
        $now = date('Y-m-d H:i:s');
        $leaseUntil = date('Y-m-d H:i:s', time() + self::LEASE_SECONDS);

        // Selecione candidato sem lock primeiro (available_at <= now, pending, attempts <3)
        $candidate = Db::table('notification_outbox')
            ->where('status', NotificationOutboxRepository::STATUS_PENDING)
            ->where('available_at', '<=', $now)
            ->where('attempts', '<', 3)
            ->orderBy('available_at', 'asc')
            ->orderBy('id', 'asc')
            ->limit(1)
            ->first();

        if ($candidate === null) {
            return null;
        }

        $id = (int) $candidate->id;
        // Tentativa condicional para evitar race: só atualiza se ainda pending e available_at <= now
        $affected = Db::table('notification_outbox')
            ->where('id', $id)
            ->where('status', NotificationOutboxRepository::STATUS_PENDING)
            ->where('available_at', '<=', $now)
            ->update([
                'status' => NotificationOutboxRepository::STATUS_PROCESSING,
                'lease_until' => $leaseUntil,
                'updated_at' => $now,
            ]);

        if ($affected === 0) {
            // Concorrência: outro worker pegou primeiro
            $this->logger->debug('outbox claim race lost', ['outbox_id' => $id, 'correlation_id' => $correlationId]);
            return null;
        }

        $claimed = Db::table('notification_outbox')->where('id', $id)->first();
        if ($claimed !== null) {
            $this->logger->info('outbox claimed', ['outbox_id' => $id, 'lease_until' => $leaseUntil, 'correlation_id' => $correlationId]);
        }
        return $claimed !== null ? (object) $claimed : null;
    }

    private function fetchTransferCorrelation(int $transferId): ?string
    {
        try {
            $row = Db::table('transfers')->where('id', $transferId)->first();
            if ($row !== null && isset($row->correlation_id) && is_string($row->correlation_id) && $row->correlation_id !== '') {
                return $row->correlation_id;
            }
        } catch (Throwable) {
        }
        return null;
    }

    private function resolveCorrelationId(): string
    {
        try {
            $id = Context::get('correlation_id');
            if (is_string($id) && $id !== '') {
                return $id;
            }
        } catch (Throwable) {
        }
        try {
            $id = Context::get('X-Correlation-Id');
            if (is_string($id) && $id !== '') {
                return $id;
            }
        } catch (Throwable) {
        }
        $generated = bin2hex(random_bytes(8));
        try {
            Context::set('correlation_id', $generated);
        } catch (Throwable) {
        }
        return $generated;
    }

    /**
     * T056 OTEL helper similar to ExecuteTransferUseCase.
     *
     * @template T
     * @param callable():T|callable(string):T $callback
     * @return T
     */
    private function withSpan(string $spanName, string $correlationId, callable $callback, array $attributes = []): mixed
    {
        try {
            Context::set('correlation_id', $correlationId);
        } catch (Throwable) {
        }
        $start = microtime(true);
        $this->logger->debug('otel span start', ['span.name' => $spanName, 'correlation_id' => $correlationId] + $attributes);
        $span = null;
        $scope = null;
        if (class_exists(TracerProvider::class) || class_exists(Globals::class)) {
            try {
                if (class_exists(Globals::class) && method_exists(Globals::class, 'tracerProvider')) {
                    $tracer = Globals::tracerProvider()->getTracer('payments-api-hyperf');
                    $span = $tracer->spanBuilder($spanName)->startSpan();
                    $scope = $span->activate();
                    foreach ($attributes as $k => $v) {
                        $span->setAttribute((string) $k, (string) $v);
                    }
                    $span->setAttribute('correlation_id', $correlationId);
                }
            } catch (Throwable) {
                $span = null;
            }
        }
        try {
            $ref = new ReflectionFunction(Closure::fromCallable($callback));
            $params = $ref->getNumberOfParameters();
            $result = $params > 0 ? $callback($correlationId) : $callback();
            $durationMs = (int) ((microtime(true) - $start) * 1000);
            $this->logger->debug('otel span end', ['span.name' => $spanName, 'correlation_id' => $correlationId, 'duration_ms' => $durationMs, 'span.status' => 'OK']);
            if ($span !== null) {
                $span->setAttribute('duration_ms', $durationMs);
                $span->setStatus(StatusCode::STATUS_OK);
            }
            return $result;
        } catch (Throwable $e) {
            $durationMs = (int) ((microtime(true) - $start) * 1000);
            $this->logger->warning('otel span error', [
                'span.name' => $spanName,
                'correlation_id' => $correlationId,
                'duration_ms' => $durationMs,
                'exception.type' => $e::class,
                'exception.message' => $e->getMessage(),
                'span.status' => 'ERROR',
            ]);
            if ($span !== null) {
                $span->recordException($e);
                $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            }
            throw $e;
        } finally {
            if ($scope !== null) {
                try {
                    $scope->detach();
                } catch (Throwable) {
                }
            }
            if ($span !== null) {
                try {
                    $span->end();
                } catch (Throwable) {
                }
            }
        }
    }
}
