<?php

namespace App\Http\Requests\Owner;

use App\Enums\BookingType;
use App\Enums\VisitorOrigin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A walk-in the resort is writing down.
 *
 * SHAPE ONLY. Whether the room is free, whether the party fits, and what the
 * stay costs are all WalkInBookingService's to decide under a row lock — this
 * refuses what is plainly wrong before a query is run, and nothing more. A
 * rule duplicated here and there is a rule free to disagree with itself.
 *
 * The tourism columns are deliberately NOT required to save. A resort should
 * be able to write a guest down at a busy desk and finish the paperwork
 * later; WalkInSubmissionService is what refuses to SEND an incomplete
 * record, which is the moment it actually matters.
 */
class StoreWalkInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against BookingPolicy.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tour = in_array($this->input('booking_type'), [
            BookingType::DayTour->value,
            BookingType::NightTour->value,
        ], true);

        return [
            'room' => ['required', 'integer', 'exists:rooms,id'],
            'booking_type' => ['nullable', Rule::in(BookingType::values())],

            // No after_or_equal:today. A walk-in is often written down once
            // the guest has already arrived; see WalkInBookingService.
            'check_in_date' => ['required', 'date_format:Y-m-d'],

            // A tour is one date, so the form does not ask for the second.
            'check_out_date' => [
                $tour ? 'nullable' : 'required',
                'date_format:Y-m-d',
                ...($tour ? [] : ['after:check_in_date']),
            ],

            'check_in_time' => ['nullable', 'date_format:H:i'],
            'check_out_time' => ['nullable', 'date_format:H:i'],

            /* The party ---------------------------------------------- */

            'adults' => ['required', 'integer', 'min:1', 'max:99'],
            'children' => ['nullable', 'integer', 'min:0', 'max:99'],
            'pwd_guests' => ['nullable', 'integer', 'min:0', 'max:99'],
            'rooms_booked' => ['required', 'integer', 'min:1', 'max:50'],

            /* The guest ---------------------------------------------- */

            'contact_name' => ['required', 'string', 'max:150'],
            'contact_phone' => ['required', 'string', 'max:20'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'guest_address' => ['nullable', 'string', 'max:255'],
            'special_requests' => ['nullable', 'string', 'max:1000'],

            /* What the Department of Tourism asks for ---------------- */

            'guests_male' => ['nullable', 'integer', 'min:0', 'max:99'],
            'guests_female' => ['nullable', 'integer', 'min:0', 'max:99'],
            'origin' => ['nullable', Rule::in(VisitorOrigin::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'check_out_date.after' => 'The check-out date must be after the check-in date.',
            'contact_name.required' => "Write the guest's name as they gave it.",
            'contact_phone.required' => 'A contact number is needed in case the resort has to reach them.',
            'adults.min' => 'A booking needs at least one adult.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'room' => 'room',
            'contact_name' => 'guest name',
            'contact_phone' => 'contact number',
            'guest_address' => 'address',
            'guests_male' => 'male guests',
            'guests_female' => 'female guests',
            'origin' => 'travelling from',
            'pwd_guests' => 'PWD guests',
            'rooms_booked' => 'number of rooms',
        ];
    }

    /** Blank number boxes mean none, not an error. */
    protected function prepareForValidation(): void
    {
        foreach (['children', 'pwd_guests'] as $field) {
            if ($this->input($field) === '' || $this->input($field) === null) {
                $this->merge([$field => 0]);
            }
        }
    }
}
