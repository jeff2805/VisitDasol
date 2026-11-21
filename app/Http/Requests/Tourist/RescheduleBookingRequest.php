<?php

namespace App\Http\Requests\Tourist;

use App\Enums\BookingType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * New dates for a request the resort has not answered yet.
 *
 * SHAPE ONLY. Whether the rooms are free on the new dates, what the stay now
 * costs, and whether the reservation may be moved at all are all
 * BookingService::reschedule()'s to decide under a row lock. A rule written
 * twice is a rule free to disagree with itself.
 *
 * A tour is one date and does not ask for a second, which is read from the
 * booking rather than from the form: the kind of stay is not the guest's to
 * change here.
 */
class RescheduleBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against BookingPolicy.
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $isTour = $this->route('booking')?->booking_type?->isTour() ?? false;

        return [
            'check_in_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'check_out_date' => [
                $isTour ? 'nullable' : 'required',
                'date_format:Y-m-d',
                ...($isTour ? [] : ['after:check_in_date']),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'check_in_date.required' => 'Choose the new arrival date.',
            'check_in_date.after_or_equal' => 'Choose an arrival date from today onwards.',
            'check_out_date.required' => 'Choose the new departure date.',
            'check_out_date.after' => 'The departure date must be after the arrival date.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'check_in_date' => 'arrival date',
            'check_out_date' => 'departure date',
        ];
    }
}
