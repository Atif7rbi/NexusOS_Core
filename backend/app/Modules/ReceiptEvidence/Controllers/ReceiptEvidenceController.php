<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ReceiptEvidence\Actions\ApproveReceivingAccount;
use App\Modules\ReceiptEvidence\Actions\AssociateReceiptWithPayment;
use App\Modules\ReceiptEvidence\Actions\CancelReceiptPaymentAssociation;
use App\Modules\ReceiptEvidence\Actions\InvalidateBankReceipt;
use App\Modules\ReceiptEvidence\Actions\ReplaceInvalidatedBankReceipt;
use App\Modules\ReceiptEvidence\Actions\RetireReceivingAccount;
use App\Modules\ReceiptEvidence\Actions\VerifyBankReceipt;
use App\Modules\ReceiptEvidence\Http\ReceiptEvidenceExceptionResponder;
use App\Modules\ReceiptEvidence\Requests\ApproveReceivingAccountRequest;
use App\Modules\ReceiptEvidence\Requests\AssociateReceiptWithPaymentRequest;
use App\Modules\ReceiptEvidence\Requests\CancelReceiptPaymentAssociationRequest;
use App\Modules\ReceiptEvidence\Requests\IndexBankReceiptEvidenceRequest;
use App\Modules\ReceiptEvidence\Requests\IndexReceiptPaymentAssociationsRequest;
use App\Modules\ReceiptEvidence\Requests\IndexReceivingAccountsRequest;
use App\Modules\ReceiptEvidence\Requests\InvalidateBankReceiptRequest;
use App\Modules\ReceiptEvidence\Requests\RetireReceivingAccountRequest;
use App\Modules\ReceiptEvidence\Requests\VerifyBankReceiptRequest;
use App\Modules\ReceiptEvidence\Services\ReceiptEvidenceReadService;
use App\Modules\Receivables\Support\ReceivablesAuthorization;
use App\Modules\Shared\Services\ResolveActiveMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReceiptEvidenceController extends Controller
{
    public function __construct(
        private readonly ResolveActiveMembership $memberships,
        private readonly ReceivablesAuthorization $authorization,
    ) {}

    public function accounts(IndexReceivingAccountsRequest $request, ReceiptEvidenceReadService $reads): JsonResponse
    {
        [$tenantId] = $this->readContext($request);

        return response()->json(['data' => ['receiving_accounts' => $reads->accounts($tenantId, $request->validated())]]);
    }

    public function account(Request $request, string $account, ReceiptEvidenceReadService $reads): JsonResponse
    {
        [$tenantId] = $this->readContext($request);

        return $this->respond(fn (): array => ['receiving_account' => $reads->account($tenantId, $account)]);
    }

    public function approve(ApproveReceivingAccountRequest $request, ApproveReceivingAccount $action, ReceiptEvidenceReadService $reads): JsonResponse
    {
        [$tenantId, $actor] = $this->mutationContext($request);

        return $this->respond(fn (): array => ['receiving_account' => $reads->account($tenantId, $action->execute($tenantId, $actor, $request->validated()))]);
    }

    public function retire(RetireReceivingAccountRequest $request, string $account, RetireReceivingAccount $action, ReceiptEvidenceReadService $reads): JsonResponse
    {
        [$tenantId, $actor] = $this->mutationContext($request);

        return $this->respond(function () use ($tenantId, $actor, $account, $action, $request, $reads): array {
            $action->execute($tenantId, $account, $actor, $request->validated());

            return ['receiving_account' => $reads->account($tenantId, $account)];
        });
    }

    public function receipts(IndexBankReceiptEvidenceRequest $request, ReceiptEvidenceReadService $reads): JsonResponse
    {
        [$tenantId] = $this->readContext($request);

        return response()->json(['data' => ['receipts' => $reads->receipts($tenantId, $request->validated())]]);
    }

    public function receipt(Request $request, string $receipt, ReceiptEvidenceReadService $reads): JsonResponse
    {
        [$tenantId] = $this->readContext($request);

        return $this->respond(fn (): array => ['receipt' => $reads->receipt($tenantId, $receipt)]);
    }

    public function verify(VerifyBankReceiptRequest $request, VerifyBankReceipt $action, ReceiptEvidenceReadService $reads): JsonResponse
    {
        [$tenantId, $actor] = $this->mutationContext($request);

        return $this->respond(fn (): array => ['receipt' => $reads->receipt($tenantId, $action->execute($tenantId, $actor, $request->validated()))]);
    }

    public function invalidate(InvalidateBankReceiptRequest $request, string $receipt, InvalidateBankReceipt $action, ReceiptEvidenceReadService $reads): JsonResponse
    {
        [$tenantId, $actor] = $this->mutationContext($request);

        return $this->respond(function () use ($tenantId, $actor, $receipt, $action, $request, $reads): array {
            $action->execute($tenantId, $receipt, $actor, $request->validated());

            return ['receipt' => $reads->receipt($tenantId, $receipt)];
        });
    }

    public function replace(VerifyBankReceiptRequest $request, string $receipt, ReplaceInvalidatedBankReceipt $action, ReceiptEvidenceReadService $reads): JsonResponse
    {
        [$tenantId, $actor] = $this->mutationContext($request);

        return $this->respond(fn (): array => ['receipt' => $reads->receipt($tenantId, $action->execute($tenantId, $receipt, $actor, $request->validated()))]);
    }

    public function associations(IndexReceiptPaymentAssociationsRequest $request, ReceiptEvidenceReadService $reads): JsonResponse
    {
        [$tenantId] = $this->readContext($request);

        return response()->json(['data' => ['associations' => $reads->associations($tenantId, $request->validated())]]);
    }

    public function association(Request $request, string $association, ReceiptEvidenceReadService $reads): JsonResponse
    {
        [$tenantId] = $this->readContext($request);

        return $this->respond(fn (): array => ['association' => $reads->association($tenantId, $association)]);
    }

    public function associate(AssociateReceiptWithPaymentRequest $request, AssociateReceiptWithPayment $action, ReceiptEvidenceReadService $reads): JsonResponse
    {
        [$tenantId, $actor] = $this->mutationContext($request);

        return $this->respond(fn (): array => ['association' => $reads->association($tenantId, $action->execute($tenantId, $actor, $request->validated()))]);
    }

    public function cancel(CancelReceiptPaymentAssociationRequest $request, string $association, CancelReceiptPaymentAssociation $action, ReceiptEvidenceReadService $reads): JsonResponse
    {
        [$tenantId, $actor] = $this->mutationContext($request);

        return $this->respond(function () use ($tenantId, $actor, $association, $action, $request, $reads): array {
            $action->execute($tenantId, $association, $actor, $request->validated());

            return ['association' => $reads->association($tenantId, $association)];
        });
    }

    /** @return array{string, User} */
    private function readContext(Request $request): array
    {
        [$tenantId, $actor] = $this->membershipContext($request);
        $this->authorization->authorize($tenantId, $actor);

        return [$tenantId, $actor];
    }

    /** @return array{string, User} */
    private function mutationContext(Request $request): array
    {
        return $this->membershipContext($request);
    }

    /** @return array{string, User} */
    private function membershipContext(Request $request): array
    {
        /** @var User $actor */
        $actor = $request->user();
        $membership = $this->memberships->handle($actor);

        return [(string) $membership->tenant_id, $actor];
    }

    private function respond(callable $callback): JsonResponse
    {
        try {
            return response()->json(['data' => $callback()]);
        } catch (\Throwable $exception) {
            return app(ReceiptEvidenceExceptionResponder::class)->respond($exception);
        }
    }
}
