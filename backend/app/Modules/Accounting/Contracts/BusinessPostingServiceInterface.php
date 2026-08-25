<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Contracts;

use App\Modules\Accounting\DTOs\BusinessPostingRequest;
use App\Modules\Accounting\DTOs\PostedJournalResult;

interface BusinessPostingServiceInterface { public function post(BusinessPostingRequest $request): PostedJournalResult; }
