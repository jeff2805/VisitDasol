{{-- Shared by all roles. $layout picks the shell that matches the user's role. --}}
@extends($layout)

@section('title', 'Profile')

@section('content')
    <div @class(['container py-5' => $layout === 'layouts.public'])>
        <div class="row g-4">
            <div class="col-lg-7">
                <section class="vd-panel p-3 p-md-4">
                    <h1 class="h4 mb-1">Account details</h1>
                    <p class="text-muted-vd">Your name and contact details.</p>

                    <form method="POST" action="{{ route('profile.update') }}" novalidate data-submit-once>
                        @csrf
                        @method('PUT')

                        <div class="row">
                            <div class="col-sm-6">
                                <x-form.input name="first_name" label="First name" :value="$user->first_name" required autocomplete="given-name" />
                            </div>
                            <div class="col-sm-6">
                                <x-form.input name="last_name" label="Last name" :value="$user->last_name" required autocomplete="family-name" />
                            </div>
                        </div>

                        <x-form.input name="email" type="email" label="Email address" :value="$user->email" required autocomplete="email"
                                      help="Changing this asks for your current password." />

                        <x-form.input name="phone" type="tel" label="Contact number" :value="$user->phone"
                                      :required="$user->isResortOwner()" autocomplete="tel" />

                        @if ($user->isTourist())
                            <div class="row">
                                <div class="col-sm-7">
                                    <x-form.input name="home_city" label="City or municipality" :value="$user->home_city" autocomplete="address-level2" />
                                </div>
                                <div class="col-sm-5">
                                    <x-form.input name="home_country" label="Country" :value="$user->home_country" autocomplete="country-name" />
                                </div>
                            </div>
                        @endif

                        <x-form.input name="current_password" type="password" label="Current password" autocomplete="current-password"
                                      help="Only needed when you change your email address." />

                        <button type="submit" class="btn btn-primary">Save changes</button>
                    </form>
                </section>
            </div>

            <div class="col-lg-5">
                <section class="vd-panel p-3 p-md-4 mb-4">
                    <h2 class="h5 mb-3">Your account</h2>
                    <dl class="row mb-0 small">
                        <dt class="col-5 text-muted-vd fw-normal">Account type</dt>
                        <dd class="col-7">{{ $user->role->label() }}</dd>

                        <dt class="col-5 text-muted-vd fw-normal">Status</dt>
                        <dd class="col-7"><x-status-badge :status="$user->status" /></dd>

                        <dt class="col-5 text-muted-vd fw-normal">Member since</dt>
                        <dd class="col-7">{{ $user->created_at->format('M j, Y') }}</dd>

                        @if ($user->last_login_at)
                            <dt class="col-5 text-muted-vd fw-normal">Last sign-in</dt>
                            <dd class="col-7 mb-0">{{ $user->last_login_at->format('M j, Y g:i A') }}</dd>
                        @endif
                    </dl>
                </section>

                <section class="vd-panel p-3 p-md-4">
                    <h2 class="h5 mb-1">Change password</h2>
                    <p class="text-muted-vd small">Changing it signs out your other devices.</p>

                    <form method="POST" action="{{ route('profile.password') }}" novalidate data-submit-once>
                        @csrf
                        @method('PUT')

                        @error('current_password', 'password')
                            <div class="alert alert-danger py-2" role="alert">{{ $message }}</div>
                        @enderror

                        <x-form.input name="current_password" type="password" label="Current password" required autocomplete="current-password"
                                      id="password-current" />
                        <x-form.input name="password" type="password" label="New password" required autocomplete="new-password"
                                      id="password-new" help="At least 8 characters, including letters and numbers." />
                        <x-form.input name="password_confirmation" type="password" label="Confirm new password" required autocomplete="new-password"
                                      id="password-confirm" />

                        <button type="submit" class="btn btn-outline-primary">Change password</button>
                    </form>
                </section>
            </div>
        </div>
    </div>
@endsection
