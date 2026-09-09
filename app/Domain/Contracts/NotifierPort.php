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

/**
 * Port for external notifier POST https://util.devi.tools/api/v1/notify
 * per contracts/external-services.md:18.
 */
interface NotifierPort
{
    public function notify(NotifyRequest $request): NotifyResult;
}
