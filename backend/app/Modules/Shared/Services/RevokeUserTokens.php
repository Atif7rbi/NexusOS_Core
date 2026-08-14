<?php

declare(strict_types=1);

namespace App\Modules\Shared\Services;

use App\Models\User;

final class RevokeUserTokens
{
    public function all(User $user): void
    {
        $user->tokens()->delete();
    }
}
