<?php

declare(strict_types=1);

namespace App\Modules\ContractConsideration\Models;

use Illuminate\Database\Eloquent\Model;

final class ContractConsiderationLot extends Model
{
    protected $table = 'contract_consideration_lots';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];
}
