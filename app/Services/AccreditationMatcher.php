<?php

namespace App\Services;

use App\Enums\AccreditationMatch;
use App\Enums\ResortStatus;
use App\Models\AccreditedResort;
use App\Models\Resort;
use App\Support\AccreditationKey;
use Illuminate\Support\Collection;

/**
 * Decides whether a resort applying through the portal appears on the
 * office's uploaded accreditation list.
 *
 * THE BIAS IS DELIBERATE AND ONE-SIDED. Saying "needs review" when the
 * answer was really "listed" costs the office a minute with two names
 * side by side. Saying "listed" when the record belongs to a different
 * business tells the office an applicant is accredited when they are
 * not, in a green badge, with no reason to doubt it. So a match is only
 * asserted when nothing disagrees, and every near miss is handed to a
 * person.
 *
 * Nothing here approves anything. The result is one input to a decision
 * the office still makes.
 */
class AccreditationMatcher
{
    /**
     * How alike two names must be before a person is asked to look.
     *
     * Below this they are different businesses and the office is not
     * troubled. This only ever opens a review — it can never on its own
     * produce a match — so it is set low enough to catch misspellings.
     */
    private const SIMILAR_ENOUGH_TO_ASK = 80.0;

    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * The finding, without writing anything.
     *
     * @return array{status: AccreditationMatch, accredited: ?AccreditedResort, note: string}
     */
    public function check(Resort $resort): array
    {
        $nameKey = AccreditationKey::name($resort->name);
        $barangayKey = AccreditationKey::barangay($resort->barangay);
        $ownerKey = AccreditationKey::person($this->ownerName($resort));

        if ($nameKey === '') {
            return $this->result(AccreditationMatch::NeedsReview, null, 'the application has no resort name to match on');
        }

        $list = AccreditedResort::all();

        $exact = $list->filter(fn (AccreditedResort $row): bool => $row->name_key === $nameKey);

        if ($exact->isNotEmpty()) {
            return $this->fromExactMatches($exact, $barangayKey, $ownerKey);
        }

        return $this->fromNearMisses($list, $nameKey, $barangayKey, $ownerKey);
    }

    /**
     * Makes the finding and records it on the application.
     *
     * The result is written down rather than worked out when the review
     * screen is drawn, because the office replaces its list as
     * accreditations are renewed, and an application decided in March
     * must still show what was true in March.
     */
    public function apply(Resort $resort, bool $log = true): Resort
    {
        $found = $this->check($resort);

        $resort->accreditation_match_status = $found['status']->value;
        $resort->matched_accredited_resort_id = $found['accredited']?->id;

        // Copied, not only referenced: the number has to stay readable
        // after the next upload replaces the row it came from.
        $resort->matched_accreditation_no = $found['accredited']?->accreditation_no;
        $resort->accreditation_match_note = $found['note'];
        $resort->accreditation_checked_at = now();
        $resort->save();

        if ($log) {
            $this->logger->log(
                'resort.accreditation_checked',
                $resort,
                "Accreditation check for {$resort->name}: {$found['status']->label()}",
                [
                    'status' => $found['status']->value,
                    'accredited_resort_id' => $found['accredited']?->id,
                    'note' => $found['note'],
                ],
            );
        }

        return $resort;
    }

    /**
     * Re-checks every application still waiting for a decision.
     *
     * Run after an upload. An office that has just added a resort to the
     * list expects that resort's pending application to stop saying "not
     * listed" — without this they would have to ask the owner to apply
     * again. Applications already approved or rejected are left exactly
     * as they were: their finding is part of the record of a decision
     * that has been made.
     *
     * @return int how many were re-checked
     */
    public function recheckPending(): int
    {
        $pending = Resort::query()
            ->where('status', ResortStatus::Pending->value)
            ->with('owner')
            ->get();

        foreach ($pending as $resort) {
            $this->apply($resort, log: false);
        }

        return $pending->count();
    }

    /* Deciding ----------------------------------------------------------- */

