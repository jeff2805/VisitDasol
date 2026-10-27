<?php

namespace App\Services;

use App\Enums\ResortStatus;
use App\Models\Resort;

/**
 * What the Municipal Tourism Office checks before publishing a listing.
 *
 * The review screen shows these, and the approve action refuses to run
 * while any required item fails, so the rules cannot be skipped by
 * sending the request straight to the URL.
 */
class ResortApprovalChecklist
{
    /**
     * @return list<array{label: string, missing: string, passed: bool, required: bool, hint: string}>
     */
    public function for(Resort $resort): array
    {
        $bounds = config('visitdasol.map.bounds');
        $insideDasol = $resort->latitude >= $bounds['south']
            && $resort->latitude <= $bounds['north']
            && $resort->longitude >= $bounds['west']
            && $resort->longitude <= $bounds['east'];

        return [
            [
                'label' => 'The owner account is active',
                'missing' => 'the owner account is not active',
                'passed' => $resort->owner?->isResortOwner() && $resort->owner->isActive(),
                'required' => true,
                'hint' => 'Approve the owner application first, or reactivate the account.',
            ],
            [
                'label' => 'A cover photo has been uploaded',
                'missing' => 'no cover photo has been uploaded',
                'passed' => filled($resort->main_image),
                'required' => true,
                'hint' => 'Ask the owner to add a cover photo before the listing goes public.',
            ],
            [
                'label' => 'The map pin is inside Dasol',
                'missing' => 'the map pin is outside Dasol',
                'passed' => $insideDasol,
                'required' => true,
                'hint' => 'Ask the owner to move the pin, or correct it yourself in the edit screen.',
            ],
            [
                'label' => 'At least one room type with a rate',
                'missing' => 'no room types have been added',
                'passed' => $resort->rooms()->exists(),
                'required' => false,
                'hint' => 'Guests can view the listing, but cannot book until rooms are added.',
            ],
            [
                'label' => 'Contact number on file',
                'missing' => 'no contact number is on file',
                'passed' => filled($resort->contact_number),
                'required' => false,
                'hint' => 'Visitors may need to reach the resort directly.',
            ],
        ];
    }

    /**
     * Required items that are still failing.
     *
     * @return list<string>
     */
    public function blockers(Resort $resort): array
    {
        return collect($this->for($resort))
            ->filter(fn (array $item) => $item['required'] && ! $item['passed'])
            ->pluck('missing')
            ->values()
            ->all();
    }

    public function canApprove(Resort $resort): bool
    {
        return $resort->status === ResortStatus::Pending && $this->blockers($resort) === [];
    }
}
