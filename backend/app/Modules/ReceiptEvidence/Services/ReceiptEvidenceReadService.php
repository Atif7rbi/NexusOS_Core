<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class ReceiptEvidenceReadService
{
    public function accounts(string $tenantId, array $filters): array
    {
        $query = DB::table('approved_receiving_accounts')->where('tenant_id', $tenantId);

        foreach (['status', 'institution_identifier'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 25)
            ->through(fn (object $row): array => $this->accountProjection($row))
            ->toArray();
    }

    public function account(string $tenantId, string $id): array
    {
        $row = DB::table('approved_receiving_accounts')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if ($row === null) {
            throw (new ModelNotFoundException)->setModel('ApprovedReceivingAccount');
        }

        return $this->accountProjection($row);
    }

    public function receipts(string $tenantId, array $filters): array
    {
        $query = DB::table('bank_receipt_evidence')->where('tenant_id', $tenantId);

        foreach (['status', 'receiving_account_id', 'currency', 'source_identity_kind'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (isset($filters['control_date_from'])) {
            $query->where('control_date', '>=', $filters['control_date_from']);
        }

        if (isset($filters['control_date_to'])) {
            $query->where('control_date', '<=', $filters['control_date_to']);
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 25)
            ->through(fn (object $row): array => $this->receiptProjection($row))
            ->toArray();
    }

    public function receipt(string $tenantId, string $id): array
    {
        $row = DB::table('bank_receipt_evidence')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if ($row === null) {
            throw (new ModelNotFoundException)->setModel('BankReceiptEvidence');
        }

        return $this->receiptProjection($row);
    }

    public function associations(string $tenantId, array $filters): array
    {
        $query = DB::table('receipt_payment_associations')->where('tenant_id', $tenantId);

        foreach (['status', 'receipt_id', 'payment_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 25)
            ->through(fn (object $row): array => $this->associationProjection($row))
            ->toArray();
    }

    public function association(string $tenantId, string $id): array
    {
        $row = DB::table('receipt_payment_associations')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if ($row === null) {
            throw (new ModelNotFoundException)->setModel('ReceiptPaymentAssociation');
        }

        return $this->associationProjection($row);
    }

    private function accountProjection(object $row): array
    {
        return $this->only($row, [
            'id',
            'institution_identifier',
            'masked_account_identity',
            'valid_from',
            'retired_from',
            'status',
            'approved_by',
            'approved_at',
            'retired_by',
            'retired_at',
            'retirement_reason',
            'created_at',
            'updated_at',
        ]);
    }

    private function receiptProjection(object $row): array
    {
        return $this->only($row, [
            'id',
            'receiving_account_id',
            'channel',
            'source_identity_kind',
            'source_identity_version',
            'source_identity',
            'amount',
            'currency',
            'control_date',
            'evidence_reference',
            'evidence_locator',
            'verification_method',
            'verified_by',
            'verified_at',
            'status',
            'invalidated_by',
            'invalidated_at',
            'invalidation_reason',
            'replaces_receipt_id',
            'created_at',
            'updated_at',
        ]);
    }

    private function associationProjection(object $row): array
    {
        return $this->only($row, [
            'id',
            'receipt_id',
            'payment_id',
            'associated_amount',
            'currency',
            'associated_by',
            'associated_at',
            'status',
            'cancelled_by',
            'cancelled_at',
            'cancellation_reason',
            'replaces_association_id',
            'created_at',
            'updated_at',
        ]);
    }

    private function only(object $row, array $fields): array
    {
        return array_intersect_key((array) $row, array_flip($fields));
    }
}
