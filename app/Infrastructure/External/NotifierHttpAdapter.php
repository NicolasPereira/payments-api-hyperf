<?php

declare(strict_types=1);

namespace App\Infrastructure\External;

use App\Domain\Contracts\NotifierPort;
use App\Domain\Contracts\NotifyRequest;
use App\Domain\Contracts\NotifyResult;
use GuzzleHttp\RequestOptions;
use Hyperf\Context\Context;
use Hyperf\Guzzle\ClientFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * HTTP adapter for POST https://util.devi.tools/api/v1/notify
 * Success observed as 204 per contracts/external-services.md:18.
 * Non-2xx is retryable, never reverts transfer.
 */
final class NotifierHttpAdapter implements NotifierPort
{
    private const ENDPOINT = 'https://util.devi.tools/api/v1/notify';

    private const TIMEOUT_SECONDS = 2.0;

    public function __construct(
        private readonly ClientFactory $clientFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notify(NotifyRequest $request): NotifyResult
    {
        $correlationId = $request->correlationId ?: $this->resolveCorrelationId();

        $client = $this->clientFactory->create([
            RequestOptions::TIMEOUT => self::TIMEOUT_SECONDS,
            RequestOptions::CONNECT_TIMEOUT => self::TIMEOUT_SECONDS,
            RequestOptions::VERIFY => true, // TLS ON
            RequestOptions::HEADERS => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Correlation-Id' => $correlationId,
            ],
        ]);

        $payload = $request->payload !== [] ? $request->payload : [
            'transfer_id' => $request->transferId,
            'payee_id' => $request->payeeId,
        ];

        $this->logger->info('notifier request', [
            'transfer_id' => $request->transferId,
            'payee_id' => $request->payeeId,
            'correlation_id' => $correlationId,
            'payload' => $payload,
        ]);

        try {
            $response = $client->post(self::ENDPOINT, [
                RequestOptions::JSON => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();

            $this->logger->debug('notifier response', [
                'status' => $statusCode,
                'body' => $body,
                'correlation_id' => $correlationId,
            ]);

            // Success is 204 per spec, but also accept any 2xx as sent
            if ($statusCode >= 200 && $statusCode < 300) {
                return NotifyResult::sent($statusCode, $body);
            }

            return NotifyResult::failed($statusCode, $body, sprintf('Notifier returned HTTP %d', $statusCode));
        } catch (Throwable $e) {
            $message = $e->getMessage();

            $this->logger->warning('notifier exception', [
                'exception.type' => $e::class,
                'exception.message' => $message,
                'correlation_id' => $correlationId,
                'transfer_id' => $request->transferId,
            ]);

            return NotifyResult::failed(0, '', 'Notifier exception: ' . $message);
        }
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

        return bin2hex(random_bytes(8));
    }
}
