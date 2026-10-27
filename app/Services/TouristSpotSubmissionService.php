<?php

namespace App\Services;

use App\Enums\DestinationStatus;
use App\Enums\UserRole;
use App\Models\Destination;
use App\Models\User;
use App\Notifications\TouristSpotDecided;
use App\Notifications\TouristSpotSubmitted;
use Illuminate\Support\Facades\DB;

/**
 * The life of a destination sent in by a barangay.
 *
 * Every status change goes through here, so the rules about who published
 * what, and when, live in one place rather than in three controllers. The
 * barangay never sets a status directly; it asks, and the office answers.
 */
class TouristSpotSubmissionService
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly Notifier $notifier,
    ) {}

    /**
     * A barangay official sends in a new spot.
     *
     * The barangay is taken from the official's account, never from the form:
     * otherwise an official could write a spot into a barangay that is not
     * theirs simply by editing the page before submitting.
     *
     * @param  array<string, mixed>  $data
     */
    public function submit(User $official, array $data): Destination
    {
        return DB::transaction(function () use ($official, $data): Destination {
            $spot = new Destination($data);

            $spot->forceFill([
                'barangay' => $official->barangay,
                'status' => DestinationStatus::Pending,
                'is_featured' => false,
                'created_by' => $official->id,
                'submitted_at' => now(),
            ])->save();

            $this->logger->log(
                'tourist_spot.submitted',
                $spot,
                "Barangay {$official->barangay} sent in {$spot->name}",
                ['barangay' => $official->barangay],
                $official,
            );

            $this->tellTheOffice($spot, $official);

            return $spot;
        });
    }

    /** A sent-back spot, corrected and offered again. */
    public function resubmit(User $official, Destination $spot): Destination
    {
        return DB::transaction(function () use ($official, $spot): Destination {
            $spot->forceFill([
                'status' => DestinationStatus::Pending,
                'submitted_at' => now(),
                // The previous decision is cleared: it was about the old text,
                // and leaving it would show the barangay a reason that no
                // longer refers to anything on the page.
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_remarks' => null,
            ])->save();

            $this->logger->log(
                'tourist_spot.resubmitted',
                $spot,
                "Barangay {$official->barangay} sent in {$spot->name} again",
                [],
                $official,
            );

            $this->tellTheOffice($spot, $official);

            return $spot;
        });
    }

    /** The office publishes it. */
    public function approve(User $reviewer, Destination $spot, ?string $remarks = null): Destination
    {
        return $this->decide($reviewer, $spot, DestinationStatus::Active, $remarks);
    }

    /** The office sends it back with a reason. */
    public function reject(User $reviewer, Destination $spot, string $remarks): Destination
    {
        return $this->decide($reviewer, $spot, DestinationStatus::Rejected, $remarks);
    }

    private function decide(User $reviewer, Destination $spot, DestinationStatus $outcome, ?string $remarks): Destination
    {
        return DB::transaction(function () use ($reviewer, $spot, $outcome, $remarks): Destination {
            $from = $spot->status;

            $spot->forceFill([
                'status' => $outcome,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_remarks' => $remarks,
            ])->save();

            $this->logger->logStatusChange(
                $outcome === DestinationStatus::Active ? 'tourist_spot.approved' : 'tourist_spot.rejected',
                $spot,
                ($outcome === DestinationStatus::Active ? 'Published ' : 'Sent back ').$spot->name,
                $from->value,
                $outcome->value,
            );

            // Whoever sent it in is waiting on this answer, and is not sitting
            // on the site refreshing the page.
            $author = $spot->creator;

            if ($author !== null && $author->isBarangayOfficial()) {
                $this->notifier->send($author, new TouristSpotDecided($spot, $outcome));
            }

            return $spot;
        });
    }

    /**
     * Nobody at the office is watching a queue that is usually empty, so a
     * submission that arrives unannounced can sit for weeks.
     */
    private function tellTheOffice(Destination $spot, User $official): void
    {
        $office = User::query()
            ->withRole(UserRole::SuperAdmin)
            ->where('status', 'active')
            ->get();

        foreach ($office as $admin) {
            $this->notifier->send($admin, new TouristSpotSubmitted($spot, $official));
        }
    }
}
