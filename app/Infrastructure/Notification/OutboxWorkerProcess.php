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

use Hyperf\Process\AbstractProcess;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * T055 OutboxWorkerProcess — Hyperf Process registration
 * Registrado em config/autoload/processes.php
 * Delega polling para OutboxWorker (lease 30s, retry ≤3, correlação/tracing).
 */
final class OutboxWorkerProcess extends AbstractProcess
{
    public string $name = 'outbox-worker';

    public int $nums = 1;

    public bool $redirectStdinStdout = false;

    public int $pipeType = SOCK_DGRAM;

    public bool $enableCoroutine = true;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
    }

    public function handle(): void
    {
        // Resolve worker via container to get DI (ProcessNotificationOutboxUseCase + logger)
        try {
            /** @var OutboxWorker $worker */
            $worker = $this->container->get(OutboxWorker::class);
            $worker->runLoop();
        } catch (Throwable $e) {
            // Log via stdout logger if container has it
            try {
                $logger = $this->container->get(LoggerInterface::class);
                $logger->error('outbox worker process fatal', [
                    'exception.type' => $e::class,
                    'exception.message' => $e->getMessage(),
                    'span.name' => 'outbox.worker.process.fatal',
                ]);
            } catch (Throwable) {
            }
            // Sleep to avoid busy loop crash
            sleep(5);
        }
    }

    public function isEnable($server): bool
    {
        // Habilitado por padrão; pode desabilitar via env OUTBOX_WORKER_ENABLED=false
        $enabled = $_ENV['OUTBOX_WORKER_ENABLED'] ?? $_SERVER['OUTBOX_WORKER_ENABLED'] ?? true;
        if (is_string($enabled)) {
            return ! in_array(strtolower($enabled), ['0', 'false', 'no', 'off'], true);
        }
        return (bool) $enabled;
    }
}
