<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ContractualBillingEntitlementReceivableSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_role_has_narrow_link_privileges_and_no_helper_execute(): void
    {
        $role = (string) getenv('ACCOUNTING_RUNTIME_DB_ROLE');

        foreach (['SELECT', 'INSERT', 'UPDATE'] as $privilege) {
            self::assertTrue((bool) DB::selectOne(
                'SELECT has_table_privilege(?, ?, ?) allowed',
                [$role, 'public.entitlement_receivable_links', $privilege],
            )->allowed);
        }

        self::assertFalse((bool) DB::selectOne(
            'SELECT has_table_privilege(?, ?, ?) allowed',
            [$role, 'public.entitlement_receivable_links', 'DELETE'],
        )->allowed);

        foreach ([
            'public.enforce_entitlement_receivable_link_history()',
            'public.guard_entitlement_linked_receivable_cancellation()',
            'public.guard_linked_entitlement_reversal()',
            'public.validate_entitlement_receivable_link_state(text,text,text)',
            'public.check_entitlement_receivable_link_final_state()',
        ] as $function) {
            self::assertFalse((bool) DB::selectOne(
                'SELECT has_function_privilege(?, ?, ?) allowed',
                [$role, $function, 'EXECUTE'],
            )->allowed, "Runtime role can execute protected function [{$function}].");
        }

        $owner = DB::selectOne(<<<'SQL'
            SELECT pg_catalog.pg_get_userbyid(relowner) AS name
            FROM pg_catalog.pg_class
            WHERE oid = 'public.entitlement_receivable_links'::regclass
            SQL)->name;

        self::assertNotSame($role, $owner);
    }
}
