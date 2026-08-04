<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Leads\Models\Lead;
use Carbon\CarbonImmutable;

final class LeadFollowUpStateResolver
{
    public function resolve(Lead $lead, string $timezone): ?string
    {
        if ($lead->isArchived() || ! $lead->isOpen()) {
            return null;
        }

        if ($lead->next_follow_up_at === null) {
            return 'unscheduled';
        }

        $now = CarbonImmutable::now($timezone);
        $followUp = CarbonImmutable::instance($lead->next_follow_up_at)->setTimezone($timezone);
        $todayStart = $now->startOfDay();
        $tomorrowStart = $todayStart->addDay();
        $dayAfterTomorrowStart = $tomorrowStart->addDay();
        $weekEnd = $todayStart->endOfWeek(CarbonImmutable::SUNDAY)->addSecond();

        if ($followUp->lessThan($todayStart)) {
            return 'overdue';
        }

        if ($followUp->lessThan($tomorrowStart)) {
            return 'today';
        }

        if ($followUp->lessThan($dayAfterTomorrowStart)) {
            return 'tomorrow';
        }

        if ($followUp->lessThan($weekEnd)) {
            return 'this_week';
        }

        return 'scheduled_later';
    }
}
