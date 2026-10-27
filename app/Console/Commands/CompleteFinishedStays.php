<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\BookingStatusManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Closes off stays that have finished and that nobody marked completed.
 *
 * A confirmed reservation whose dates have passed is otherwise stuck reading
 * "Confirmed" for ever: it clutters the owner's list, it overstates upcoming
 * arrivals on the dashboard, and the guest never gets a thank-you.
 *
 * Counted to the guest's checkout HOUR, not merely their checkout date. A
 * stay ending at noon is not over at three in the morning, and closing it
 * then would thank a guest who has not left yet.
 *
 * The grace period defaults to nothing, because a stay being over is what
 * finishing means. Raise visitdasol.booking.auto_complete_grace_hours to
 * give owners a window to handle a no-show differently first.
 */
class CompleteFinishedStays extends Command
{
    protected $signature = 'visitdasol:complete-stays
                            {--grace= : Hours to wait after check-out before closing a stay}
                            {--dry-run : List what would change without changing it}';

    protected $description = 'Mark confirmed stays completed once they have finished';

    public function handle(BookingStatusManager $status): int
    {
        $grace = max(0, (int) ($this->option('grace')
            ?? config('visitdasol.booking.auto_complete_grace_hours', 0)));

        $dryRun = (bool) $this->option('dry-run');
        $now = CarbonImmutable::now(config('app.timezone'));

        /*
         * The date is narrowed in SQL so the job never reads the whole
         * table, and the hour is then checked in PHP. Tomorrow's date is
         * the cheap upper bound: a stay checking out today may or may not
         * be over depending on its hour, and only checkoutAt() knows.
         */
        $finished = Booking::query()
            ->where('status', BookingStatus::Confirmed->value)
            ->where('check_out_date', '<=', $now->addDay()->toDateString())
            ->with(['tourist', 'resort.owner'])
            ->get()
            ->filter(function (Booking $booking) use ($status, $now, $grace): bool {
                $checkoutAt = $status->checkoutAt($booking);

                return $checkoutAt !== null
                    && $now->gt($checkoutAt->addHours($grace));
            });

        if ($finished->isEmpty()) {
            $this->info('No finished stays are waiting to be closed.');

            return self::SUCCESS;
        }

        $closed = 0;

        foreach ($finished as $booking) {
            if ($dryRun) {
                $this->line("  would close {$booking->booking_reference} (checked out "
                    .$status->checkoutAt($booking)?->format('Y-m-d g:i A').')');

                continue;
            }

            try {
                // Through the manager, so the transition is checked, written to
                // the audit trail, and the guest is thanked, exactly as when an
                // owner does it by hand.
                $status->complete($booking);
                $closed++;
            } catch (Throwable $e) {
                $this->warn("  skipped {$booking->booking_reference}: {$e->getMessage()}");
            }
        }

        $this->info($dryRun
            ? $finished->count().' stay(s) would be closed.'
            : "Closed {$closed} finished ".Str::plural('stay', $closed).'.');

        return self::SUCCESS;
    }
}
