<?php

namespace App\Services;

use App\Enums\WalkInSubmissionStatus;
use App\Exceptions\WalkInException;
use App\Models\Booking;
use App\Models\User;
use App\Models\WalkInSubmission;
use App\Notifications\WalkInReturned;
use Illuminate\Support\Facades\DB;

/**
 * A walk-in record's journey through the Municipal Tourism Office.
 *
 * The ONLY place submission_status is ever written. Every move is made under
 * a row lock and writes two things: the new state on the booking, and an
 * append-only row in walk_in_submissions saying who moved it and why. A
 * status that changed with no trail behind it would be a figure nobody could
 * account for when the office is asked where a number came from.
 *
 *   draft ──submit──► pending_review ──accept──► accepted  (final)
 *                           │
 *                        return (remarks required)
 *                           ▼
 *                       returned ──submit──► resubmitted ──accept──► accepted
 *                                                  │
 *                                               return
 *                                                  ▼
 *                                              returned
 *
 * NOTHING HERE TOUCHES THE ROOM. The booking stays Confirmed throughout, so
 * a record the office sends back keeps its cottage held — the guest is at the
 * resort whatever the paperwork says. That separation is the point of the
 * module and is the one thing not to break.
 *
 * Every guard is asked again inside the transaction even though the policy
 * asked it first. The policy decides what a button may do when a page is
 * drawn; this decides what is true at the instant the write happens, and two
 * reviewers with the same record open is exactly the case the policy cannot
 * see.
 */
