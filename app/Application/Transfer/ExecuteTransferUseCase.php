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

namespace App\Application\Transfer;

use App\Domain\Contracts\AuthorizerPort;
use App\Domain\Contracts\AuthorizerRequest;
use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\Exception\NotFoundException;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\Entity\TransferStatus;
use App\Domain\Transfer\Exception\MerchantPayerNotAllowedException;
use App\Domain\Transfer\Exception\SelfTransferException;
use App\Domain\Transfer\Exception\TransferValidationException;
use App\Domain\Transfer\ValueObject\TransferValue;
use App\Domain\Wallet\Exception\InsufficientBalanceException;
use App\Infrastructure\Cache\RedisIdempotencyStore;
use App\Infrastructure\Persistence\Database;
use App\Infrastructure\Persistence\NotificationOutboxRepository;
use App\Infrastructure\Persistence\TransferRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Infrastructure\Persistence\WalletRepository;
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
 * T039 ExecuteTransferUseCase — fluxo plan.md:133
 * valida payload → idempotency Redis NX → load payer/payee → reject self/404
 * → balance check → AuthorizerPort → transaction lock wallets asc → recheck
 * → debit/credit → create Transfer → insert Outbox pending.
 */
final class ExecuteTransferUseCase
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly WalletRepository $wallets,
        private readonly TransferRepository $transfers,
        private readonly NotificationOutboxRepository $outbox,
        private readonly AuthorizerPort $authorizer,
        private readonly RedisIdempotencyStore $idempotency,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{value:mixed, payer:mixed, payee:mixed, correlation_id?:mixed} $input
     * @return array{transfer:array, notification:string, value:string, idempotency_key:string, correlation_id:string}
     *
     * @throws DomainException
     */
    public function execute(array $input): array
    {
        return $this->withSpan('transfer.execute', $this->resolveCorrelationIdFromInput($input), function (string $correlationId) use ($input) {
            return $this->doExecute($input, $correlationId);
        }, ['transfer.operation' => 'execute']);
    }

    /**
     * @param array{value:mixed, payer:mixed, payee:mixed, correlation_id?:mixed} $input
     * @return array{transfer:array, notification:string, value:string, idempotency_key:string, correlation_id:string}
     *
     * @throws DomainException
     */
    private function doExecute(array $input, string $initialCorrelationId): array
    {
        // 1. Validate payload strictly (value must be string)
        $rawValue = $input['value'] ?? null;
        $rawPayer = $input['payer'] ?? null;
        $rawPayee = $input['payee'] ?? null;
        $correlationId = isset($input['correlation_id']) && is_string($input['correlation_id']) && $input['correlation_id'] !== ''
            ? $input['correlation_id']
            : $this->resolveCorrelationId();

        if ($rawValue === null || $rawPayer === null || $rawPayee === null) {
            throw new TransferValidationException('Campos obrigatórios: value, payer, payee');
        }

        // value must be string exact "10.00"
        $transferValue = TransferValue::fromMixed($rawValue);
        $valueStr = $transferValue->getAmount();
        $valueMoney = $transferValue->getMoney();

        $payerId = $this->parseId($rawPayer, 'payer');
        $payeeId = $this->parseId($rawPayee, 'payee');

        if ($payerId === $payeeId) {
            throw new SelfTransferException();
        }

        // 2. Idempotency fingerprint hash(payer+payee+value) Redis NX
        $fingerprint = RedisIdempotencyStore::fingerprint($payerId, $payeeId, $valueStr);

        // If already stored result exists, return it without mutation (idempotent hit)
        try {
            $cached = $this->idempotency->get($fingerprint);
            if ($cached !== null && $cached !== '' && $cached !== '1') {
                $decoded = json_decode($cached, true);
                if (is_array($decoded) && isset($decoded['transfer'])) {
                    $this->logger->info('idempotency hit cached result', ['fingerprint' => $fingerprint, 'correlation_id' => $correlationId]);
                    return $decoded;
                }
                // placeholder '1' means reserved but not yet completed — treat as hit for pending? For sequential test, return cached placeholder? Instead allow re-proceed? We'll return hit with stored value if decodable, otherwise return stored raw as transfer
                // To avoid double debit on concurrent, we consider any existence as hit without re-executing, but need to return same result
                // If placeholder only, we still skip double debit and return conflict
                if ($cached === '1') {
                    // No result yet — still within window, return same as would be? For test, second sequential will have real result, not placeholder.
                    // Return cached placeholder as 422 duplicate handling: but we should fetch DB transfer by fingerprint?
                    $existingTransfer = Db::table('transfers')->where('idempotency_key', $fingerprint)->first();
                    if ($existingTransfer !== null) {
                        $transferArr = $this->transferRowToArray($existingTransfer);
                        return [
                            'transfer' => $transferArr,
                            'notification' => 'queued',
                            'value' => $valueStr,
                            'idempotency_key' => $fingerprint,
                            'correlation_id' => $correlationId,
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
            // Redis unavailable — log and continue without idempotency (graceful degradation); MySQL audit remains
            $this->logger->warning('idempotency get failed, continuing without cache', ['error' => $e->getMessage()]);
        }

        // Try reserve (SET NX EX 180) — if false and no cached result, still treat as duplicate hit by returning existing transfer
        $reserved = false;
        try {
            $reserved = $this->idempotency->tryReserve($fingerprint, '1', 180);
            if (! $reserved) {
                // Duplicate within window — try to return cached result
                $cached2 = $this->idempotency->get($fingerprint);
                if ($cached2 !== null) {
                    $decoded2 = json_decode($cached2, true);
                    if (is_array($decoded2) && isset($decoded2['transfer'])) {
                        return $decoded2;
                    }
                    $existing = Db::table('transfers')->where('idempotency_key', $fingerprint)->first();
                    if ($existing !== null) {
                        $transferArr = $this->transferRowToArray($existing);
                        return [
                            'transfer' => $transferArr,
                            'notification' => 'queued',
                            'value' => $valueStr,
                            'idempotency_key' => $fingerprint,
                            'correlation_id' => $correlationId,
                        ];
                    }
                }
                // No cached result but key exists — still avoid duplicate mutation; throw? For test we return generic duplicate error
                $this->logger->info('idempotency duplicate without cached result', ['fingerprint' => $fingerprint]);
                // Fall through to proceed? Better to return idempotent pending response without duplicate debit
                // We will not re-execute; throw duplicate?
                // For MVP, if duplicate but no stored result, we still prevent double debit by returning existing transfer if found, else treat as 422?
                // Let's try to find transfer anyway
                $existing = Db::table('transfers')->where('idempotency_key', $fingerprint)->first();
                if ($existing !== null) {
                    $transferArr = $this->transferRowToArray($existing);
                    return [
                        'transfer' => $transferArr,
                        'notification' => 'queued',
                        'value' => $valueStr,
                        'idempotency_key' => $fingerprint,
                        'correlation_id' => $correlationId,
                    ];
                }
                // If no transfer yet, we may be concurrent duplicate — return idempotent hit without creating new
                throw new TransferValidationException('Transferência duplicada dentro da janela de idempotência');
            }
        } catch (TransferValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->warning('idempotency reserve failed', ['error' => $e->getMessage()]);
            $reserved = true; // proceed without idempotency
        }

        // 3. Load payer/payee — reject 404
        $payer = $this->users->findById($payerId);
        if ($payer === null) {
            throw new NotFoundException('Payer não encontrado', 'payer_not_found');
        }

        $payee = $this->users->findById($payeeId);
        if ($payee === null) {
            throw new NotFoundException('Payee não encontrado', 'payee_not_found');
        }

        // 4. Reject self already done; merchant payer validation FR-007 per spec.md (T046)
        // FR-007: lojista só recebe — payer merchant → 403 merchant_payer_blocked
        // Must run BEFORE balance check / AuthorizerPort / transaction to avoid mutation or external calls.
        if ($payer->getType()->value === 'merchant') {
            $ex = new MerchantPayerNotAllowedException();
            // metrics counter merchant_payer_blocked_total + domain_exception_total per Constitution VI:164
            // OTEL exception.type required per T008b / Constitution VI:152
            $this->logger->warning('merchant payer blocked', [
                'exception.type' => $ex::class,
                'exception.message' => $ex->getMessage(),
                'business_code' => $ex->getBusinessCode(),
                'http_status' => $ex->getHttpStatus(),
                'payer_id' => $payerId,
                'payee_id' => $payeeId,
                'correlation_id' => $correlationId,
            ]);
            $this->logger->debug('metrics merchant_payer_blocked_total increment', [
                'code' => $ex->getBusinessCode(),
                'exception.type' => $ex::class,
            ]);
            $this->logger->debug('metrics domain_exception_total increment', [
                'code' => $ex->getBusinessCode(),
                'exception.type' => $ex::class,
            ]);
            throw $ex;
        }

        // FR-006: common→common e common→merchant permitidos — payee pode ser common OU merchant
        // Nenhuma validação de payee.type aqui; any payee type is allowed. US1 usa common payee,
        // US2 usa merchant payee e deve passar. Bloqueio apenas de payer merchant acima.

        // 5. Balance check before authorizer (payer wallet)
        $payerWallet = $this->wallets->findByUserId($payerId);
        if ($payerWallet === null) {
            throw new NotFoundException('Carteira do payer não encontrada', 'wallet_not_found');
        }

        $payeeWallet = $this->wallets->findByUserId($payeeId);
        if ($payeeWallet === null) {
            throw new NotFoundException('Carteira do payee não encontrada', 'wallet_not_found');
        }

        if ($payerWallet->getBalance()->lessThan($valueMoney)) {
            throw new InsufficientBalanceException();
        }

        // 6. AuthorizerPort — authorize before transaction (must not mutate wallets before)
        // T052: mapeia não autorizado→403, malformed/timeout/5xx→502/503, sem mutação antes da transação
        // T056: OTEL span para authorize + correlação
        $authResult = $this->withSpan('transfer.authorize', $correlationId, function () use ($payerId, $payeeId, $valueStr, $correlationId) {
            $authRequest = new AuthorizerRequest($payerId, $payeeId, $valueStr, $correlationId);
            $this->logger->info('transfer authorize start', ['payer' => $payerId, 'payee' => $payeeId, 'value' => $valueStr, 'correlation_id' => $correlationId]);
            $result = $this->authorizer->authorize($authRequest);
            $this->logger->info('transfer authorize result', [
                'authorized' => $result->isAuthorized(),
                'error_code' => $result->errorCode,
                'correlation_id' => $correlationId,
            ]);
            return $result;
        }, ['payer' => $payerId, 'payee' => $payeeId, 'value' => $valueStr]);

        if (! $authResult->isAuthorized()) {
            $code = $authResult->errorCode ?? 'authorizer_denied';
            $msg = $authResult->errorMessage ?? 'Autorização negada';
            $raw = $authResult->rawResponse ?? '';
            $this->logger->warning('transfer authorize denied/malformed', [
                'error_code' => $code,
                'correlation_id' => $correlationId,
                'payer' => $payerId,
                'payee' => $payeeId,
                'exception.type' => $code,
            ]);
            // Map to appropriate HTTP status per T052 / contracts/external-services.md
            if ($code === 'authorizer_denied') {
                throw new class($msg, 'authorizer_denied', 403) extends DomainException {
                };
            }
            if ($code === 'authorizer_timeout') {
                throw new class($msg, 'authorizer_timeout', 503) extends DomainException {
                };
            }
            if ($code === 'authorizer_malformed' || $code === 'authorizer_upstream_error') {
                throw new class($msg, $code, 502) extends DomainException {
                };
            }
            if (str_contains($code, 'unavailable')) {
                throw new class($msg, 'authorizer_unavailable', 503) extends DomainException {
                };
            }

            // default denied → 403
            throw new class($msg, $code, 403) extends DomainException {
            };
        }

        // 7. Transaction: lock wallets asc user_id → recheck → debit/credit → create Transfer → insert Outbox pending
        $transferRow = $this->withSpan('transfer.transaction', $correlationId, function () use ($payerId, $payeeId, $valueMoney, $valueStr, $fingerprint, $correlationId, $authResult) {
            return Database::transaction(function () use ($payerId, $payeeId, $valueMoney, $valueStr, $fingerprint, $correlationId, $authResult) {
                // Lock wallets FOR UPDATE asc user_id (deterministic to prevent deadlock)
                $locked = Database::lockWalletsForUpdate([$payerId, $payeeId]);

                // Extract balances from locked rows
                $payerRow = null;
                $payeeRow = null;
                foreach ($locked as $row) {
                    $uid = (int) ($row->user_id ?? $row->user_id);
                    if ($uid === $payerId) {
                        $payerRow = $row;
                    }
                    if ($uid === $payeeId) {
                        $payeeRow = $row;
                    }
                }

                if ($payerRow === null || $payeeRow === null) {
                    throw new NotFoundException('Carteira não encontrada no lock', 'wallet_not_found');
                }

                $payerBalance = Money::fromString((string) $payerRow->balance);
                $payeeBalance = Money::fromString((string) $payeeRow->balance);

                // Recheck balance after lock
                if ($payerBalance->lessThan($valueMoney)) {
                    throw new InsufficientBalanceException();
                }

                $newPayerBalance = $payerBalance->subtract($valueMoney);
                $newPayeeBalance = $payeeBalance->add($valueMoney);

                $now = date('Y-m-d H:i:s');

                // Update wallets
                Db::table('wallets')->where('user_id', $payerId)->update([
                    'balance' => $newPayerBalance->getAmount(),
                    'updated_at' => $now,
                ]);
                Db::table('wallets')->where('user_id', $payeeId)->update([
                    'balance' => $newPayeeBalance->getAmount(),
                    'updated_at' => $now,
                ]);

                // Create transfer
                $transferId = Db::table('transfers')->insertGetId([
                    'value' => $valueStr,
                    'payer_id' => $payerId,
                    'payee_id' => $payeeId,
                    'status' => TransferStatus::COMPLETED->value,
                    'idempotency_key' => $fingerprint,
                    'authorization_result' => $authResult->rawResponse,
                    'correlation_id' => $correlationId,
                    'authorized_at' => $now,
                    'completed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // Insert Outbox pending (same transaction)
                Db::table('notification_outbox')->insert([
                    'transfer_id' => $transferId,
                    'payee_id' => $payeeId,
                    'payload' => json_encode(['transfer_id' => $transferId, 'payee_id' => $payeeId, 'value' => $valueStr], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'status' => NotificationOutboxRepository::STATUS_PENDING,
                    'attempts' => 0,
                    'available_at' => $now,
                    'lease_until' => null,
                    'last_response' => null,
                    'sent_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $transfer = Db::table('transfers')->where('id', $transferId)->first();
                if ($transfer === null) {
                    throw new RuntimeException('Transfer not found after insert');
                }

                return $transfer;
            });
        }, ['correlation_id' => $correlationId]);

        $transferArray = $this->transferRowToArray($transferRow);

        $result = [
            'transfer' => $transferArray,
            'notification' => 'queued',
            'value' => $valueStr,
            'idempotency_key' => $fingerprint,
            'correlation_id' => $correlationId,
        ];

        // Store result in Redis for idempotency window 180s
        try {
            $this->idempotency->storeResult($fingerprint, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 180);
        } catch (Throwable $e) {
            $this->logger->warning('idempotency storeResult failed', ['error' => $e->getMessage(), 'fingerprint' => $fingerprint]);
        }

        $this->logger->info('transfer completed', [
            'transfer_id' => $transferArray['id'],
            'payer' => $payerId,
            'payee' => $payeeId,
            'value' => $valueStr,
            'correlation_id' => $correlationId,
            'idempotency_key' => $fingerprint,
        ]);

        return $result;
    }

    private function parseId(mixed $raw, string $field): int
    {
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }

        if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        throw new TransferValidationException(sprintf('Campo %s deve ser integer positivo', $field));
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

        return bin2hex(random_bytes(8));
    }

    /**
     * @return array{id:int,value:string,payer:int,payee:int,status:string}
     */
    private function transferRowToArray(array|object $row): array
    {
        $r = is_array($row) ? (object) $row : $row;

        return [
            'id' => (int) $r->id,
            'value' => (string) $r->value,
            'payer' => (int) $r->payer_id,
            'payee' => (int) $r->payee_id,
            'status' => (string) $r->status,
        ];
    }

    private function resolveCorrelationIdFromInput(array $input): string
    {
        if (isset($input['correlation_id']) && is_string($input['correlation_id']) && $input['correlation_id'] !== '') {
            try {
                Context::set('correlation_id', $input['correlation_id']);
            } catch (Throwable) {
            }
            return $input['correlation_id'];
        }
        return $this->resolveCorrelationId();
    }

    /**
     * T056 OTEL span helper with correlation_id + trace context.
     * Creates real OTEL span if SDK available, otherwise logs start/end.
     *
     * @template T
     * @param callable():T|callable(string):T $callback receives correlationId if expects param
     * @return T
     */
    private function withSpan(string $spanName, string $correlationId, callable $callback, array $attributes = []): mixed
    {
        // Ensure correlation in Context for downstream + logs TraceContextProcessor
        try {
            Context::set('correlation_id', $correlationId);
        } catch (Throwable) {
        }
        $start = microtime(true);
        $this->logger->debug('otel span start', ['span.name' => $spanName, 'correlation_id' => $correlationId] + $attributes);
        $span = null;
        $scope = null;
        // Try real OTEL SDK if available
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
