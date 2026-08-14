<?php

declare(strict_types=1);

namespace App\Modules\Projects\Exceptions;

use DomainException;

final class ProjectHasLiveDependenciesException extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            'لا يمكن أرشفة مشروع لديه حجوزات أو عقود تشغيلية قائمة.'
        );
    }
}
