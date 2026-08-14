<?php

declare(strict_types=1);

namespace App\Modules\Customers\Exceptions;

use DomainException;

final class CustomerHasLiveDependenciesException extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            'لا يمكن أرشفة عميل لديه حجز أو عقد تشغيلي قائم.'
        );
    }
}
