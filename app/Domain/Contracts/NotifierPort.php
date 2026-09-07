<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

/**
 * Port for external notifier POST https://util.devi.tools/api/v1/notify
 * per contracts/external-services.md:18
 */
interface NotifierPort
{
    public function notify(NotifyRequest $request): NotifyResult;
}