class WalkInSubmissionService
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Send a record to the tourism office, or send back a corrected one.
     *
     * The same button either way, because to a resort owner it is the same
     * act. Which of the two statuses it lands on depends on whether the
     * office has seen it before, so a reviewer can tell a fresh record from
     * one they already sent back.
     *
     * @throws WalkInException
     */
    public function submit(Booking $booking, User $owner): Booking
    {
        return DB::transaction(function () use ($booking, $owner): Booking {
            $locked = $this->lock($booking);
            $from = $locked->submission_status;

            if ($from === WalkInSubmissionStatus::Accepted) {
                throw WalkInException::alreadyAccepted();
            }

            if ($from?->isSubmittable() !== true) {
                throw WalkInException::alreadySubmitted();
            }

            if ($missing = $this->missingForSubmission($locked)) {
                throw WalkInException::incomplete($missing);
            }

            // A record the office has returned comes back as a resubmission,
            // so the queue shows which ones have been round once already.
            $wasReturned = $from === WalkInSubmissionStatus::Returned;

            $to = $wasReturned
                ? WalkInSubmissionStatus::Resubmitted
                : WalkInSubmissionStatus::PendingReview;

            $locked->forceFill([
                'submission_status' => $to,
                'submitted_at' => now(),

                /*
                 * The previous refusal is cleared as the record goes back.
                 * Leaving it would show the resort a complaint about a
                 * version they have already fixed; the full series of them
                 * stays in walk_in_submissions, which is where history lives.
                 */
                'return_remarks' => null,
            ])->save();

            $this->record(
                $locked,
                $wasReturned ? WalkInSubmission::RESUBMITTED : WalkInSubmission::SUBMITTED,
                $to,
                $owner,
            );

            $this->logger->log(
                $wasReturned ? 'walk_in.resubmitted' : 'walk_in.submitted',
                $locked,
                "Sent walk-in {$locked->booking_reference} to the tourism office",
                ['from' => $from->value, 'to' => $to->value],
                $owner,
            );

            return $locked;
        });
    }

    /**
     * The office accepts the record, and it becomes countable.
     *
     * This is the moment the stay starts appearing in the Department of
     * Tourism return — see Booking::scopeReportable(). Final: there is no
     * way back from here short of the office asking the resort to record a
     * correction as its own entry.
     *
     * @throws WalkInException
     */
    public function accept(Booking $booking, User $reviewer): Booking
    {
        return DB::transaction(function () use ($booking, $reviewer): Booking {
            $locked = $this->lock($booking);
            $from = $locked->submission_status;

            if ($from?->isAwaitingReview() !== true) {
                throw WalkInException::notUnderReview();
            }

            $locked->forceFill([
                'submission_status' => WalkInSubmissionStatus::Accepted,
                'reviewed_at' => now(),
                'reviewed_by' => $reviewer->id,
                'accepted_at' => now(),
                'return_remarks' => null,
            ])->save();

            $this->record(
                $locked,
                WalkInSubmission::ACCEPTED,
                WalkInSubmissionStatus::Accepted,
                $reviewer,
            );

            $this->logger->log(
                'walk_in.accepted',
                $locked,
                "Accepted walk-in {$locked->booking_reference}",
                ['from' => $from->value],
                $reviewer,
            );

            return $locked;
        });
    }

    /**
     * The office sends the record back for correction.
     *
     * Remarks are not optional. A record returned with no reason is one the
     * resort cannot act on, and the office would be asked by telephone what
     * was wrong with it — which is the work this module exists to save.
     *
     * THE ROOM IS NOT RELEASED. Deliberate, and worth saying twice: the stay
     * stays Confirmed and keeps holding its cottage. The guest slept there
     * whether or not the paperwork was right.
     *
     * @throws WalkInException
     */
    public function returnForCorrection(Booking $booking, User $reviewer, string $remarks): Booking
    {
        $remarks = trim($remarks);

        if ($remarks === '') {
            throw WalkInException::remarksRequired();
        }

        return DB::transaction(function () use ($booking, $reviewer, $remarks): Booking {
            $locked = $this->lock($booking);
            $from = $locked->submission_status;

            if ($from?->isAwaitingReview() !== true) {
                throw WalkInException::notUnderReview();
            }

            $locked->forceFill([
                'submission_status' => WalkInSubmissionStatus::Returned,
                'reviewed_at' => now(),
                'reviewed_by' => $reviewer->id,
                'return_remarks' => $remarks,
            ])->save();

            $this->record(
                $locked,
                WalkInSubmission::RETURNED,
                WalkInSubmissionStatus::Returned,
                $reviewer,
                $remarks,
            );

            $this->logger->log(
                'walk_in.returned',
                $locked,
                "Returned walk-in {$locked->booking_reference} for correction",
                ['from' => $from->value],
                $reviewer,
            );

            // The resort owner, not the guest: a walk-in has no account.
            $locked->loadMissing('resort.owner');

            $this->notifier->send(
                $locked->resort?->owner,
                new WalkInReturned($locked, $remarks),
            );

            return $locked;
        });
    }

    /**
     * What the Department of Tourism return needs and this record has not
     * got, said in words a resort owner can act on.
     *
     * Asked at submission rather than at acceptance so the gap is found
     * while the record is still the resort's to fix, instead of bouncing
     * back from the office days later.
     */
    public function missingForSubmission(Booking $booking): ?string
    {
        $missing = [];

        if (blank($booking->contact_name)) {
            $missing[] = "the guest's name";
        }

        if ($booking->origin === null) {
            $missing[] = 'where the party travelled from';
        }

        if ($booking->guests_male === null || $booking->guests_female === null) {
            $missing[] = 'the male and female guest counts';
        }

        if ($missing === []) {
            return null;
        }

        return 'it is missing '.\Illuminate\Support\Arr::join($missing, ', ', ' and ').'.';
    }

    /* ------------------------------------------------------------------ */

    /**
     * The record, locked, and proved to be a walk-in.
     *
     * An online booking has no submission to move, and letting one through
     * here would put a status on a guest's own reservation that no screen
     * knows how to read.
     */
    private function lock(Booking $booking): Booking
    {
        $locked = Booking::query()
            ->whereKey($booking->id)
            ->lockForUpdate()
            ->first();

        if ($locked === null || ! $locked->isWalkIn()) {
            throw WalkInException::notAWalkIn();
        }

        return $locked;
    }

    /** One append-only line in the record's history. */
    private function record(
        Booking $booking,
        string $action,
        WalkInSubmissionStatus $resulting,
        User $actor,
        ?string $remarks = null,
    ): void {
        WalkInSubmission::create([
            'booking_id' => $booking->id,
            'action' => $action,
            'resulting_status' => $resulting->value,
            'actor_id' => $actor->id,
            'remarks' => $remarks,
        ]);
    }
}
