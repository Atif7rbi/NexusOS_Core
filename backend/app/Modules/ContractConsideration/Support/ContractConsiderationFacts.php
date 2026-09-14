<?php

declare(strict_types=1);

namespace App\Modules\ContractConsideration\Support;

use App\Modules\ContractConsideration\Exceptions\ContractConsiderationValidationFailed;
use Illuminate\Support\Str;

final class ContractConsiderationFacts
{
    public const ADOPTION_BASIS = 'CLEAN_NO_PRIOR_SUPPORTED_SOURCES';

    public const SCOPE_VERSION = 'CONTRACT_CONSIDERATION_V1';

    public const ADOPTED = 'ADOPTED';

    public const GENESIS = 'GENESIS';

    public const UNPERFORMED_UNBILLED = 'UNPERFORMED_UNBILLED';

    public static function operation(array $input): string
    {
        $value = (string) ($input['coordination_adoption_operation_id'] ?? '');

        if (! Str::isUlid($value)) {
            throw new ContractConsiderationValidationFailed(
                'coordination_adoption_operation_id must be a caller-supplied ULID.',
            );
        }

        return $value;
    }

    public static function contractId(string $contractId): string
    {
        if (! Str::isUlid($contractId)) {
            throw new ContractConsiderationValidationFailed(
                'contract_id must be a ULID.',
            );
        }

        return $contractId;
    }
}
