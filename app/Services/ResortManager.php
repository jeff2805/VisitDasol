<?php

namespace App\Services;

use App\Enums\ResortStatus;
use App\Models\Resort;
use App\Notifications\ResortApplicationDecided;
use RuntimeException;

/**
 * Every change to a resort's status goes through here, so each one is
 * checked against the allowed transitions and written to the audit trail.
 * Who may call these is decided by ResortPolicy, in the controller.
 */
class ResortManager
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly Notifier $notifier,
    ) {}

    public function approve(Resort $resort): void
    {
        $from = $this->transition($resort, ResortStatus::Active, [
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_remarks' => null,
        ]);

        $this->logger->logStatusChange('resort.approved', $resort, "Approved the resort {$resort->name}", $from, $resort->status->value);

        // The owner has been waiting on this, and a pending listing gives them
        // no sign of whether anything is happening.
        $this->notifier->send($resort->owner, new ResortApplicationDecided($resort, true));
    }

    public function reject(Resort $resort, string $remarks): void
    {
        $from = $this->transition($resort, ResortStatus::Rejected, [
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_remarks' => $remarks,
        ]);

        $this->logger->logStatusChange('resort.rejected', $resort, "Rejected the resort {$resort->name}", $from, $resort->status->value);

        $this->notifier->send($resort->owner, new ResortApplicationDecided($resort, false, $remarks));
    }

    public function activate(Resort $resort): void
    {
        $from = $this->transition($resort, ResortStatus::Active, [
            'review_remarks' => null,
        ]);

        $this->logger->logStatusChange('resort.activated', $resort, "Put the resort {$resort->name} back online", $from, $resort->status->value);
    }

    /** Takes a live listing offline. Existing reservations are untouched. */
    public function deactivate(Resort $resort, string $remarks): void
    {
        $from = $this->transition($resort, ResortStatus::Inactive, [
            'review_remarks' => $remarks,
        ]);

        $this->logger->logStatusChange('resort.deactivated', $resort, "Took the resort {$resort->name} offline", $from, $resort->status->value, [
            'reason' => $remarks,
        ]);
    }

    /** Featured resorts are shown first on the home page and listing. */
    public function setFeatured(Resort $resort, bool $featured): void
    {
        $resort->forceFill(['is_featured' => $featured])->save();

        $this->logger->log(
            $featured ? 'resort.featured' : 'resort.unfeatured',
            $resort,
            ($featured ? 'Featured the resort ' : 'Removed the feature flag from ').$resort->name,
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return string  the status before the change
     */
    private function transition(Resort $resort, ResortStatus $to, array $extra = []): string
    {
        $from = $resort->status;

        if (! $from->canTransitionTo($to)) {
            throw new RuntimeException("A resort cannot go from {$from->value} to {$to->value}.");
        }

        $resort->forceFill(['status' => $to] + $extra)->save();

        return $from->value;
    }
}
