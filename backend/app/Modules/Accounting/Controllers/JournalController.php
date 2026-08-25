<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Actions\ManageManualJournalAction;
use App\Modules\Accounting\Actions\ReverseJournalAction;
use App\Modules\Accounting\Http\AccountingLineMapper;
use App\Modules\Accounting\Requests\IndexJournalsRequest;
use App\Modules\Accounting\Requests\ReverseJournalRequest;
use App\Modules\Accounting\Requests\StoreJournalRequest;
use App\Modules\Accounting\Requests\WriteJournalRequest;
use App\Modules\Accounting\Services\AccountingReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class JournalController extends AccountingController
{
    public function index(IndexJournalsRequest $request, AccountingReadService $reads): JsonResponse
    {
        [$tenantId] = $this->context($request, 'view_ledger');

        return response()->json(['data' => ['journals' => $reads->journals($tenantId, $request->validated())]]);
    }

    public function store(StoreJournalRequest $request, ManageManualJournalAction $action, AccountingLineMapper $lines, AccountingReadService $reads): JsonResponse
    {
        [$tenantId, $actor] = $this->context($request, 'create_manual_draft');
        $data = $request->validated();
        $id = $action->create($tenantId, $actor, $data['entry_date'], $data['description'], $lines->map($data['lines'] ?? []));

        return response()->json(['data' => ['journal' => $reads->journal($tenantId, $id)]], 201);
    }

    public function show(Request $request, string $journal, AccountingReadService $reads): JsonResponse
    {
        [$tenantId] = $this->context($request, 'view_ledger');

        return response()->json(['data' => ['journal' => $reads->journal($tenantId, $journal)]]);
    }

    public function update(WriteJournalRequest $request, string $journal, ManageManualJournalAction $action, AccountingLineMapper $lines, AccountingReadService $reads): JsonResponse
    {
        [$tenantId, $actor] = $this->context($request, 'edit_manual_draft');
        $reads->journal($tenantId, $journal);
        $data = $request->validated();
        $action->update($tenantId, $journal, $actor, $data['entry_date'], $data['description'], $lines->map($data['lines']));

        return response()->json(['data' => ['journal' => $reads->journal($tenantId, $journal)]]);
    }

    public function destroy(Request $request, string $journal, ManageManualJournalAction $action, AccountingReadService $reads): Response
    {
        [$tenantId, $actor] = $this->context($request, 'edit_manual_draft');
        $reads->journal($tenantId, $journal);
        $action->delete($tenantId, $journal, $actor);

        return response()->noContent();
    }

    public function post(Request $request, string $journal, ManageManualJournalAction $action, AccountingReadService $reads): JsonResponse
    {
        [$tenantId, $actor] = $this->context($request, 'post_journal');
        $reads->journal($tenantId, $journal);
        $result = $action->post($tenantId, $journal, $actor);

        return response()->json(['data' => ['result' => ['journal_entry_id' => $result->journalEntryId, 'journal_number' => $result->journalNumber, 'idempotent_replay' => $result->idempotentReplay], 'journal' => $reads->journal($tenantId, $result->journalEntryId)]]);
    }

    public function reverse(ReverseJournalRequest $request, string $journal, ReverseJournalAction $action, AccountingReadService $reads): JsonResponse
    {
        [$tenantId, $actor] = $this->context($request, 'reverse_journal');
        $reads->journal($tenantId, $journal);
        $data = $request->validated();
        $result = $action->execute($tenantId, $journal, $actor, $data['entry_date'], $data['reason']);

        return response()->json(['data' => ['result' => ['journal_entry_id' => $result->journalEntryId, 'journal_number' => $result->journalNumber], 'journal' => $reads->journal($tenantId, $result->journalEntryId)]]);
    }
}
