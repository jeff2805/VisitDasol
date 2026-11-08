<?php

namespace App\Http\Requests\Tourist;

use App\Enums\BookingType;
use App\Enums\PaymentPreference;
use App\Enums\VisitorOrigin;
use App\Models\ResortManualPaymentAccount;
use App\Models\Room;
use App\Services\BookingService;
use App\Services\Payments\PaymentPolicyService;
use App\Services\Payments\PaymentTerms;
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

    /**
     * The selected resort's GCash account, if it has a live one.
     *
     * Goes through room(), which is loaded once per request, so this
     * costs a single query and only where a resort has an account to
     * find. What it is for is in the payment_preference rule below.
     */
    private function manualAccount(): ?ResortManualPaymentAccount
    {
        return $this->room()?->resort
            ?->manualAccount()
            ->where('active', true)
            ->first();
    }

    /**
     * What this resort asks for up front, for the stay being requested.
     *
     * THE THIRD PLACE THAT ASKS, AND IT USED TO ASK WITHOUT THEM.
     *
     * Three things decide which payment methods exist: the booking form
     * draws them, this rule accepts them, and BookingService refuses
     * anything else. The first and last pass the stay's real terms; this
     * one passed only the GCash account, so it was answering a different
     * question from the other two.
     *
     * While "Pay at the resort" was withdrawn outright that made no
     * visible difference. It does now: the method is offered exactly
     * when the terms ask for nothing up front, so a rule that cannot see
     * the terms would reject the one option the form had shown.
     *
     * Null when the request has not named a room or usable dates. The
     * rules for those fields refuse it first, and the methods list then
     * falls back to what is true of the site rather than of the stay.
     */
    private function terms(): ?PaymentTerms
    {
        $room = $this->room();

        if ($room === null) {
            return null;
        }

        try {
            $checkIn = CarbonImmutable::parse($this->input('check_in_date'))->startOfDay();
            $checkOut = CarbonImmutable::parse($this->input('check_out_date'))->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if ($checkOut->lte($checkIn)) {
            return null;
        }

        $nights = (int) $checkIn->diffInDays($checkOut);
        $rooms = max(1, (int) $this->input('rooms_booked', 1));

        return app(PaymentPolicyService::class)->terms(
            $room->resort->paymentTerms(),
            app(BookingService::class)->total($room->price_per_night, $nights, $rooms),
            $checkIn,
        );
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
             * The guest's valid ID, sent with the request rather than after it.
             *
             * REQUIRED. The resort cannot check anybody in until an ID has
             * been verified, so a booking made without one was a booking that
             * could not be honoured — the guest found that out later, from
             * their reservation page, after the room had already been held
             * for them. Asking here moves that discovery to the one moment
             * the guest is still filling the form in.
             *
             * The rules are the ones IdDocumentRequest already uses — both
             * the extension and the real content type, and the size ceiling
             * from config — because this is the same document arriving by a
             * different door, and two doors with two rules is how one of
             * them ends up weaker.
             */
            'id_document' => [
                'required',
                'file',
                'mimes:'.implode(',', config('visitdasol.uploads.document_mimes')),
                'mimetypes:image/jpeg,image/png,application/pdf',
                'max:'.config('visitdasol.uploads.document_max_kb'),
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
             *
             * SAYING NO HAS TO BE POSSIBLE, AND IT WAS NOT.
             *
             * The form sends a box for every extra the resort offers.
             * An untouched one posts an empty string, which the global
             * ConvertEmptyStringsToNull turns into null — and null with
             * no "nullable" here failed "integer". Typing the 0 that
             * the placeholder shows failed "min:1". Either way a guest
             * at a resort with two extras collected two errors they
             * never asked for and could not submit at all.
             *
             * Worse, both landed on keys like "extras.7", which no
             * @error block on the page matched, so the banner counted
             * fields that nothing on the screen marked.
             *
             * nullable for the empty box, min:0 for the typed zero.
             * BookingExtraService drops both rather than booking a
             * kayak nobody wanted.
             */
            'extras' => ['nullable', 'array', 'max:20'],
            'extras.*' => ['nullable', 'integer', 'min:0', 'max:100'],

            /*
             * THE SAME QUESTION THE FORM ASKED.
             *
             * BookingController::create() offers whatever
             * PaymentPreference::available($manualGcashAccount) returns.
             * This asked available() with no account at all, so Manual
             * GCash was never on the accepted list — a resort that had
             * set up GCash showed the guest an option the server then
             * refused. Pass the same account and the two agree.
             */
            'payment_preference' => [
                'nullable',
                Rule::in(
                    array_column(
                        PaymentPreference::available($this->manualAccount(), $this->terms()),
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

            /*
             * BOTH ENDS OF THE RANGE, AND NOT WORKED OUT HERE.
             *
             * Room::partyProblem() is the one place that knows what a party
             * has to be for a room: the ceiling from rooms.capacity, the
             * floor from rooms.min_capacity where the owner set one. The
             * form asked only the ceiling, and did its own multiplication to
             * get it — so a room let as a whole could be booked by one
             * person, and the arithmetic sat in a second place waiting to
             * disagree with BookingService's.
             *
             * Both now call the model. Neither carries a capacity figure of
             * its own.
             */
            if ($capacityProblem = $room->partyProblem($guests, $roomsBooked)) {
                $validator->errors()->add(
                    (int) $room->capacity < 1 ? 'room' : 'guests',
                    $capacityProblem
                );
            }

            /*
             * More units than the resort has of this type, whatever the
             * dates. "max:20" above is a sanity bound on the form; this is
             * the real ceiling, and it names the room the guest picked.
             *
             * The dated check — how many are free on THOSE nights — stays in
             * BookingService, under the row lock, where it is the only
             * answer that can be trusted.
             */
            $units = (int) $room->total_rooms;

            if ($units > 0 && $roomsBooked > $units) {
                $validator->errors()->add(
                    'rooms_booked',
                    'The requested number of rooms exceeds available inventory. '
                    . "This resort has {$units} " . Str::plural('unit', $units)
                    . ' of this room type in total.'
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

            'id_document.required' =>
                'Attach a photo or PDF of your valid ID. '
                . 'The resort cannot check you in without one.',

            'id_document.file' =>
                'Choose a photo or PDF of your valid ID.',

            'id_document.mimes' =>
                'Upload your ID as a JPG, PNG, or PDF.',

            'id_document.mimetypes' =>
                'Upload your ID as a JPG, PNG, or PDF.',

            'id_document.max' =>
                'Your ID must be '
                . round(config('visitdasol.uploads.document_max_kb') / 1024, 1)
                . ' MB or smaller.',

            'rooms_booked.min' =>
                'You must book at least one room.',

            'origin.required' =>
                'Choose where you are travelling from.',

            /*
             * The key here is "extras.7", an id nobody outside the
             * database recognises, so the default wording named a field
             * the guest has never heard of. These say what to do
             * instead. 0 and blank are both fine now, which is why
             * neither message asks for a minimum of one.
             */
            'extras.*.integer' =>
                'Say how many you would like as a whole number, '
                . 'or leave the box empty.',

            'extras.*.min' =>
                'That cannot be less than nothing. Leave the box empty, '
                . 'or put 0.',

            'extras.*.max' =>
                'That is more than a resort can offer of one thing. '
                . 'Ask them directly for a large group.',
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
            'id_document' => 'valid ID',
        ];
    }
}
