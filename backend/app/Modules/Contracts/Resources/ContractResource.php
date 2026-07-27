<?php

declare(strict_types=1);

namespace App\Modules\Contracts\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Contracts\Models\Contract */
final class ContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'reservation_id' => $this->reservation_id,
            'status' => $this->status->value,
            'total_amount' => (string) $this->total_amount,
            'activated_at' => $this->activated_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'reservation' => $this->whenLoaded('reservation', function () {
                $reservation = $this->reservation;

                return [
                    'id' => $reservation->id,
                    'status' => $reservation->status->value,
                    'unit' => $reservation->relationLoaded('unit') && $reservation->unit !== null
                        ? [
                            'id' => $reservation->unit->id,
                            'project_id' => $reservation->unit->project_id,
                            'unit_number' => $reservation->unit->unit_number,
                            'status' => $reservation->unit->status->value,
                        ]
                        : null,
                    'customer' => $reservation->relationLoaded('customer') && $reservation->customer !== null
                        ? [
                            'id' => $reservation->customer->id,
                            'name' => $reservation->customer->name,
                            'status' => $reservation->customer->status->value,
                        ]
                        : null,
                ];
            }),
        ];
    }
}
