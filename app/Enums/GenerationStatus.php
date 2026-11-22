<?php

namespace App\Enums;

enum GenerationStatus: string
{
    /** Saved locally, not yet accepted by Pixelforge (e.g. waiting for capacity). */
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * Statuses only ever move forward. Webhooks can arrive late or out of order,
     * so an update is applied only if it moves the generation to a higher rank.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    public function canMoveTo(self $next): bool
    {
        return ! $this->isFinal() && $next->rank() > $this->rank();
    }
}
