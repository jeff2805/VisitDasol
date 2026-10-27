<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\OwnerApplicationDecided;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * All account status changes go through here, so that every change is
 * checked against the allowed transitions, written to the audit trail,
 * and (where it matters) ends the person's open sessions.
 *
 * Who is allowed to call these is decided by UserPolicy, in the controller.
 */
class AccountManager
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly Notifier $notifier,
    ) {}

    /** Approve a pending owner application: the owner may now sign in. */
    public function approveOwner(User $owner, ?string $remarks = null): void
    {
        $from = $this->transition($owner, UserStatus::Active);

        $owner->ownerProfile()->first()?->forceFill([
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_remarks' => $remarks,
        ])->save();

        $this->logger->logStatusChange('owner.approved', $owner, "Approved the owner account of {$owner->full_name}", $from, $owner->status->value);

        $this->notifier->send($owner, new OwnerApplicationDecided(true, $remarks));
    }

    /** Turn down an application. The reason is shown when they try to sign in. */
    public function rejectOwner(User $owner, string $remarks): void
    {
        $from = $this->transition($owner, UserStatus::Rejected);

        $owner->ownerProfile()->first()?->forceFill([
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_remarks' => $remarks,
        ])->save();

        $this->endSessions($owner);

        $this->logger->logStatusChange('owner.rejected', $owner, "Rejected the owner application of {$owner->full_name}", $from, $owner->status->value);

        // A rejected applicant cannot sign in at all, so this email is the
        // only way they learn a decision was made, or why.
        $this->notifier->send($owner, new OwnerApplicationDecided(false, $remarks));
    }

    /** Put a rejected application back in the queue for another look. */
    public function reopenOwner(User $owner): void
    {
        $from = $this->transition($owner, UserStatus::Pending);

        $owner->ownerProfile()->first()?->forceFill([
            'reviewed_by' => null,
            'reviewed_at' => null,
        ])->save();

        $this->logger->logStatusChange('owner.reopened', $owner, "Reopened the owner application of {$owner->full_name}", $from, $owner->status->value);
    }

    public function activate(User $user): void
    {
        $from = $this->transition($user, UserStatus::Active);

        $this->logger->logStatusChange('user.activated', $user, "Activated the account of {$user->full_name}", $from, $user->status->value);
    }

    /**
     * Deactivate an account and sign it out everywhere. Any resort this user
     * owns stops being public immediately, without its own status changing.
     */
    public function deactivate(User $user, ?string $reason = null): void
    {
        $from = $this->transition($user, UserStatus::Inactive);

        $this->endSessions($user);

        $this->logger->logStatusChange(
            'user.deactivated',
            $user,
            "Deactivated the account of {$user->full_name}",
            $from,
            $user->status->value,
            filled($reason) ? ['reason' => $reason] : [],
        );
    }

    /**
     * Applies a status change after checking it is allowed.
     *
     * @return string the status the account was in before
     */
    private function transition(User $user, UserStatus $to): string
    {
        $from = $user->status;

        if (! $from->canTransitionTo($to)) {
            throw new RuntimeException("An account cannot go from {$from->value} to {$to->value}.");
        }

        $user->status = $to;
        $user->save();

        return $from->value;
    }

    /**
     * Removes the user's stored sessions so they are signed out on every
     * device at once. Only possible while sessions live in the database.
     */
    private function endSessions(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->delete();
    }
}
