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
use App\Domain\Contracts\AuthorizerPort;
use App\Domain\Contracts\NotifierPort;
use App\Domain\Contracts\TransactionManager;
use App\Infrastructure\External\AuthorizerHttpAdapter;
use App\Infrastructure\External\NotifierHttpAdapter;
use App\Infrastructure\Persistence\HyperfTransactionManager;

/*
 * This file is part of Hyperf.
 *
 * @see     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
return [
    AuthorizerPort::class => AuthorizerHttpAdapter::class,
    NotifierPort::class => NotifierHttpAdapter::class,
    TransactionManager::class => HyperfTransactionManager::class,
];
