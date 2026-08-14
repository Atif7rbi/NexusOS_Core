<?php

declare(strict_types=1);

namespace App\Modules\Projects\Exceptions;

use DomainException;

final class ProjectHasOperationalFootprintException extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            'لا يمكن إعادة المشروع إلى مسودة لوجود حجوزات أو عقود تشغيلية قائمة.'
        );
    }
}
