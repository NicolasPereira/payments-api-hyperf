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

use App\Domain\Contracts\NotifierPort;
use App\Domain\Contracts\NotifyResult;
use Hyperf\Guzzle\ClientFactory;
use Throwable;

final class NotifierHttpAdapter implements NotifierPort
{
    private const URL = 'https://util.devi.tools/api/v1/notify';

    public function __construct(private readonly ClientFactory $clientFactory)
    {
    }

    public function notify(int $payeeId, int $transferId, ?string $correlationId = null): NotifyResult
    {
        $client = $this->clientFactory->create(['timeout' => 3.0, 'verify' => true]);
        $headers = ['Content-Type' => 'application/json'];
        if ($correlationId !== null && $correlationId !== '') {
            $headers['X-Correlation-Id'] = $correlationId;
        }
        try {
            $response = $client->post(self::URL, [
                'headers' => $headers,
                'json' => ['payee_id' => $payeeId, 'transfer_id' => $transferId],
            ]);
            $status = $response->getStatusCode();
            if ($status === 204 || ($status >= 200 && $status < 300)) {
                return NotifyResult::sent((string) $status);
            }

            return NotifyResult::failed('notifier_http_' . $status);
        } catch (Throwable $e) {
            return NotifyResult::failed('notifier_error: ' . substr($e->getMessage(), 0, 200));
        }
    }
}
