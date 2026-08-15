<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Shared\Exceptions\TenantAccessDeniedException;
use App\Modules\Shared\Exceptions\TenantMembershipInvitedException;
use App\Modules\Shared\Exceptions\TenantMembershipMissingException;
use App\Modules\Shared\Exceptions\TenantMembershipPausedException;
use App\Modules\Shared\Exceptions\TenantMembershipRemovedException;
use App\Modules\Shared\Exceptions\TenantMembershipSuspendedException;
use App\Modules\Shared\Exceptions\UserAccessDeniedException;
use Illuminate\Http\JsonResponse;
use LogicException;
use Throwable;

final class LifecycleAccessDenialResponder
{
    public function __construct(
        private readonly ApiErrorResponse $errors,
    ) {}

    public function respond(Throwable $exception): JsonResponse
    {
        [$code, $status, $title, $message] = match (true) {
            $exception instanceof TenantMembershipMissingException => [
                'tenant_membership_missing',
                'missing',
                'لا توجد شركة مرتبطة بالحساب',
                'لا توجد عضوية شركة مرتبطة بهذا الحساب. يرجى التواصل مع مسؤول النظام.',
            ],
            $exception instanceof TenantMembershipInvitedException => [
                'tenant_membership_invited',
                'invited',
                'عضويتك بانتظار التفعيل',
                'عضويتك في هذه الشركة لم تُفعّل بعد.',
            ],
            $exception instanceof TenantMembershipPausedException => [
                'tenant_membership_paused',
                'paused',
                'تم إيقاف عضويتك مؤقتًا',
                'تم إيقاف عضويتك مؤقتًا في هذه الشركة. يرجى التواصل مع مدير الشركة لإعادة تفعيل حسابك.',
            ],
            $exception instanceof TenantMembershipSuspendedException => [
                'tenant_membership_suspended',
                'suspended',
                'تم تعليق عضويتك',
                'تم تعليق عضويتك في هذه الشركة. يرجى التواصل مع مسؤول الشركة لمزيد من المعلومات.',
            ],
            $exception instanceof TenantMembershipRemovedException => [
                'tenant_membership_removed',
                'removed',
                'انتهت عضويتك',
                'لم تعد عضوًا في هذه الشركة.',
            ],
            $exception instanceof UserAccessDeniedException =>
                $this->userDenial($exception->status),
            $exception instanceof TenantAccessDeniedException =>
                $this->tenantDenial($exception->status),
            default => throw new LogicException(
                'Unsupported lifecycle access exception.',
            ),
        };

        return $this->errors->make(403, $code, $message, [
            'status' => $status,
            'title' => $title,
        ]);
    }

    /** @return array{string, string, string, string} */
    private function userDenial(string $status): array
    {
        return match ($status) {
            User::STATUS_SUSPENDED => [
                'user_suspended',
                User::STATUS_SUSPENDED,
                'تم تعليق الحساب',
                'تم تعليق هذا الحساب. يرجى التواصل مع مسؤول النظام.',
            ],
            User::STATUS_ARCHIVED => [
                'user_archived',
                User::STATUS_ARCHIVED,
                'الحساب غير متاح',
                'تمت أرشفة هذا الحساب ولم يعد متاحًا للاستخدام.',
            ],
            default => [
                'user_inactive',
                $status,
                'الحساب غير نشط',
                'هذا الحساب غير نشط.',
            ],
        };
    }

    /** @return array{string, string, string, string} */
    private function tenantDenial(string $status): array
    {
        return match ($status) {
            Tenant::STATUS_INVITED => [
                'tenant_invited',
                Tenant::STATUS_INVITED,
                'الشركة بانتظار التفعيل',
                'حساب الشركة لم يُفعّل بعد.',
            ],
            Tenant::STATUS_PAUSED => [
                'tenant_paused',
                Tenant::STATUS_PAUSED,
                'تم إيقاف الشركة مؤقتًا',
                'تم إيقاف الوصول إلى هذه الشركة مؤقتًا.',
            ],
            Tenant::STATUS_SUSPENDED => [
                'tenant_suspended',
                Tenant::STATUS_SUSPENDED,
                'تم تعليق الشركة',
                'تم تعليق الوصول إلى هذه الشركة.',
            ],
            Tenant::STATUS_REMOVED => [
                'tenant_removed',
                Tenant::STATUS_REMOVED,
                'الشركة غير متاحة',
                'لم تعد هذه الشركة متاحة للاستخدام.',
            ],
            default => [
                'tenant_inactive',
                $status,
                'الشركة غير نشطة',
                'هذه الشركة غير نشطة.',
            ],
        };
    }
}
