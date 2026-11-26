<?php

namespace App\Enums;

enum GenerationStatus: string
{
    /** Saved locally, not yet accepted by Pixelforge (e.g. waiting out a rate limit). */
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * Statuses only ever move forward, and a final status never changes.
     * This is what makes out-of-order webhooks safe: a late "processing"
     * event after "succeeded" is simply ignored.
     */
    public function canMoveTo(self $next): bool
    {
        return ! $this->isFinal() && $next->step() > $this->step();
    }

    private function step(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }
}
