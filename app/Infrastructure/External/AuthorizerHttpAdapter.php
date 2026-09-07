<?php

declare(strict_types=1);

namespace App\Infrastructure\External;

use App\Domain\Contracts\AuthorizerPort;
use App\Domain\Contracts\AuthorizerRequest;
use App\Domain\Contracts\AuthorizerResult;
use GuzzleHttp\RequestOptions;
use Hyperf\Guzzle\ClientFactory;
use Hyperf\Context\Context;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * HTTP adapter for GET https://util.devi.tools/api/v2/authorize
 * TLS verify ON per contracts/external-services.md:31 and research Decision 6.
 * Maps {status:success, data:{authorization:true}} to authorized.
 */
final class AuthorizerHttpAdapter implements AuthorizerPort
{
    private const ENDPOINT = 'https://util.devi.tools/api/v2/authorize';

    private const TIMEOUT_SECONDS = 2.0;

    public function __construct(
        private readonly ClientFactory $clientFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function authorize(AuthorizerRequest $request): AuthorizerResult
    {
        $correlationId = $request->correlationId ?: $this->resolveCorrelationId();

        $client = $this->clientFactory->create([
            RequestOptions::TIMEOUT => self::TIMEOUT_SECONDS,
            RequestOptions::CONNECT_TIMEOUT => self::TIMEOUT_SECONDS,
            RequestOptions::VERIFY => true, // TLS ON - never disable per spec
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'X-Correlation-Id' => $correlationId,
            ],
        ]);

        $this->logger->info('authorizer request', [
            'payer' => $request->payerId,
            'payee' => $request->payeeId,
            'value' => $request->value,
            'correlation_id' => $correlationId,
        ]);

        try {
            $response = $client->get(self::ENDPOINT);
            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();

            $this->logger->debug('authorizer response', [
                'status' => $statusCode,
                'body' => $body,
                'correlation_id' => $correlationId,
            ]);

            if ($statusCode !== 200) {
                return AuthorizerResult::failed($body, 'authorizer_upstream_error', sprintf('Authorizer returned HTTP %d', $statusCode));
            }

            $decoded = json_decode($body, true);
            if (! is_array($decoded)) {
                return AuthorizerResult::failed($body, 'authorizer_malformed', 'Malformed authorizer response: invalid JSON');
            }

            // Expected: {status:"success", data:{authorization:true}}
            $status = $decoded['status'] ?? null;
            $data = $decoded['data'] ?? null;
            $authorization = is_array($data) ? ($data['authorization'] ?? null) : null;

            if ($status === 'success' && $authorization === true) {
                return AuthorizerResult::authorized($body);
            }

            // Any other payload is treated as denied (including false, missing, etc.)
            // But malformed vs denied distinction: if structure is missing, treat as malformed (502)
            if (! isset($decoded['status']) || ! isset($decoded['data'])) {
                return AuthorizerResult::failed($body, 'authorizer_malformed', 'Malformed authorizer response: missing status/data');
            }

            return AuthorizerResult::denied($body, 'Authorizer denied authorization');
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $isTimeout = str_contains(strtolower($message), 'timeout') || str_contains(strtolower($message), 'timed out') || $e::class === \GuzzleHttp\Exception\ConnectException::class;

            $this->logger->warning('authorizer exception', [
                'exception.type' => $e::class,
                'exception.message' => $message,
                'correlation_id' => $correlationId,
            ]);

            if ($isTimeout) {
                return AuthorizerResult::failed('', 'authorizer_timeout', 'Authorizer timed out');
            }

            // Network/5xx or TLS errors
            return AuthorizerResult::failed('', 'authorizer_unavailable', 'Authorizer unavailable: ' . $message);
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
