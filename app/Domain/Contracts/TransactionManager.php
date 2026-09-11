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

namespace App\Domain\Contracts;

interface TransactionManager
{
    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function transaction(callable $work): mixed;
}
