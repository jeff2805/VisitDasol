{{-- Shared by the add and edit room screens. --}}
<div class="row g-4">
    <div class="col-lg-7">
        <section class="vd-panel p-4">
            <x-form.input name="name" label="Room type name" :value="$room->name" required maxlength="120"
                          help="For example: Seaview Deluxe, Family Cottage." />

            <x-form.select name="room_type" label="Category" :options="$roomTypes"
                           :value="$room->room_type?->value" placeholder="Choose a category" required />

            {{-- What each category means. "Deluxe" and "Premium" are the same
                 word to most people, and without this the choice is a guess —
                 which is how a ₱600 room ended up filed as a cottage. --}}
            <details class="vd-hints mb-3">
                <summary>What do these mean?</summary>
                <dl class="vd-hints__list">
                    @foreach (\App\Enums\RoomType::cases() as $type)
                        <dt>{{ $type->label() }}</dt>
                        <dd>{{ $type->hint() }}</dd>
                    @endforeach
                </dl>
            </details>

            <div class="mb-3">
                <label class="form-label" for="field-description">
                    Description <span class="text-muted-vd fw-normal small">(optional)</span>
                </label>
                <textarea class="form-control @error('description') is-invalid @enderror"
                          id="field-description" name="description" rows="4"
                          placeholder="Beds, air-conditioning, view, and anything else guests should know.">{{ old('description', $room->description) }}</textarea>
                @error('description')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </section>
    </div>

    <div class="col-lg-5">
        <section class="vd-panel p-4">
            <h2 class="h5 mb-3">Rate and capacity</h2>

            <div class="mb-3">
                <label class="form-label" for="field-price-per-night">Overnight rate</label>
                <div class="input-group has-validation">
                    <span class="input-group-text">₱</span>
                    <input type="number" class="form-control @error('price_per_night') is-invalid @enderror"
                           id="field-price-per-night" name="price_per_night" min="1" step="1" required
                           value="{{ old('price_per_night', $room->price_per_night ? (int) $room->price_per_night : '') }}">
                    @error('price_per_night')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="form-text">The price for one room, for one night.</div>
            </div>

            {{-- Day and night tours. Blank means you do not run them,
                 and the booking form then offers guests only an
                 overnight stay for this room. --}}
            <div class="row">
                <div class="col-sm-6">
                    <div class="mb-3">
                        <label class="form-label" for="field-day-tour-price">
                            Day tour <span class="text-muted-vd fw-normal small"></span>
                        </label>
                        <div class="input-group has-validation">
                            <span class="input-group-text">₱</span>
                            <input type="number" class="form-control @error('day_tour_price') is-invalid @enderror"
                                   id="field-day-tour-price" name="day_tour_price" min="1" step="1"
                                   value="{{ old('day_tour_price', $room->day_tour_price ? (int) $room->day_tour_price : '') }}">
                            @error('day_tour_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-text">Whole booking, not per night. Leave blank if you do not run them.</div>
                    </div>
                </div>

                <div class="col-sm-6">
                    <div class="mb-3">
                        <label class="form-label" for="field-night-tour-price">
                            Night tour <span class="text-muted-vd fw-normal small"></span>
                        </label>
                        <div class="input-group has-validation">
                            <span class="input-group-text">₱</span>
                            <input type="number" class="form-control @error('night_tour_price') is-invalid @enderror"
                                   id="field-night-tour-price" name="night_tour_price" min="1" step="1"
                                   value="{{ old('night_tour_price', $room->night_tour_price ? (int) $room->night_tour_price : '') }}">
                            @error('night_tour_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-text">Evening use, leaving the next morning.</div>
                    </div>
                </div>
            </div>

            {{-- Said plainly, because it affects what an owner can sell
                 in a day and is not obvious from two price boxes. --}}
            <div class="alert alert-info py-2 small" role="status">
                A tour holds the unit for that whole date, the same as a night does.
                You cannot sell the same cottage as a day tour and an overnight stay
                on the same date.
            </div>

            <x-form.input name="capacity" type="number" label="Guests per room" :value="$room->capacity" required
                          min="1" max="30" help="The most people who can sleep in one of these rooms." />

            <x-form.input name="bed_count" type="number" label="Beds per room" :value="$room->bed_count ?? 1" required
                          min="1" max="30"
                          help="Shown to guests on the booking form, and multiplied by the rooms they take." />

            <x-form.input name="total_rooms" type="number" label="How many rooms of this type" :value="$room->total_rooms"
                          required min="1" max="500"
                          help="Used to work out availability. Two identical cottages means two." />

            @if (($committed ?? 0) > 0)
                <div class="alert alert-warning py-2 small mb-0" role="status">
                    Guests currently hold {{ $committed }} {{ Str::plural('unit', $committed) }} on at least one
                    upcoming night, so this number cannot go below {{ $committed }}.
                </div>
            @endif
        </section>

        {{-- Discounts. Off for every new room: a rate shown to a guest is a
             rate the resort has to honour at the gate, so nothing comes on
             by default and the owner types the figure themselves. --}}
        <section class="vd-panel p-4 mt-4" data-room-discounts>
            <h2 class="h5 mb-1">Discounts</h2>
            <p class="text-muted-vd small mb-3">
                Taken off the share of the bill belonging to the guests who qualify,
                not off the whole room. The booking form shows a guest the figure
                before they send the request.
            </p>

            <x-form.checkbox name="child_discount_enabled" :checked="$room->child_discount_enabled"
                             data-discount-toggle="child">
                Give children a discount
            </x-form.checkbox>

            <div class="vd-discount-fields ps-4" data-discount-fields="child"
                 @if (! old('child_discount_enabled', $room->child_discount_enabled)) hidden @endif>
                <x-form.input name="child_discount_percent" type="number" label="Children get off" required
                              min="1" max="100" :value="$room->child_discount_percent ?: 20" />

                <div class="row">
                    <div class="col-6">
                        <x-form.input name="child_discount_min_age" type="number" label="Youngest age" required
                                      min="0" max="17" :value="$room->child_discount_min_age ?? 0" />
                    </div>
                    <div class="col-6">
                        <x-form.input name="child_discount_max_age" type="number" label="Oldest age" required
                                      min="0" max="17" :value="$room->child_discount_max_age ?? 12" />
                    </div>
                </div>

                <p class="form-text mt-n2">
                    Guests count their own children, so these ages are printed beside the box
                    on the booking form. Your staff check them at the gate.
                </p>
            </div>

            <x-form.checkbox name="pwd_discount_enabled" :checked="$room->pwd_discount_enabled"
                             data-discount-toggle="pwd">
                Give guests with a disability a discount
            </x-form.checkbox>

            <div class="vd-discount-fields ps-4" data-discount-fields="pwd"
                 @if (! old('pwd_discount_enabled', $room->pwd_discount_enabled)) hidden @endif>
                <x-form.input name="pwd_discount_percent" type="number" label="PWD guests get off" required
                              min="1" max="100" :value="$room->pwd_discount_percent ?: 20"
                              help="Philippine law gives persons with disability 20% off accommodation. Ask for the PWD ID at the gate." />
            </div>

            <p class="form-text mb-0">
                A guest who is both a child and a PWD is discounted once, at whichever
                of your two rates is better for them.
            </p>
        </section>
    </div>
</div>
