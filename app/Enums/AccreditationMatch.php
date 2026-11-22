<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * What the accreditation check found for one resort application.
 *
 * NONE OF THESE IS A DECISION. The office approves or rejects; this only
 * says whether the applicant appears on the uploaded list, which is one
 * input to that decision and not a substitute for it. Listed is not
 * approval — the wording on the review screen says so in as many words,
 * because an indicator that looks like a verdict will be read as one.
 */
enum AccreditationMatch: string
{
    use EnumHelpers;

    /** Found, with enough agreeing details to be confident. */
    case Listed = 'listed';

    /** Nothing on the list resembles this applicant. */
    case NotListed = 'not_listed';

    /**
     * Something close was found, but not close enough to assert.
     *
     * This is the case the whole design exists to produce. Two resorts in
     * one barangay with similar names is ordinary in Dasol, and an
     * automatic match on a near miss would quietly tell the office an
     * applicant is accredited when a different business is.
     */
    case NeedsReview = 'needs_review';

    public function label(): string
    {
        return match ($this) {
            self::Listed => 'Listed',
            self::NotListed => 'Not listed',
            self::NeedsReview => 'Needs review',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Listed => 'text-bg-success',
            self::NotListed => 'text-bg-danger',
            self::NeedsReview => 'text-bg-warning',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Listed => 'patch-check',
            self::NotListed => 'patch-question',
            self::NeedsReview => 'exclamation-triangle',
        };
    }

    /** The sentence under the badge on the review screen. */
    public function explanation(): string
    {
        return match ($this) {
            self::Listed => 'A matching record was found in the uploaded MTO '
                .'Accredited Resorts List. This is not an approval — verify the '
                .'submitted documents before deciding.',
            self::NotListed => 'No matching record was found in the MTO Accredited '
                .'Resorts List. The application needs further review, and the list '
                .'may simply be out of date.',
            self::NeedsReview => 'A similar record was found, but the details do not '
                .'agree closely enough to call it a match. Compare it against the '
                .'application by hand.',
        };
    }
}
