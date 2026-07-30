<?php

declare(strict_types=1);

namespace App\Modules\Collections\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Collections\Actions\AmendCollectionScheduleAction;
use App\Modules\Collections\Actions\FinalizeCollectionScheduleAction;
use App\Modules\Collections\Actions\SaveDraftCollectionScheduleAction;
use App\Modules\Collections\DTOs\CollectionLineData;
use App\Modules\Collections\Requests\AmendCollectionScheduleRequest;
use App\Modules\Collections\Requests\FinalizeCollectionScheduleRequest;
use App\Modules\Collections\Requests\GetCollectionScheduleRequest;
use App\Modules\Collections\Requests\SaveDraftCollectionScheduleRequest;
use App\Modules\Collections\Support\CollectionScheduleExceptionResponder;
use App\Modules\Collections\Support\CollectionScheduleResponseAssembler;
use App\Modules\Contracts\Models\Contract;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class CollectionScheduleController extends Controller
{
    public function __construct(
        private readonly CollectionScheduleResponseAssembler $assembler,
        private readonly CollectionScheduleExceptionResponder $exceptions,
    ) {}

    public function show(GetCollectionScheduleRequest $request, string $contract): JsonResponse
    {
        $record = $this->contract($request);

        return $this->execute(fn (): JsonResponse => response()->json([
            'data' => $this->assembler->assemble(
                $record,
                $this->user($request),
                $request->includeHistory(),
            ),
        ]));
    }

    public function saveDraft(
        SaveDraftCollectionScheduleRequest $request,
        string $contract,
        SaveDraftCollectionScheduleAction $action,
    ): JsonResponse {
        $record = $this->contract($request);

        return $this->execute(function () use ($request, $record, $action): JsonResponse {
            $user = $this->user($request);

            $action->execute(
                (string) $record->tenant_id,
                (string) $record->id,
                $user->id,
                $this->lines($request->validated('lines')),
            );

            return response()->json([
                'message' => 'تم حفظ مسودة جدول التحصيل بنجاح.',
                'data' => $this->assembler->assemble($record->refresh(), $user),
            ]);
        });
    }

    public function finalize(
        FinalizeCollectionScheduleRequest $request,
        string $contract,
        FinalizeCollectionScheduleAction $action,
    ): JsonResponse {
        $record = $this->contract($request);

        return $this->execute(function () use ($request, $record, $action): JsonResponse {
            $user = $this->user($request);

            $action->execute(
                (string) $record->tenant_id,
                (string) $record->id,
                $user->id,
            );

            return response()->json([
                'message' => 'تم اعتماد جدول التحصيل بنجاح.',
                'data' => $this->assembler->assemble($record->refresh(), $user),
            ]);
        });
    }

    public function amend(
        AmendCollectionScheduleRequest $request,
        string $contract,
        AmendCollectionScheduleAction $action,
    ): JsonResponse {
        $record = $this->contract($request);

        return $this->execute(function () use ($request, $record, $action): JsonResponse {
            $user = $this->user($request);

            $action->execute(
                (string) $record->tenant_id,
                (string) $record->id,
                $user->id,
                $request->validated('expected_active_collection_ids'),
                $this->lines($request->validated('lines')),
                $request->validated('cancellation_reason'),
            );

            return response()->json([
                'message' => 'تم استبدال جدول التحصيل بنجاح.',
                'data' => $this->assembler->assemble($record->refresh(), $user),
            ]);
        });
    }

    private function contract(Request $request): Contract
    {
        $contract = $request->attributes->get('collection_schedule_contract');

        if (! $contract instanceof Contract) {
            throw new \LogicException('Tenant-scoped Contract was not resolved.');
        }

        return $contract;
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new \LogicException('Authenticated user was not resolved.');
        }

        return $user;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, CollectionLineData>
     */
    private function lines(array $lines): array
    {
        return array_map(
            static fn (array $line): CollectionLineData => new CollectionLineData(
                id: isset($line['id']) ? (string) $line['id'] : null,
                sequence: (int) $line['sequence'],
                title: (string) $line['title'],
                amount: (string) $line['amount'],
                dueDate: CarbonImmutable::parse((string) $line['due_date']),
                notes: array_key_exists('notes', $line) ? $line['notes'] : null,
            ),
            $lines,
        );
    }

    private function execute(callable $operation): JsonResponse
    {
        try {
            return $operation();
        } catch (Throwable $exception) {
            $response = $this->exceptions->from($exception);

            if ($response !== null) {
                return $response;
            }

            throw $exception;
        }
    }
}
