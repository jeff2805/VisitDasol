@extends('layouts.owner')

@section('title', 'Payment terms')

@section('content')
    <x-page-header title="Payment terms" :subtitle="$resort->name">
        <a class="btn btn-light border" href="{{ route('owner.dashboard') }}">Back</a>
    </x-page-header>

    @if ($isDefault)
        <div class="alert alert-info d-flex gap-2" role="status">
            <i class="bi bi-info-circle mt-1" aria-hidden="true"></i>
            <div>
                <strong class="d-block">You are using the portal's standard terms</strong>
                They work for most resorts. Change anything below and your own terms
                apply from your next booking onwards.
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('owner.payment-policy.update') }}" novalidate data-submit-once>
        @csrf
        @method('PUT')

        <div class="row g-4">
            <div class="col-lg-7">
                <section class="vd-panel p-4 mb-4">
                    <h2 class="h5 mb-1">What you ask for up front</h2>
                    <p class="text-muted-vd small mb-3">
                        Taken from the bill after any child or PWD discount, because that
                        is what the guest actually owes.
                    </p>

                    <fieldset class="mb-3" data-discount-scope>
                        <legend class="visually-hidden">Payment requirement</legend>

                        @foreach (\App\Enums\PaymentRequirement::cases() as $case)
                            <label class="vd-paychoice__option">
                                <input class="form-check-input" type="radio"
                                       name="requirement" value="{{ $case->value }}"
                                       data-requirement
                                       @checked(old('requirement', $policy->requirement->value) === $case->value)>
                                <span class="vd-paychoice__text">
                                    <span class="vd-paychoice__name">{{ $case->label() }}</span>
                                    <span class="vd-paychoice__detail">{{ $case->description() }}</span>
                                </span>
                            </label>
                        @endforeach
                    </fieldset>

                    @error('requirement')<p class="text-danger small">{{ $message }}</p>@enderror

                    {{-- Only meaningful for a deposit, so it is only shown for one.
                         Hidden rather than removed, so a figure already typed
                         survives a change of mind. --}}
                    <div data-deposit-fields
                         @if (old('requirement', $policy->requirement->value) !== 'deposit') hidden @endif>
                        <div class="mb-3" style="max-width: 16rem">
                            <label class="form-label" for="field-deposit-percent">Deposit</label>
                            <div class="input-group has-validation">
                                <input type="number" class="form-control @error('deposit_percent') is-invalid @enderror"
                                       id="field-deposit-percent" name="deposit_percent"
                                       min="1" max="100" step="1"
                                       value="{{ old('deposit_percent', $policy->deposit_percent ?: 30) }}">
                                <span class="input-group-text">%</span>
                                @error('deposit_percent')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                </section>

                <section class="vd-panel p-4 mb-4">
                    <h2 class="h5 mb-1">When it is due</h2>
                    <p class="text-muted-vd small mb-3">
                        A deadline needs a starting point. Counting from your confirmation
                        is the usual choice: the clock only starts once you have accepted.
                    </p>

                    <div class="row">
                        <div class="col-sm-5">
                            <x-form.input name="deadline_hours" type="number" label="Hours to pay" required
                                          min="1" max="8760"
                                          :value="$policy->deadline_hours" />
                        </div>
                        <div class="col-sm-7">
                            <div class="mb-3">
                                <label class="form-label" for="field-deadline-reference">Counted from</label>
                                <select class="form-select @error('deadline_reference') is-invalid @enderror"
                                        id="field-deadline-reference" name="deadline_reference">
                                    @foreach (\App\Enums\PaymentDeadlineReference::cases() as $case)
                                        <option value="{{ $case->value }}"
                                                @selected(old('deadline_reference', $policy->deadline_reference->value) === $case->value)>
                                            {{ $case->label() }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('deadline_reference')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                </section>

                <section class="vd-panel p-4">
                    <h2 class="h5 mb-1">Guests arriving soon</h2>
                    <p class="text-muted-vd small mb-3">
                        A 24-hour deadline is no use on a booking made the night before
                        arrival, so say what you want instead when a guest is nearly here.
                    </p>

                    <div class="row">
                        <div class="col-sm-7">
                            <div class="mb-3">
                                <label class="form-label" for="field-last-minute-rule">When arrival is close</label>
                                <select class="form-select @error('last_minute_rule') is-invalid @enderror"
                                        id="field-last-minute-rule" name="last_minute_rule">
                                    @foreach (\App\Enums\LastMinuteRule::cases() as $case)
                                        <option value="{{ $case->value }}"
                                                @selected(old('last_minute_rule', $policy->last_minute_rule->value) === $case->value)>
                                            {{ $case->label() }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('last_minute_rule')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-sm-5">
                            <x-form.input name="last_minute_hours" type="number" label="Counts as close within" required
                                          min="0" max="720"
                                          :value="$policy->last_minute_hours"
                                          help="Hours before arrival." />
                        </div>
                    </div>

                    <x-form.checkbox name="allow_on_site" :checked="$policy->allow_on_site">
                        Let guests pay at the resort
                    </x-form.checkbox>

                    <p class="form-text mb-0">
                        Turning this off removes "Pay at the resort" from the booking form.
                        Only do that if you really cannot take cash on arrival.
                    </p>
                </section>
            </div>

            <div class="col-lg-5">
                {{-- What the terms above actually do, on a round number.
                     An owner should not have to make a test booking to find
                     out what a guest will be asked for. --}}
                <aside class="vd-panel p-4 mb-4">
                    <h2 class="h5 mb-1">What a guest would see</h2>
                    <p class="text-muted-vd small mb-3">
                        On a ₱5,000 stay, with your saved terms.
                    </p>

                    <x-payment-terms :terms="$preview" heading="Booking a month ahead" class="mb-3" />
                    <x-payment-terms :terms="$previewLastMinute" heading="Arriving today" />
                </aside>

                @unless ($manualAccount?->active)
                    <aside class="vd-panel p-4 mb-4">
                        <h2 class="h6 mb-2">Taking the money</h2>
                        <p class="text-muted-vd small mb-2">
                            You have no active GCash account set up, so guests who owe a
                            deposit can only settle with you directly.
                        </p>
                        <a class="btn btn-sm btn-outline-primary"
                           href="{{ route('owner.manual-gcash.edit') }}">Set up GCash</a>
                    </aside>
                @endunless

                <div class="d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary btn-lg">Save terms</button>
                </div>
            </div>
        </div>
    </form>

    @unless ($isDefault)
        <section class="vd-panel p-4 mt-4">
            <h2 class="h6 mb-2">Back to the standard terms</h2>
            <p class="text-muted-vd small mb-3">
                Reservations already made keep the terms they were agreed under.
            </p>

            <x-confirm-form id="reset-payment-policy"
                            :action="route('owner.payment-policy.destroy')"
                            method="DELETE"
                            title="Use the portal's standard terms?"
                            message="Your own terms will be removed. Bookings already made are not affected."
                            trigger="Use the standard terms"
                            confirm="Yes, reset them"
                            icon="arrow-counterclockwise"
                            variant="danger"
                            trigger-class="btn btn-outline-danger btn-sm" />
        </section>
    @endunless
@endsection
