<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Actions;

use App\Models\User;
final class ReplaceInvalidatedBankReceipt
{
    public function __construct(private readonly VerifyBankReceipt $verify) {}

    public function execute(string $tenantId, string $originalReceiptId, User $actor, array $input): string
    {
        $input['replaces_receipt_id'] = $originalReceiptId;

        return $this->verify->execute($tenantId, $actor, $input);
    }
}
