<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Collections\Contracts\CollectionAuditRecorderInterface;
use RuntimeException;

final class ThrowingCollectionAuditRecorder implements CollectionAuditRecorderInterface
{
    public bool $wasCalled = false;

    public function record(
        string $tenantId,
        string $contractId,
        string $event,
        int|string $actorId,
        array $context = [],
    ): void {
        $this->wasCalled = true;

        throw new RuntimeException('Intentional collection audit failure.');
    }
}
