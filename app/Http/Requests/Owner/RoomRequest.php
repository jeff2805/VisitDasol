<?php

namespace App\Http\Requests\Owner;

use App\Enums\RoomType;
use App\Models\Room;
use App\Services\AvailabilityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Adding or editing a room type.
 *
 * resort_id and status are not here: the controller takes the resort from
 * the signed-in owner, and status changes through its own action.
 */
class RoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against RoomPolicy.
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        if (is_string($this->input('name'))) {
            $clean['name'] = Str::squish($this->input('name'));
        }

        /*
         * An unticked checkbox sends nothing at all, which is how a
         * switched-off discount has to reach the rules below: absent
         * would otherwise read as "leave it alone" and a policy could
         * never be turned back off.
         */
        foreach (['child_discount_enabled', 'pwd_discount_enabled'] as $flag) {
            $clean[$flag] = $this->boolean($flag);
        }

        $this->merge($clean);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $resortId = $this->user()->resort()->first()?->id;

        return [
            'name' => [
                'required', 'string', 'min:2', 'max:120',
                Rule::unique('rooms', 'name')
                    ->where('resort_id', $resortId)
                    ->ignore($this->route('room')?->id),
            ],
            'room_type' => ['required', Rule::in(RoomType::values())],
            'description' => ['nullable', 'string', 'max:2000'],
            'capacity' => ['required', 'integer', 'min:1', 'max:30'],
            'bed_count' => ['required', 'integer', 'min:1', 'max:30'],
            'price_per_night' => ['required', 'numeric', 'min:1', 'max:1000000'],

            /*
             * Blank means the room is not sold that way, which is why
             * neither is required and neither falls back to the nightly
             * rate. A room priced for a day tour by accident would be
             * advertising something the owner never agreed to run.
             */
            'day_tour_price' => ['nullable', 'numeric', 'min:1', 'max:1000000'],
            'night_tour_price' => ['nullable', 'numeric', 'min:1', 'max:1000000'],

            'total_rooms' => ['required', 'integer', 'min:1', 'max:500'],

            /*
             * Discount policy. The percentage is only required once the
             * policy is switched on, so an owner who leaves both off is
             * never asked for a number they do not have.
             */
            'child_discount_enabled' => ['boolean'],
            'child_discount_percent' => [
                'exclude_if:child_discount_enabled,false',
                'required', 'integer', 'min:1', 'max:100',
            ],
            'child_discount_min_age' => [
                'exclude_if:child_discount_enabled,false',
                'required', 'integer', 'min:0', 'max:17',
            ],
            'child_discount_max_age' => [
                'exclude_if:child_discount_enabled,false',
                'required', 'integer', 'min:0', 'max:17',
                'gte:child_discount_min_age',
            ],

            'pwd_discount_enabled' => ['boolean'],
            'pwd_discount_percent' => [
                'exclude_if:pwd_discount_enabled,false',
                'required', 'integer', 'min:1', 'max:100',
            ],
        ];
    }

    /**
     * A policy that is switched off stores zeroes rather than keeping
     * the figures from the last time it was on, so a room's saved
     * values and what the booking form charges can never disagree.
     *
     * @return array<string, mixed>
     */
    public function roomAttributes(): array
    {
        $validated = $this->safe()->except([
            'child_discount_percent',
            'child_discount_min_age',
            'child_discount_max_age',
            'pwd_discount_percent',
        ]);

        $validated['child_discount_percent'] = $this->boolean('child_discount_enabled')
            ? (int) $this->validated('child_discount_percent')
            : 0;

        $validated['child_discount_min_age'] = $this->boolean('child_discount_enabled')
            ? (int) $this->validated('child_discount_min_age')
            : 0;

        $validated['child_discount_max_age'] = $this->boolean('child_discount_enabled')
            ? (int) $this->validated('child_discount_max_age')
            : 12;

        $validated['pwd_discount_percent'] = $this->boolean('pwd_discount_enabled')
            ? (int) $this->validated('pwd_discount_percent')
            : 0;

        return $validated;
    }

    /**
     * Inventory may never fall below what guests already hold.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $room = $this->route('room');

            if (! $room instanceof Room || $validator->errors()->has('total_rooms')) {
                return;
            }

            $committed = app(AvailabilityService::class)->peakUnitsCommittedAhead($room);

            if ((int) $this->input('total_rooms') < $committed) {
                $validator->errors()->add(
                    'total_rooms',
                    "Guests already hold {$committed} ".str('unit')->plural($committed)
                    .' of this room type on at least one upcoming night. Cancel or complete those reservations first.'
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
            'name.unique' => 'You already have a room type with this name.',
            'capacity.min' => 'A room must take at least one guest.',
            'bed_count.required' => 'Enter how many beds are in one of these rooms.',
            'bed_count.min' => 'A room must have at least one bed.',
            'price_per_night.min' => 'Enter the nightly rate in pesos.',
            'total_rooms.min' => 'Enter how many rooms of this type you have.',

            'child_discount_percent.required' =>
                'Enter how much children get off, or switch the child discount back off.',
            'child_discount_percent.min' =>
                'A discount of nothing is the same as no discount. Enter 1% or more.',
            'child_discount_min_age.required' =>
                'Enter the youngest age this discount covers. Put 0 for newborns.',
            'child_discount_max_age.required' =>
                'Enter the oldest age this discount covers.',
            'child_discount_max_age.gte' =>
                'The oldest age cannot be younger than the youngest age.',

            'pwd_discount_percent.required' =>
                'Enter how much PWD guests get off, or switch the PWD discount back off.',
            'pwd_discount_percent.min' =>
                'A discount of nothing is the same as no discount. Enter 1% or more.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'room_type' => 'room category',
            'price_per_night' => 'rate per night',
            'total_rooms' => 'number of units',
            'bed_count' => 'number of beds',
            'child_discount_percent' => 'child discount',
            'child_discount_min_age' => 'youngest age',
            'child_discount_max_age' => 'oldest age',
            'pwd_discount_percent' => 'PWD discount',
        ];
    }
}
