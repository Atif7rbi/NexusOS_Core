<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\UnitHandover\Support\UnitHandoverRecoveryResolver;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class UnitHandoverApplicationContractTest extends TestCase
{
    public function test_public_actions_expose_no_skip_auth_or_force_escape_hatch(): void
    {
        foreach ([
            'app/Modules/UnitHandover/Actions/RecordUnitHandoverEvidence.php',
            'app/Modules/UnitHandover/Actions/CreateUnitHandoverAcceptance.php',
            'app/Modules/UnitHandover/Actions/ReverseUnitHandoverPerformanceSource.php',
        ] as $path) {
            $source = file_get_contents(base_path($path));

            self::assertIsString($source);
            self::assertStringNotContainsString(
                'skip_auth',
                $source,
                $path,
            );
            self::assertStringNotContainsString(
                'skipAuth',
                $source,
                $path,
            );
            self::assertStringNotContainsString(
                'force:',
                $source,
                $path,
            );
            self::assertStringNotContainsString(
                '$force',
                $source,
                $path,
            );
        }
    }

    public function test_actions_use_only_unit_handover_authorization_facade(): void
    {
        foreach ([
            'app/Modules/UnitHandover/Actions/RecordUnitHandoverEvidence.php',
            'app/Modules/UnitHandover/Actions/CreateUnitHandoverAcceptance.php',
            'app/Modules/UnitHandover/Actions/ReverseUnitHandoverPerformanceSource.php',
        ] as $path) {
            $source = file_get_contents(base_path($path));

            self::assertIsString($source);

            self::assertStringContainsString(
                'UnitHandoverAuthorization',
                $source,
                $path,
            );

            self::assertStringNotContainsString(
                'ReceivablesAuthorization',
                $source,
                $path,
            );

            self::assertStringNotContainsString(
                'TenantAdministratorAuthority',
                $source,
                $path,
            );

            self::assertStringNotContainsString(
                "DB::table('tenant_users')",
                $source,
                $path,
            );

            self::assertStringNotContainsString(
                "DB::table('users')",
                $source,
                $path,
            );

            self::assertStringNotContainsString(
                "DB::table('tenants')",
                $source,
                $path,
            );
        }
    }

    public function test_reversal_action_keeps_single_source_aware_correction_path(): void
    {
        $source = file_get_contents(base_path(
            'app/Modules/UnitHandover/Actions/ReverseUnitHandoverPerformanceSource.php',
        ));

        self::assertIsString($source);

        self::assertStringContainsString(
            'authorizeCorrection(',
            $source,
        );

        self::assertStringContainsString(
            'authorizeCorrectionTransactional(',
            $source,
        );

        self::assertStringContainsString(
            "DB::table('contracts')",
            $source,
        );

        self::assertStringContainsString(
            "DB::table('reservations')",
            $source,
        );

        self::assertStringContainsString(
            "DB::table('units')",
            $source,
        );

        self::assertStringContainsString(
            "DB::table('unit_handover_evidence')",
            $source,
        );

        self::assertStringContainsString(
            "DB::table('unit_handover_acceptances')",
            $source,
        );

        self::assertStringNotContainsString(
            'ReverseUnitHandoverAcceptance',
            $source,
        );

        self::assertStringNotContainsString(
            'ReverseUnitHandoverEvidence',
            $source,
        );
    }

    public function test_unknown_outcome_recovery_refuses_nested_transaction(): void
    {
        $caught = null;

        DB::beginTransaction();

        try {
            app(UnitHandoverRecoveryResolver::class)
                ->recoverEvidence(
                    '01AAAAAAAAAAAAAAAAAAAAAAAA',
                    [
                        'handover_evidence_operation_id' => '01BBBBBBBBBBBBBBBBBBBBBBBB',
                        'handover_evidence_reference' => 'HANDOVER/NESTED-RECOVERY',
                    ],
                );
        } catch (\LogicException $exception) {
            $caught = $exception;
        } finally {
            DB::rollBack();
        }

        self::assertInstanceOf(
            \LogicException::class,
            $caught,
        );

        self::assertSame(
            'Unit Handover recovery requires a fresh transaction.',
            $caught?->getMessage(),
        );
    }
}
