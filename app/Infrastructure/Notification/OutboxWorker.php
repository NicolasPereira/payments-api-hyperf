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

namespace App\Infrastructure\Notification;

use App\Application\Notification\ProcessNotificationOutboxUseCase;
use Hyperf\Context\Context;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Throwable;

/**
 * T054 OutboxWorker — polling + lease_until 30s, retry ≤3
 * Lógica de worker desacoplada do Process para testabilidade.
 * T056 correlação/tracing com correlation_id e OTEL spans outbox.worker.*.
 */
final class OutboxWorker
{
    private const POLL_INTERVAL_SECONDS = 2;

    private const BATCH_SIZE = 10;

    public function __construct(
        private readonly ProcessNotificationOutboxUseCase $processor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Executa um ciclo: libera leases expiradas + processa lote pending.
     *
     * @return int total processados no ciclo
     */
    public function runOnce(?string $correlationId = null): int
    {
        $correlationId = $correlationId !== null && $correlationId !== '' ? $correlationId : $this->resolveCorrelationId();
        try {
            Context::set('correlation_id', $correlationId);
        } catch (Throwable) {
        }

        $this->logger->debug('otel span start', ['span.name' => 'outbox.worker.cycle', 'correlation_id' => $correlationId]);

        $released = 0;
        try {
            $released = $this->processor->releaseExpiredLeases();
        } catch (Throwable $e) {
            $this->logger->warning('outbox lease release failed', [
                'exception.type' => $e::class,
                'exception.message' => $e->getMessage(),
                'correlation_id' => $correlationId,
            ]);
        }

        $processed = 0;
        // Processa até BATCH_SIZE sequencialmente; cada processNext faz claim com lease 30s
        for ($i = 0; $i < self::BATCH_SIZE; ++$i) {
            try {
                $result = $this->processor->processNext($correlationId);
                if ($result === null) {
                    break;
                }
                ++$processed;
                $this->logger->info('outbox worker processed item', [
                    'outbox_id' => $result['outbox_id'],
                    'status' => $result['status'],
                    'attempts' => $result['attempts'],
                    'correlation_id' => $correlationId,
                    'span.name' => 'outbox.worker.item',
                ]);
            } catch (Throwable $e) {
                $this->logger->warning('outbox worker item failed', [
                    'exception.type' => $e::class,
                    'exception.message' => $e->getMessage(),
                    'correlation_id' => $correlationId,
                ]);
                // Continua para próximo item; não aborta ciclo
            }
        }

        $this->logger->debug('otel span end', [
            'span.name' => 'outbox.worker.cycle',
            'correlation_id' => $correlationId,
            'released' => $released,
            'processed' => $processed,
            'span.status' => 'OK',
        ]);

        if ($processed === 0 && $released === 0) {
            // Sem trabalho, interval poll
        }

        return $processed;
    }

    /**
     * Loop contínuo do worker (chamado pelo Process).
     * Dorme POLL_INTERVAL_SECONDS quando idle.
     */
    public function runLoop(): void
    {
        $this->logger->info('outbox worker loop started', ['poll_interval' => self::POLL_INTERVAL_SECONDS, 'lease_seconds' => 30]);
        while (true) {
            try {
                $correlationId = $this->resolveCorrelationId();
                $processed = $this->runOnce($correlationId);
                if ($processed === 0) {
                    // Idle backoff
                    $this->sleepSeconds(self::POLL_INTERVAL_SECONDS);
                }
            } catch (Throwable $e) {
                $this->logger->warning('outbox worker loop exception', [
                    'exception.type' => $e::class,
                    'exception.message' => $e->getMessage(),
                ]);
                $this->sleepSeconds(self::POLL_INTERVAL_SECONDS);
            }
        }
    }

    private function sleepSeconds(int $seconds): void
    {
        // Em Swoole/Hyperf coroutine, use sleep coroutine-friendly
        if (function_exists('\Swoole\Coroutine::sleep')) {
            try {
                Coroutine::sleep($seconds);
                return;
            } catch (Throwable) {
            }
        }
        sleep($seconds);
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
        $generated = bin2hex(random_bytes(8));
        try {
            Context::set('correlation_id', $generated);
        } catch (Throwable) {
        }
        // Também tenta gerar com prefix worker para rastreio
        return $generated;
    }
}
