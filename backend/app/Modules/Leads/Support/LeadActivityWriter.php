<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Leads\Enums\ActivityType;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use Carbon\CarbonInterface;
use LogicException;

final class LeadActivityWriter
{
    /**
     * @param array<string, mixed> $payload
     */
    public function automatic(
        Lead $lead,
        ActivityType $type,
        array $payload,
        int $actorId,
        CarbonInterface $occurredAt,
    ): LeadActivity {
        if ($type === ActivityType::Note) {
            throw new LogicException('Note Activities must be created through the note writer.');
        }

        return $this->create(
            lead: $lead,
            type: $type,
            body: null,
            payload: [
                'schema_version' => 1,
                ...$payload,
            ],
            actorId: $actorId,
            occurredAt: $occurredAt,
        );
    }

    public function note(
        Lead $lead,
        string $body,
        int $actorId,
        CarbonInterface $occurredAt,
    ): LeadActivity {
        return $this->create(
            lead: $lead,
            type: ActivityType::Note,
            body: $body,
            payload: null,
            actorId: $actorId,
            occurredAt: $occurredAt,
        );
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function create(
        Lead $lead,
        ActivityType $type,
        ?string $body,
        ?array $payload,
        int $actorId,
        CarbonInterface $occurredAt,
    ): LeadActivity {
        $activity = new LeadActivity([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'type' => $type,
            'body' => $body,
            'payload' => $payload,
            'occurred_at' => $occurredAt,
            'created_by' => $actorId,
        ]);

        $activity->created_at = $occurredAt;
        $activity->save();

        return $activity;
    }
}
