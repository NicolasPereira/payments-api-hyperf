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
use App\Domain\Contracts\IdempotencyStore;
use App\Domain\Contracts\TransactionManager;
use App\Domain\Shared\Exception\NotFoundException;
use App\Domain\Transfer\Entity\Transfer;
use App\Domain\Transfer\Exception\AuthorizerDeniedException;
use App\Domain\Transfer\Exception\AuthorizerUnavailableException;
use App\Domain\Transfer\Exception\SelfTransferException;
use App\Domain\Transfer\ValueObject\TransferValue;
use App\Domain\Wallet\Exception\InsufficientBalanceException;
use App\Infrastructure\Persistence\NotificationOutboxRepository;
use App\Infrastructure\Persistence\TransferRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Infrastructure\Persistence\WalletRepository;
use Throwable;

final class ExecuteTransferUseCase
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly WalletRepository $wallets,
        private readonly TransferRepository $transfers,
        private readonly NotificationOutboxRepository $outbox,
        private readonly IdempotencyStore $idempotency,
        private readonly AuthorizerPort $authorizer,
        private readonly TransactionManager $transactionManager,
    ) {
    }

    /**
     * @param array{value:string, payer:int, payee:int} $input
     * @return array{transfer:?Transfer, notification:string, replay:bool}
     */
    public function execute(array $input, ?string $correlationId = null): array
    {
        $value = TransferValue::fromString((string) ($input['value'] ?? ''));
        $payerId = (int) ($input['payer'] ?? 0);
        $payeeId = (int) ($input['payee'] ?? 0);

        if ($payerId === $payeeId) {
            throw new SelfTransferException();
        }

        $payer = $this->users->findById($payerId);
        if ($payer === null) {
            throw new NotFoundException('Payer not found.', 'payer_not_found');
        }
        $payee = $this->users->findById($payeeId);
        if ($payee === null) {
            throw new NotFoundException('Payee not found.', 'payee_not_found');
        }

        // Entity guard: merchant payer + self-transfer (payee of any type allowed).
        Transfer::create($payerId, $payeeId, $value, $payer->type);

        $payerWallet = $this->wallets->findByUserId($payerId);
        if ($payerWallet === null) {
            throw new NotFoundException('Payer wallet not found.', 'payer_not_found');
        }
        if (! $payerWallet->canAfford($value->money())) {
            throw new InsufficientBalanceException();
        }

        $fingerprint = $this->idempotency->fingerprint($payerId, $payeeId, $value->amount());
        if (! $this->idempotency->reserve($fingerprint, '{"status":"in_progress"}')) {
            return $this->replay($fingerprint);
        }

        $auth = $this->authorizer->authorize($payerId, $payeeId, $value->amount(), $correlationId);
        if (! $auth->authorized) {
            throw $this->mapAuthorizationFailure($auth->reason);
        }

        $result = $this->transactionManager->transaction(function () use ($payerId, $payeeId, $value, $payer, $fingerprint, $correlationId) {
            $locked = $this->wallets->lockForUpdate([$payerId, $payeeId]);
            if (! isset($locked[$payerId]) || ! isset($locked[$payeeId])) {
                throw new NotFoundException('Wallet not found.', 'wallet_not_found');
            }
            if (! $locked[$payerId]->canAfford($value->money())) {
                throw new InsufficientBalanceException();
            }

            $locked[$payerId]->debit($value->money());
            $locked[$payeeId]->credit($value->money());
            $this->wallets->updateBalance($payerId, $locked[$payerId]->balance());
            $this->wallets->updateBalance($payeeId, $locked[$payeeId]->balance());

            $transfer = Transfer::create($payerId, $payeeId, $value, $payer->type, $fingerprint, $correlationId);
            $transfer = $transfer->markAuthorized()->markCompleted();
            $persisted = $this->transfers->create($transfer);

            $this->outbox->createPending($persisted->id, $payeeId, [
                'transfer_id' => $persisted->id,
                'payee_id' => $payeeId,
                'value' => $value->amount(),
            ]);

            return $persisted;
        });

        $this->idempotency->put($fingerprint, (string) json_encode([
            'transfer_id' => $result->id,
            'status' => 'completed',
        ]));

        return ['transfer' => $result, 'notification' => 'queued', 'replay' => false];
    }

    /**
     * @return array{transfer:?Transfer, notification:string, replay:bool}
     */
    private function replay(string $fingerprint): array
    {
        $stored = $this->idempotency->get($fingerprint);
        $data = is_string($stored) ? json_decode($stored, true) : null;
        if (is_array($data) && isset($data['transfer_id'])) {
            $transfer = $this->transfers->findById((int) $data['transfer_id']);

            return ['transfer' => $transfer, 'notification' => 'queued', 'replay' => true];
        }

        return ['transfer' => null, 'notification' => 'pending', 'replay' => true];
    }

    private function mapAuthorizationFailure(string $reason): Throwable
    {
        if (str_contains($reason, 'denied')) {
            return new AuthorizerDeniedException();
        }

        return new AuthorizerUnavailableException();
    }
}
