<?php

declare(strict_types=1);

namespace App\Modules\Units\Exceptions;

use DomainException;

final class UnitHasLiveDependenciesException extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            'لا يمكن أرشفة وحدة مرتبطة بحجز أو عقد تشغيلي قائم.'
        );
    }
}
