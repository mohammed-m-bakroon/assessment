<?php

namespace App\Http\Resources;

use App\Billing\Money;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Generation */
class GenerationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,

            // Money as exact 9-decimal USD strings; the UI only formats them.
            'estimated_charge_min' => Money::format($this->estimated_charge_min),
            'estimated_charge_max' => Money::format($this->estimated_charge_max),
            'charge' => $this->charge === null ? null : Money::format($this->charge),
            'usage' => $this->usage,

            'image_url' => $this->image_url,
            'image_url_expires_at' => $this->image_url_expires_at?->toIso8601String(),
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            // Why a pending generation hasn't been accepted yet (e.g. concurrency_limit_exceeded).
            'waiting_reason' => $this->status === GenerationStatus::Pending ? $this->last_submit_error : null,

            'created_at' => $this->created_at->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
