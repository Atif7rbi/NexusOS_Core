<?php

declare(strict_types=1);

namespace App\Modules\UnitHandover\Support;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Receivables\Exceptions\ReceivablesAccessDenied;
use App\Modules\Receivables\Support\ReceivablesAuthorization;
use App\Modules\Shared\Authorization\TenantAdministratorAuthority;
use App\Modules\UnitHandover\Exceptions\UnitHandoverAccessDenied;
use Illuminate\Support\Facades\DB;

final class UnitHandoverAuthorization
{
    public function __construct(
        private readonly ReceivablesAuthorization $receivablesAuthorization,
    ) {}

    public function authorizeEvidence(
        string $tenantId,
        User $actor,
    ): void {
        $this->authorizeWithoutActiveTenantRequirement(
            $tenantId,
            $actor,
            false,
        );
    }

    public function authorizeEvidenceTransactional(
        string $tenantId,
        User $actor,
    ): void {
        $this->authorizeWithoutActiveTenantRequirement(
            $tenantId,
            $actor,
            true,
        );
    }

    public function authorizeAcceptance(
        string $tenantId,
        User $actor,
    ): void {
        try {
            $this->receivablesAuthorization
                ->authorizeTenantAdministrator(
                    $tenantId,
                    $actor,
                );
        } catch (ReceivablesAccessDenied $exception) {
            throw new UnitHandoverAccessDenied(
                'Actor is not authorized for Unit Handover.',
                previous: $exception,
            );
        }
    }

    public function authorizeAcceptanceTransactional(
        string $tenantId,
        User $actor,
    ): void {
        try {
            $this->receivablesAuthorization
                ->authorizeTransactionalTenantAdministrator(
                    $tenantId,
                    $actor,
                );
        } catch (ReceivablesAccessDenied $exception) {
            throw new UnitHandoverAccessDenied(
                'Actor is not authorized for Unit Handover.',
                previous: $exception,
            );
        }
    }

    public function authorizeCorrection(
        string $tenantId,
        User $actor,
    ): void {
        $this->authorizeWithoutActiveTenantRequirement(
            $tenantId,
            $actor,
            false,
        );
    }

    public function authorizeCorrectionTransactional(
        string $tenantId,
        User $actor,
    ): void {
        $this->authorizeWithoutActiveTenantRequirement(
            $tenantId,
            $actor,
            true,
        );
    }

    private function authorizeWithoutActiveTenantRequirement(
        string $tenantId,
        User $actor,
        bool $transactional,
    ): void {
        if ($transactional && DB::transactionLevel() < 1) {
            throw new \LogicException(
                'Transactional Unit Handover authorization requires an active transaction.',
            );
        }

        if ($transactional) {
            /*
             * Frozen global authorization lock corridor:
             *
             * membership -> User -> Tenant.
             *
             * Tenant existence is required, but Tenant.status is deliberately
             * not an eligibility predicate for Evidence recording or
             * historical source correction.
             */
            $membership = DB::table('tenant_users')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->first();

            $lockedActor = User::query()
                ->whereKey($actor->id)
                ->lockForUpdate()
                ->first();

            $tenant = DB::table('tenants')
                ->where('id', $tenantId)
                ->lockForUpdate()
                ->first();

            if (
                $lockedActor === null
                || ! $lockedActor->isActive()
                || $tenant === null
                || $membership === null
                || $membership->status !== TenantUser::STATUS_ACTIVE
                || ! TenantAdministratorAuthority::allows($lockedActor)
            ) {
                throw new UnitHandoverAccessDenied(
                    'Actor is not authorized for Unit Handover.',
                );
            }

            return;
        }

        $tenant = DB::table('tenants')
            ->where('id', $tenantId)
            ->first();

        $membership = DB::table('tenant_users')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $actor->id)
            ->first();

        if (
            ! $actor->isActive()
            || $tenant === null
            || $membership === null
            || $membership->status !== TenantUser::STATUS_ACTIVE
            || ! TenantAdministratorAuthority::allows($actor)
        ) {
            throw new UnitHandoverAccessDenied(
                'Actor is not authorized for Unit Handover.',
            );
        }
    }
}
