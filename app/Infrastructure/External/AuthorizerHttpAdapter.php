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

namespace App\Infrastructure\External;

use App\Domain\Contracts\AuthorizerPort;
use App\Domain\Contracts\AuthorizerResult;
use Hyperf\Guzzle\ClientFactory;
use Throwable;

final class AuthorizerHttpAdapter implements AuthorizerPort
{
    private const URL = 'https://util.devi.tools/api/v2/authorize';

    public function __construct(private readonly ClientFactory $clientFactory)
    {
    }

    public function authorize(int $payer, int $payee, string $value, ?string $correlationId = null): AuthorizerResult
    {
        $client = $this->clientFactory->create(['timeout' => 3.0, 'verify' => true]);
        $options = [];
        if ($correlationId !== null && $correlationId !== '') {
            $options['headers'] = ['X-Correlation-Id' => $correlationId];
        }
        try {
            $response = $client->get(self::URL, $options);
            if ($response->getStatusCode() !== 200) {
                return AuthorizerResult::unavailable('authorizer_http_' . $response->getStatusCode());
            }
            $data = json_decode((string) $response->getBody(), true);
            if (! is_array($data)) {
                return AuthorizerResult::invalid('authorizer_malformed');
            }
            if (($data['status'] ?? null) !== 'success') {
                return AuthorizerResult::denied('authorizer_denied');
            }
            $auth = $data['data']['authorization'] ?? null;
            if ($auth === true) {
                return AuthorizerResult::authorized();
            }

            return AuthorizerResult::denied('authorizer_denied');
        } catch (Throwable $e) {
            return AuthorizerResult::unavailable('authorizer_unavailable');
        }
    }
}
