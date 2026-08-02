<?php

namespace App\Modules\Users\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Leads\Support\EnsureTenantUserCanBePaused;
use App\Modules\Shared\Phone\SaudiMobileNormalizer;
use App\Modules\Users\Requests\StoreTenantUserRequest;
use App\Modules\Users\Requests\UpdateTenantUserRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TenantUserController extends Controller
{
    private const DEMO_USERS_LIMIT = 3;
    public function __construct(
        private readonly SaudiMobileNormalizer $phoneNormalizer,
        private readonly EnsureTenantUserCanBePaused $ensureCanBePaused,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $membership = $this->currentMembership($request);

        $query = TenantUser::query()
            ->where('tenant_id', $membership->tenant_id)
            ->where('status', '!=', TenantUser::STATUS_REMOVED)
            ->with([
                'user:id,name,email,phone,role,last_login_at,created_at,updated_at',
            ])
            ->latest();

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));

            $query->whereHas(
                'user',
                function (Builder $userQuery) use ($search): void {
                    $userQuery->where(function (Builder $nested) use ($search): void {
                        $nested
                            ->where('name', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%")
                            ->orWhere('phone', 'ilike', "%{$search}%");
                    });
                }
            );
        }

        if ($request->filled('status')) {
            $query->where(
                'status',
                (string) $request->query('status')
            );
        }

        if ($request->filled('role')) {
            $query->whereHas(
                'user',
                fn (Builder $userQuery) => $userQuery->where(
                    'role',
                    (string) $request->query('role')
                )
            );
        }

        return response()->json([
            'data' => [
                'users' => $query
                    ->paginate(
                        perPage: min(
                            max((int) $request->query('per_page', 20), 1),
                            100
                        )
                    ),
                'summary' => [
                    'total' => TenantUser::query()
                        ->where('tenant_id', $membership->tenant_id)
                        ->where('status', '!=', TenantUser::STATUS_REMOVED)
                        ->count(),

                    'active' => TenantUser::query()
                        ->where('tenant_id', $membership->tenant_id)
                        ->where('status', TenantUser::STATUS_ACTIVE)
                        ->count(),

                    'paused' => TenantUser::query()
                        ->where('tenant_id', $membership->tenant_id)
                        ->whereIn('status', [
                            TenantUser::STATUS_PAUSED,
                            TenantUser::STATUS_SUSPENDED,
                        ])
                        ->count(),

                    'limit' => self::DEMO_USERS_LIMIT,
                ],
            ],
        ]);
    }

    public function store(
        StoreTenantUserRequest $request
    ): JsonResponse {
        $this->ensureAdministrator($request);

        $actorMembership = $this->currentMembership($request);

        $currentCount = TenantUser::query()
            ->where('tenant_id', $actorMembership->tenant_id)
            ->where('status', '!=', TenantUser::STATUS_REMOVED)
            ->count();

        if ($currentCount >= self::DEMO_USERS_LIMIT) {
            throw ValidationException::withMessages([
                'users' => [
                    'تم الوصول إلى الحد المتاح للمستخدمين في النسخة الحالية.',
                ],
            ]);
        }

        $validated = $request->validated();

        $membership = DB::transaction(
            function () use (
                $validated,
                $actorMembership,
                $request
            ): TenantUser {
                $user = User::query()->create([
                    'name' => trim($validated['name']),
                    'email' => mb_strtolower(
                        trim($validated['email'])
                    ),
                    'phone' => $this->phoneNormalizer->normalizeNullable($validated['phone'] ?? null),
                    'role' => $validated['role'],
                    'status' => User::STATUS_ACTIVE,
                    'email_verified_at' => now(),
                    'password' => $validated['password'],
                ]);

                return TenantUser::query()->create([
                    'tenant_id' => $actorMembership->tenant_id,
                    'user_id' => $user->id,
                    'status' => TenantUser::STATUS_ACTIVE,
                    'invited_at' => null,
                    'joined_at' => now(),
                    'removed_at' => null,
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);
            }
        );

        return response()->json([
            'message' => 'تم إنشاء المستخدم بنجاح.',
            'data' => [
                'user' => $membership->load([
                    'user:id,name,email,phone,role,last_login_at,created_at,updated_at',
                ]),
            ],
        ], 201);
    }

    public function show(
        Request $request,
        TenantUser $user
    ): JsonResponse {
        $membership = $this->currentMembership($request);

        $this->ensureSameTenant($membership, $user);

        return response()->json([
            'data' => [
                'user' => $user->load([
                    'user:id,name,email,phone,role,last_login_at,created_at,updated_at',
                    'creator:id,name',
                    'updater:id,name',
                ]),
            ],
        ]);
    }

    public function update(
        UpdateTenantUserRequest $request,
        TenantUser $user
    ): JsonResponse {
        $this->ensureAdministrator($request);

        $actorMembership = $this->currentMembership($request);

        $this->ensureSameTenant($actorMembership, $user);

        $validated = $request->validated();

        if ($user->user_id === $request->user()->id) {
            if (
                array_key_exists('role', $validated) ||
                array_key_exists('status', $validated)
            ) {
                throw ValidationException::withMessages([
                    'user' => [
                        'لا يمكنك تغيير دورك أو حالة عضويتك من شاشة إدارة المستخدمين.',
                    ],
                ]);
            }
        }

        DB::transaction(
            function () use ($validated, $request, $user): void {
                $membership = TenantUser::query()
                    ->with('user')
                    ->whereKey($user->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    ($validated['status'] ?? null) === TenantUser::STATUS_PAUSED
                    && $membership->status !== TenantUser::STATUS_PAUSED
                ) {
                    $this->ensureCanBePaused->handle($membership);
                }

                $identityData = [];

                foreach ([
                    'name',
                    'email',
                    'phone',
                    'role',
                    'password',
                ] as $field) {
                    if (array_key_exists($field, $validated)) {
                        $identityData[$field] = $validated[$field];
                    }
                }

                if (isset($identityData['email'])) {
                    $identityData['email'] = mb_strtolower(
                        trim($identityData['email'])
                    );
                }
                if (array_key_exists('phone', $identityData)) {
                    $identityData['phone'] = $this->phoneNormalizer
                        ->normalizeNullable($identityData['phone']);
                }

                if ($identityData !== []) {
                    $membership->user->update($identityData);
                }

                if (array_key_exists('status', $validated)) {
                    $membership->status = $validated['status'];

                    $membership->user->forceFill([
                        'status' => $validated['status']
                            === TenantUser::STATUS_SUSPENDED
                                ? User::STATUS_SUSPENDED
                                : User::STATUS_ACTIVE,
                    ])->save();

                    if ($validated['status'] !== TenantUser::STATUS_ACTIVE) {
                        $membership->user->tokens()->delete();
                    }
                }

                $membership->updated_by = $request->user()->id;
                $membership->save();
            }
        );

        return response()->json([
            'message' => 'تم تحديث المستخدم بنجاح.',
            'data' => [
                'user' => $user->fresh()->load([
                    'user:id,name,email,phone,role,last_login_at,created_at,updated_at',
                ]),
            ],
        ]);
    }

    public function destroy(
        Request $request,
        TenantUser $user
    ): JsonResponse {
        $this->ensureAdministrator($request);

        $actorMembership = $this->currentMembership($request);

        $this->ensureSameTenant($actorMembership, $user);

        if ($user->user_id === $request->user()->id) {
            throw ValidationException::withMessages([
                'user' => [
                    'لا يمكنك إزالة عضويتك من حسابك الحالي.',
                ],
            ]);
        }

        DB::transaction(function () use ($request, $user): void {
            $user->forceFill([
                'status' => TenantUser::STATUS_REMOVED,
                'removed_at' => now(),
                'updated_by' => $request->user()->id,
            ])->save();

            $user->user->forceFill([
                'status' => User::STATUS_ARCHIVED,
            ])->save();

            $user->user->tokens()->delete();
        });

        return response()->json([
            'message' => 'تم حذف عضوية المستخدم بنجاح.',
        ]);
    }

    private function ensureAdministrator(
        Request $request
    ): void {
        abort_unless(
            $request->user()?->role
                === User::ROLE_ADMINISTRATOR,
            403,
            'ليس لديك صلاحية لإدارة المستخدمين.'
        );
    }

    private function currentMembership(
        Request $request
    ): TenantUser {
        $membership = TenantUser::query()
            ->where('user_id', $request->user()->id)
            ->where('status', TenantUser::STATUS_ACTIVE)
            ->first();

        if (! $membership) {
            throw ValidationException::withMessages([
                'membership' => [
                    'لا توجد عضوية شركة نشطة لهذا الحساب.',
                ],
            ]);
        }

        return $membership;
    }

    private function ensureSameTenant(
        TenantUser $actorMembership,
        TenantUser $targetMembership
    ): void {
        abort_unless(
            $actorMembership->tenant_id
                === $targetMembership->tenant_id,
            404
        );
    }
}
