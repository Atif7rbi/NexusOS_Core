<?php

declare(strict_types=1);

namespace App\Modules\Projects\Exceptions;

use DomainException;

final class FinalProjectCannotBeUpdatedException extends DomainException
{
    public function __construct()
    {
        parent::__construct('لا يمكن تعديل مشروع مكتمل أو ملغي.');
    }
}