    /**
     * @param  Collection<int, AccreditedResort>  $exact
     * @return array{status: AccreditationMatch, accredited: ?AccreditedResort, note: string}
     */
    private function fromExactMatches(Collection $exact, string $barangayKey, string $ownerKey): array
    {
        if ($exact->count() > 1) {
            /*
             * Several accredited resorts share this name. The barangay
             * is what tells them apart; if exactly one sits in the right
             * one, that is the record. If none or several do, a person
             * has to choose — this code must not.
             */
            $inBarangay = $barangayKey === ''
                ? collect()
                : $exact->filter(fn (AccreditedResort $row): bool => $row->barangay_key === $barangayKey);

            if ($inBarangay->count() !== 1) {
                return $this->result(
                    AccreditationMatch::NeedsReview,
                    null,
                    $exact->count().' accredited resorts share this name, and the barangay does not single one out',
                );
            }

            $exact = $inBarangay;
        }

        $row = $exact->first();

        // A barangay on both sides that disagrees is a different
        // business with the same name, which is ordinary here.
        if ($barangayKey !== '' && $row->barangay_key !== null && $row->barangay_key !== $barangayKey) {
            return $this->result(
                AccreditationMatch::NeedsReview,
                $row,
                'the name matches, but the list has it in '.$row->barangay.' and the application says '.$this->spell($barangayKey),
            );
        }

        if (! $row->isCurrent()) {
            return $this->result(
                AccreditationMatch::NeedsReview,
                $row,
                'the name matches accreditation '.($row->accreditation_no ?: 'on the list').', but '.$row->lapseReason(),
            );
        }

        /*
         * A name and barangay that agree is enough to assert. The owner
         * name is not required to agree: the person registering on the
         * portal is often a manager or a son running the business, while
         * the list carries whoever signed the accreditation. Demanding
         * it would send almost every true match to review and the office
         * would stop reading the badge.
         */
        $note = $barangayKey !== '' && $row->barangay_key === $barangayKey
            ? 'the resort name and barangay both match this record'
            : 'the resort name matches this record';

        if ($ownerKey !== '' && $row->owner_key !== null && $row->owner_key !== $ownerKey) {
            $note .= ', though the list names '.$row->owner_name.' as the owner';
        }

        return $this->result(AccreditationMatch::Listed, $row, $note);
    }

    /**
     * @param  Collection<int, AccreditedResort>  $list
     * @return array{status: AccreditationMatch, accredited: ?AccreditedResort, note: string}
     */
    private function fromNearMisses(Collection $list, string $nameKey, string $barangayKey, string $ownerKey): array
    {
        $closest = null;
        $best = 0.0;

        foreach ($list as $row) {
            similar_text($nameKey, (string) $row->name_key, $percent);

            /*
             * A matching barangay or owner makes a weaker name worth
             * asking about — a resort that has renamed itself is still
             * in the same place, run by the same family. The bonus only
             * ever raises something to "needs review"; it can never
             * produce a match.
             */
            if ($barangayKey !== '' && $row->barangay_key === $barangayKey) {
                $percent += 10;
            }

            if ($ownerKey !== '' && $row->owner_key === $ownerKey) {
                $percent += 10;
            }

            if ($percent > $best) {
                $best = $percent;
                $closest = $row;
            }
        }

        if ($closest === null || $best < self::SIMILAR_ENOUGH_TO_ASK) {
            return $this->result(
                AccreditationMatch::NotListed,
                null,
                'nothing on the list resembles this resort',
            );
        }

        return $this->result(
            AccreditationMatch::NeedsReview,
            $closest,
            'the closest record on the list is "'.$closest->resort_name.'", which is similar but not the same',
        );
    }

    /**
     * @return array{status: AccreditationMatch, accredited: ?AccreditedResort, note: string}
     */
    private function result(AccreditationMatch $status, ?AccreditedResort $accredited, string $note): array
    {
        return ['status' => $status, 'accredited' => $accredited, 'note' => $note];
    }

    private function ownerName(Resort $resort): string
    {
        $owner = $resort->owner;

        return $owner === null ? '' : trim($owner->first_name.' '.$owner->last_name);
    }

    /** A normalised key back in something readable, for a sentence. */
    private function spell(string $key): string
    {
        return ucwords($key);
    }
}
