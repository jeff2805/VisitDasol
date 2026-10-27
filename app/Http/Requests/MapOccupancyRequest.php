<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The dates the map is asking about.
 *
 * Both are optional: a visitor who has named no dates is asking "who is
 * staying tonight", which is a question with an answer. When they do name
 * dates the pair has to make sense, and has to stay inside the same
 * limits the booking engine enforces — a map that reports on a stay of
 * two years is answering a question nobody can act on.
 */
class MapOccupancyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The map is public. Nothing here reads anyone's own data.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $limits = config('visitdasol.booking', []);

        return [
            'check_in' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:today',
                'before_or_equal:'.CarbonImmutable::today()
                    ->addDays((int) ($limits['max_advance_days'] ?? 365))
                    ->toDateString(),
            ],

            'check_out' => [
                'nullable',
                'date_format:Y-m-d',
                'after:check_in',
                'required_with:check_in',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'check_in.after_or_equal' => 'Choose a check-in date from today onwards.',
            'check_in.before_or_equal' => 'That date is too far ahead to check.',
            'check_out.after' => 'The check-out date must be after the check-in date.',
            'check_out.required_with' => 'Choose a check-out date as well.',
        ];
    }

    /**
     * The nights to report on.
     *
     * Falls back to tonight, which is what "how full is it right now"
     * means: the night that starts today and ends tomorrow.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: bool}
     *         check-in, check-out, and whether the visitor chose them
     */
    public function range(): array
    {
        $today = CarbonImmutable::today();

        $checkIn = $this->filled('check_in')
            ? CarbonImmutable::parse($this->validated('check_in'))->startOfDay()
            : null;

        $checkOut = $this->filled('check_out')
            ? CarbonImmutable::parse($this->validated('check_out'))->startOfDay()
            : null;

        if ($checkIn === null || $checkOut === null || $checkOut->lte($checkIn)) {
            return [$today, $today->addDay(), false];
        }

        // Cap the span so one request cannot sweep years of reservations.
        $maxNights = (int) (config('visitdasol.booking.max_nights') ?? 30);

        if ($checkIn->diffInDays($checkOut) > $maxNights) {
            $checkOut = $checkIn->addDays($maxNights);
        }

        return [$checkIn, $checkOut, true];
    }
}
