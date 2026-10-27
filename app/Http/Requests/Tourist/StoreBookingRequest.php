<?php

namespace App\Http\Requests\Tourist;

use App\Enums\BookingType;
use App\Enums\PaymentPreference;
use App\Enums\VisitorOrigin;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends FormRequest
{
    /** Looked up twice otherwise: once to fill in the times, once to check capacity. */
    private ?Room $room = null;

    private bool $roomLoaded = false;

    public function authorize(): bool
    {
        // The controller authorizes against BookingPolicy.
        return true;
    }

    /** The selected room, or null when the form named one that is not there. */
    private function room(): ?Room
    {
        if (! $this->roomLoaded) {
            $this->roomLoaded = true;

            $this->room = Room::query()
                ->with('resort:id,check_in_time,check_out_time')
                ->find($this->input('room'));
        }

        return $this->room;
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        $first = is_string($this->input('contact_first_name'))
            ? Str::squish($this->input('contact_first_name'))
            : null;

        $last = is_string($this->input('contact_last_name'))
            ? Str::squish($this->input('contact_last_name'))
            : null;

        if ($first !== null || $last !== null) {
            $clean['contact_first_name'] = $first;
            $clean['contact_last_name'] = $last;

            $clean['contact_name'] = Str::squish(
                trim(($first ?? '') . ' ' . ($last ?? ''))
            );
        } elseif (is_string($this->input('contact_name'))) {
            $clean['contact_name'] = Str::squish(
                $this->input('contact_name')
            );
        }

        if (is_string($this->input('contact_phone'))) {
            $clean['contact_phone'] = preg_replace(
                '/[\s\-().]/',
                '',
                $this->input('contact_phone')
            );
        }

        if (is_string($this->input('contact_email'))) {
            $clean['contact_email'] = Str::lower(
                trim($this->input('contact_email'))
            );
        }

        /*
         * Guest composition. The form always sends all three boxes, but
         * a party of nobody's children and nobody's PWD guests is the
         * common case, so an absent count reads as none rather than as
         * a mistake.
         */
        $children = $this->count('children') ?? 0;
        $pwdGuests = $this->count('pwd_guests') ?? 0;

        /*
         * Adults are what is left of the party once the children are
         * taken out, so a caller that names only a party size still
         * describes a valid one.
         */
        $adults = $this->count('adults')
            ?? max(1, ($this->count('guests') ?? 1) - $children);

        $clean['adults'] = $adults;
        $clean['children'] = $children;
        $clean['pwd_guests'] = $pwdGuests;

        // PWD guests are already among the adults and children above,
        // and must never be counted into the party a second time.
        $clean['guests'] = $adults + $children;

        /*
         * Arrival and departure times. The resort's own standard hours
         * when the guest did not pick: a request with no time on it is
         * a guest arriving when everybody else does, not a request to
         * be refused.
         */
        /*
         * A tour is one date to the guest, so the form sends one. The
         * engine needs the day after to hold the room, and works that
         * out itself — but check_out_date is still validated below, so
         * it is filled in here rather than left for the rules to reject.
         */
        $type = BookingType::tryFrom((string) $this->input('booking_type'))
            ?? BookingType::Overnight;

        /*
         * Only an absent kind is filled in. A kind that was sent and is
         * not one of ours is left exactly as it came so the rules can
         * refuse it: quietly turning it into an overnight stay would
         * book a guest a night they did not ask for and charge them the
         * nightly rate for it.
         */
        if (! $this->filled('booking_type')) {
            $clean['booking_type'] = $type->value;
        }

        if ($type->isTour() && $this->filled('check_in_date')) {
            try {
                $clean['check_out_date'] = CarbonImmutable::parse(
                    $this->input('check_in_date')
                )->addDay()->toDateString();
            } catch (\Throwable) {
                // A check-in date that will not parse is refused by the
                // rules; nothing useful can be derived from it here.
            }
        }

        $resort = $this->room()?->resort;

        /*
         * A tour keeps its own hours, the same as it does in
         * BookingService. The resort's standard 2 p.m. check-in belongs
         * to somebody sleeping over; handed to a day group it would cost
         * them most of the day they paid for.
         */
        [$defaultIn, $defaultOut] = $type->defaultHours();

        $clean['check_in_time'] = $this->time('check_in_time')
            ?? $this->standardTime(
                $type->isTour() ? null : $resort?->check_in_time,
                $defaultIn
            );

        $clean['check_out_time'] = $this->time('check_out_time')
            ?? $this->standardTime(
                $type->isTour() ? null : $resort?->check_out_time,
                $defaultOut
            );

        $this->merge($clean);
    }

    /**
     * A whole-number count from the form, or null when the field was
     * left out or filled in with something that is not a number. Null
     * and zero have to stay different: one is an omission the request
     * can fill in, the other is an answer.
     */
    private function count(string $field): ?int
    {
        $value = $this->input($field);

        if (! is_numeric($value) || (string) $value === '') {
            return null;
        }

        return (int) $value;
    }

    /** A 24-hour HH:MM from the form, or null if it is not one. */
    private function time(string $field): ?string
    {
        $value = $this->input($field);

        return is_string($value)
            && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)
                ? $value
                : null;
    }

    /** The resort's own hour, which it stores with seconds on it. */
    private function standardTime(?string $stored, string $fallback): string
    {
        return $stored === null
            ? $fallback
            : substr($stored, 0, 5);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $limits = config('visitdasol.booking', []);

        return [
            'room' => [
                'required',
                'integer',
                'exists:rooms,id',
            ],

            'check_in_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:today',
                'before_or_equal:' . now()
                    ->addDays((int) ($limits['max_advance_days'] ?? 365))
                    ->toDateString(),
            ],

            'check_out_date' => [
                'required',
                'date_format:Y-m-d',
                'after:check_in_date',
            ],

            /*
             * Absent means overnight — what every booking was before
             * tours existed, and what a caller that does not know about
             * them still means.
             */
            'booking_type' => ['nullable', Rule::enum(BookingType::class)],

            'check_in_time' => [
                'required',
                'date_format:H:i',
            ],

            'check_out_time' => [
                'required',
                'date_format:H:i',
            ],

            // Guest composition.
            'adults' => [
                'required',
                'integer',
                'min:1',
                'max:60',
            ],

            'children' => [
                'required',
                'integer',
                'min:0',
                'max:59',
            ],

            'pwd_guests' => [
                'required',
                'integer',
                'min:0',
                'max:60',
            ],

            // Derived in prepareForValidation().
            'guests' => [
                'required',
                'integer',
                'min:1',
                'max:60',
            ],

            'rooms_booked' => [
                'required',
                'integer',
                'min:1',
                'max:20',
            ],

            'guests_male' => [
                'required',
                'integer',
                'min:0',
                'max:60',
            ],

            'guests_female' => [
                'required',
                'integer',
                'min:0',
                'max:60',
            ],

            'origin' => [
                'required',
                Rule::enum(VisitorOrigin::class),
            ],

            'contact_first_name' => [
                'nullable',
                'required_with:contact_last_name',
                'string',
                'max:75',
            ],

            'contact_last_name' => [
                'nullable',
                'required_with:contact_first_name',
                'string',
                'max:75',
            ],

            'contact_name' => [
                'required',
                'string',
                'max:150',
            ],

            'contact_phone' => [
                'required',
                'string',
                'regex:/^\+?[0-9]{7,15}$/',
            ],

            'contact_email' => [
                'nullable',
                'email:rfc',
                'max:255',
            ],

            'special_requests' => [
                'nullable',
                'string',
                'max:1000',
            ],

            /*
             * What the guest asked for besides the room, as
             * extra id => quantity.
             *
             * NO PRICE IS ACCEPTED HERE. BookingExtraService reads the
             * rate from the resort's own listing and snapshots it, so
             * a posted price is simply never looked at. The ids are
             * checked against the resort in the service too — one from
             * another resort is dropped rather than refusing the
             * booking, because a guest should not lose a room over a
             * kayak.
             */
            'extras' => ['nullable', 'array', 'max:20'],
            'extras.*' => ['integer', 'min:1', 'max:100'],

            'payment_preference' => [
                'nullable',
                Rule::in(
                    array_column(
                        PaymentPreference::available(),
                        'value'
                    )
                ),
            ],
        ];
    }

    /**
     * Validate guest composition and selected room capacity.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $fields = [
                'room',
                'adults',
                'children',
                'pwd_guests',
                'guests',
                'rooms_booked',
                'guests_male',
                'guests_female',
            ];

            foreach ($fields as $field) {
                if ($validator->errors()->has($field)) {
                    return;
                }
            }

            $adults = (int) $this->input('adults');
            $children = (int) $this->input('children');
            $pwdGuests = (int) $this->input('pwd_guests');
            $guests = (int) $this->input('guests');
            $roomsBooked = (int) $this->input('rooms_booked');

            // PWD guests are a subset of adults and children.
            if ($pwdGuests > $guests) {
                $validator->errors()->add(
                    'pwd_guests',
                    'PWD guests cannot exceed the total number of guests.'
                );
            }

            // Verify the derived total.
            if ($guests !== ($adults + $children)) {
                $validator->errors()->add(
                    'guests',
                    'Total guests must equal adults plus children.'
                );
            }

            // Male and female counts must equal the total.
            $male = (int) $this->input('guests_male');
            $female = (int) $this->input('guests_female');
            $stated = $male + $female;

            if ($stated !== $guests) {
                $validator->errors()->add(
                    'guests_male',
                    "Male and female counts add up to {$stated}, "
                    . "but the total number of guests is {$guests}."
                );
            }

            // Validate the requested capacity against the selected room.
            $room = $this->room();

            if (! $room) {
                return;
            }

            $capacityPerRoom = (int) $room->capacity;
            $totalCapacity = $capacityPerRoom * $roomsBooked;

            if ($capacityPerRoom < 1) {
                $validator->errors()->add(
                    'room',
                    'This room does not have a valid guest capacity.'
                );

                return;
            }

            if ($guests > $totalCapacity) {
                $validator->errors()->add(
                    'guests',
                    "This booking accommodates up to {$totalCapacity} guests "
                    . "for {$roomsBooked} selected room(s). "
                    . 'Choose more rooms or reduce the guest count.'
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'check_in_date.after_or_equal' =>
                'Choose a check-in date from today onwards.',

            'check_in_date.before_or_equal' =>
                'That date is too far ahead to book online.',

            'check_out_date.after' =>
                'The check-out date must be after the check-in date.',

            'check_in_time.required' =>
                'Please select your check-in time.',

            'check_in_time.date_format' =>
                'Please enter a valid check-in time.',

            'check_out_time.required' =>
                'Please select your check-out time.',

            'check_out_time.date_format' =>
                'Please enter a valid check-out time.',

            'adults.required' =>
                'Enter the number of adults.',

            'adults.min' =>
                'At least one adult is required.',

            'children.required' =>
                'Enter the number of children. Put 0 if there are none.',

            'pwd_guests.required' =>
                'Enter the number of PWD guests. Put 0 if there are none.',

            'guests.required' =>
                'The total number of guests is required.',

            'guests_male.required' =>
                'Enter the number of male guests. Put 0 if there are none.',

            'guests_female.required' =>
                'Enter the number of female guests. Put 0 if there are none.',

            'contact_phone.regex' =>
                'Enter a number the resort can reach you on, '
                . 'for example 09171234567.',

            'contact_name.required' =>
                'Enter the first and last name the resort should ask for.',

            'contact_email.email' =>
                'Enter an address the resort can reply to, '
                . 'for example you@example.com.',

            'room.exists' =>
                'Choose a valid room type.',

            'rooms_booked.min' =>
                'You must book at least one room.',

            'origin.required' =>
                'Choose where you are travelling from.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'check_in_date' => 'check-in date',
            'check_out_date' => 'check-out date',
            'check_in_time' => 'check-in time',
            'check_out_time' => 'check-out time',
            'rooms_booked' => 'number of rooms',
            'contact_name' => 'contact name',
            'contact_phone' => 'contact number',
            'guests' => 'total guests',
            'adults' => 'adults',
            'children' => 'children',
            'pwd_guests' => 'PWD guests',
            'guests_male' => 'number of male guests',
            'guests_female' => 'number of female guests',
            'origin' => 'where you are travelling from',
        ];
    }
}
