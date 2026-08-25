<?php

namespace App\Modules\Shared\Services;

use App\Modules\Shared\Contracts\BusinessNumberGeneratorInterface;
use App\Modules\Shared\Exceptions\BusinessNumberGenerationException;
use Illuminate\Support\Facades\DB;
use Throwable;

class BusinessNumberGenerator implements BusinessNumberGeneratorInterface
{
    /**
     * @return array{
     *     number: string,
     *     year: int,
     *     sequence: int
     * }
     */
    public function generate(
        string $tenantId,
        string $prefix,
        int $year,
    ): array {
        try {
            return DB::transaction(fn (): array =>
                $this->generateWithinCurrentTransaction(
                    $tenantId,
                    $prefix,
                    $year,
                ), 3);
        } catch (BusinessNumberGenerationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BusinessNumberGenerationException(
                'Business number could not be generated.',
                previous: $exception,
            );
        }
    }

    public function generateWithinCurrentTransaction(
        string $tenantId,
        string $prefix,
        int $year,
    ): array {
        $normalizedPrefix = $this->validate($tenantId, $prefix, $year);

        if (DB::transactionLevel() < 1) {
            throw new BusinessNumberGenerationException(
                'Business number allocation requires an active transaction.'
            );
        }

        DB::table('business_number_sequences')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'prefix' => $normalizedPrefix,
            'year' => $year,
            'current_value' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = DB::select(
            <<<'SQL'
                UPDATE business_number_sequences
                SET current_value = current_value + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE tenant_id = ? AND prefix = ? AND year = ?
                RETURNING current_value
                SQL,
            [$tenantId, $normalizedPrefix, $year],
        );

        if (count($rows) !== 1) {
            throw new BusinessNumberGenerationException(
                'Business number sequence could not be allocated.'
            );
        }

        $sequenceText = (string) $rows[0]->current_value;

        if (! preg_match('/^[1-9][0-9]*$/', $sequenceText)) {
            throw new BusinessNumberGenerationException(
                'Business number sequence returned an invalid value.'
            );
        }

        $sequence = filter_var($sequenceText, FILTER_VALIDATE_INT);

        if ($sequence === false) {
            throw new BusinessNumberGenerationException(
                'Business number sequence exceeds the application integer range.'
            );
        }

        return [
            'number' => sprintf(
                '%s-%d-%s',
                $normalizedPrefix,
                $year,
                str_pad($sequenceText, 3, '0', STR_PAD_LEFT),
            ),
            'year' => $year,
            'sequence' => $sequence,
        ];
    }

    private function validate(
        string $tenantId,
        string $prefix,
        int $year,
    ): string {
        $normalizedPrefix = strtoupper(trim($prefix));

        if ($tenantId === '') {
            throw new BusinessNumberGenerationException(
                'Business number tenant is required.'
            );
        }

        if (! preg_match('/^[A-Z]{2,10}$/', $normalizedPrefix)) {
            throw new BusinessNumberGenerationException(
                'Business number prefix is invalid.'
            );
        }

        if ($year < 2000 || $year > 9999) {
            throw new BusinessNumberGenerationException(
                'Business number year is invalid.'
            );
        }

        return $normalizedPrefix;
    }
}
