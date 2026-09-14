<?php

declare(strict_types=1);

namespace App\Modules\ContractConsideration\Models;

use Illuminate\Database\Eloquent\Model;

final class ContractConsiderationPosition extends Model
{
    protected $table = 'contract_consideration_positions';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];
}
