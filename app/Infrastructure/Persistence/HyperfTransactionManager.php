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

namespace App\Infrastructure\Persistence;

use App\Domain\Contracts\TransactionManager;
use Hyperf\DbConnection\Db;

final class HyperfTransactionManager implements TransactionManager
{
    public function transaction(callable $work): mixed
    {
        return Db::transaction($work);
    }
}
