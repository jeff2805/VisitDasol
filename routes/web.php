<?php

use App\Http\Controllers\Admin\ActivityLogController as AdminActivityLogController;
use App\Http\Controllers\Admin\AmenityController as AdminAmenityController;
use App\Http\Controllers\Admin\AnnouncementController as AdminAnnouncementController;
use App\Http\Controllers\Admin\DestinationCategoryController as AdminDestinationCategoryController;
use App\Http\Controllers\Admin\BarangayOfficialController;
use App\Http\Controllers\Admin\DestinationController as AdminDestinationController;
use App\Http\Controllers\Admin\DestinationImageController as AdminDestinationImageController;
use App\Http\Controllers\Admin\SpotSubmissionController;
use App\Http\Controllers\Barangay\TouristSpotController as BarangaySpotController;
use App\Http\Controllers\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Admin\MapController as AdminMapController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\OwnerApplicationController;
use App\Http\Controllers\Admin\ResortApplicationController;
use App\Http\Controllers\Admin\ResortController as AdminResortController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Admin\EnvironmentalFeeAccountController as FeeAccountController;
use App\Http\Controllers\Admin\EnvironmentalFeeCheckpointController as FeeCheckpointController;
use App\Http\Controllers\Admin\EnvironmentalFeeController;
use App\Http\Controllers\Admin\EnvironmentalFeeReportController as FeeReportController;
use App\Http\Controllers\Admin\EnvironmentalFeeVerificationController as FeeVerificationController;
use App\Http\Controllers\Admin\EnvironmentalFeeRemittanceController as AdminFeeRemittanceController;
use App\Http\Controllers\Admin\EnvironmentalFeeStaffController as FeeStaffController;
use App\Http\Controllers\EnvironmentalFeeCollectionController;
use App\Http\Controllers\EnvironmentalFeeRemittanceController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\MapOccupancyController;
use App\Http\Controllers\PlaceSearchController;
use App\Http\Controllers\RouteProfileController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ResortController;
use App\Http\Controllers\Tourist\BookingController as TouristBookingController;
use App\Http\Controllers\Tourist\PaymentController as TouristPaymentController;
use App\Http\Controllers\Tourist\ReviewController as TouristReviewController;
use App\Http\Controllers\Owner\ReviewController as OwnerReviewController;
use App\Http\Controllers\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Admin\OwnerController as AdminOwnerController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SocialLoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Owner\AvailabilityController as OwnerAvailabilityController;
use App\Http\Controllers\Owner\BookingController as OwnerBookingController;
use App\Http\Controllers\Owner\ManualGcashController;
use App\Http\Controllers\Owner\PaymentPolicyController;
use App\Http\Controllers\Owner\PaymentProofController;
use App\Http\Controllers\Owner\ResortController as OwnerResortController;
use App\Http\Controllers\Owner\StatisticsController as OwnerStatisticsController;
use App\Http\Controllers\Owner\BookingExtraController as OwnerBookingExtraController;
use App\Http\Controllers\Owner\ResortExtraController as OwnerExtraController;
use App\Http\Controllers\Owner\RoomController as OwnerRoomController;
use App\Http\Controllers\Owner\RoomImageController as OwnerRoomImageController;
use App\Http\Controllers\Owner\ResortImageController as OwnerResortImageController;
use App\Http\Controllers\Auth\OwnerRegistrationController;
use App\Http\Controllers\Auth\TouristRegistrationController;
use App\Http\Controllers\Tourist\ManualPaymentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Dev\SetupCheckController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
|--------------------------------------------------------------------------
| Visit Dasol web routes
|--------------------------------------------------------------------------
|
| Routes are grouped by audience and filled in phase by phase:
|
|   PUBLIC          no middleware
|   AUTHENTICATION  guest / auth                       (Phase 3)
|   SHARED          auth, active                       (Phase 3)
|   TOURIST         /tourist  auth, active, role       (Phases 11, 13)
|   RESORT OWNER    /owner    auth, active, role       (Phases 6, 8, 12)
|   MTO             /admin    auth, active, role       (Phases 4, 7, 9, 14-17)
|
| Never change data with a GET route.
|
*/

/*
|--------------------------------------------------------------------------
| PUBLIC
|--------------------------------------------------------------------------
*/

Route::get('/', HomeController::class)->name('home');
Route::get('/about', [PageController::class, 'about'])->name('about');
Route::get('/privacy', [PageController::class, 'privacy'])->name('privacy');
Route::get('/terms', [PageController::class, 'terms'])->name('terms');

Route::get('/resorts', [ResortController::class, 'index'])->name('resorts.index');
Route::get('/resorts/{resort:slug}', [ResortController::class, 'show'])->name('resorts.show');

/*
| The site calls these "destinations", and so do the route names, the
| model, the table and the morph map. The URL still says "tourist-spots",
| which is the one place the two differ.
|
| It is left that way on purpose. Links to /tourist-spots are already out
| in the world — shared, bookmarked, and indexed by search engines — and
| changing a public URL costs that for nothing a visitor can see. The
| redirect below keeps the older /destinations links working too, so
| every address anyone holds still arrives in the right place.
|
| If the office ever does want the URL to match, flip these two lines and
| reverse the redirect. Nothing else has to move.
*/
Route::get('/tourist-spots', [DestinationController::class, 'index'])->name('destinations.index');
Route::get('/tourist-spots/{destination:slug}', [DestinationController::class, 'show'])->name('destinations.show');

// Anyone holding an old link still arrives in the right place.
Route::permanentRedirect('/destinations', '/tourist-spots');
Route::permanentRedirect('/destinations/{slug}', '/tourist-spots/{slug}');

Route::get('/map', MapController::class)->name('map');

/*
| How full each resort is, for the dates the visitor picked on the map.
|
| Public, because the map is: deciding where to stay should not need an
| account. Throttled because it is the one map control that can be worked
| repeatedly, and every call sweeps the reservations table.
|
| It returns counts and a status word per resort, and nothing that could
| identify a guest or name a reservation. It is a snapshot for display:
| nothing is held, and BookingService re-checks availability under a row
| lock when a booking is actually submitted.
*/
Route::get('/map/occupancy', MapOccupancyController::class)
    ->middleware('throttle:60,1')
    ->name('map.occupancy');

/*
| Recording the municipal environmental fee at a checkpoint.
|
| OUTSIDE the admin prefix on purpose: the people who work the gates are
| not necessarily the tourism office. There is no "collector" role in
| this system and inventing one would invent a municipal post, so
| authority comes from an assignment row instead — checked by
| EnvironmentalFeeCollectionPolicy on every action here, and checked
| again inside EnvironmentalFeeService before anything is written.
|
| The role middleware below is a coarse first gate only. It keeps
| tourists and resort owners out; it does not decide who may collect.
*/
Route::middleware(['auth', 'active', 'verified', 'role:super_admin,barangay_official,fee_collector,cashier'])
    ->prefix('environmental-fee')
    ->name('environmental-fee.')
    ->group(function () {
        Route::get('/', [EnvironmentalFeeCollectionController::class, 'index'])->name('index');
        Route::post('/', [EnvironmentalFeeCollectionController::class, 'store'])->name('store');

        /*
        | Handing a period's takings over to the office.
        |
        | Declared here, counted by the office on its own screen. The
        | two halves are deliberately in different places and behind
        | different policies: anyone who could do both could hand in a
        | short envelope and certify it themselves.
        |
        | Above the /{collection} routes on purpose. "remittances" would
        | otherwise be read as a collection reference and 404.
        */
        Route::prefix('remittances')->name('remittances.')->group(function () {
            Route::get('/', [EnvironmentalFeeRemittanceController::class, 'index'])->name('index');
            Route::get('/expected', [EnvironmentalFeeRemittanceController::class, 'expected'])->name('expected');
            Route::post('/', [EnvironmentalFeeRemittanceController::class, 'store'])->name('store');
            Route::get('/{remittance}', [EnvironmentalFeeRemittanceController::class, 'show'])->name('show');
        });

        Route::get('/{collection}', [EnvironmentalFeeCollectionController::class, 'show'])->name('show');
        Route::patch('/{collection}/void', [EnvironmentalFeeCollectionController::class, 'void'])->name('void');
    });

/*
| Turning a typed place name into a point, for the map's "From" box.
|
| Public, because the map is. Throttled because it stands in front of
| OpenStreetMap's free search and their terms ask callers not to hammer it;
| PlaceFinder caches every answer, including the misses, so a repeated
| spelling never reaches them twice.
*/
Route::get('/places/search', PlaceSearchController::class)
    ->middleware('throttle:30,1')
    ->name('places.search');

/*
| Whether a drawn route climbs or drops. POST, because the shape of the road
| is sent up: a few dozen points, too many for a query string.
*/
Route::post('/routes/profile', RouteProfileController::class)
    ->middleware('throttle:30,1')
    ->name('routes.profile');

Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
Route::get('/announcements/{announcement:slug}', [AnnouncementController::class, 'show'])->name('announcements.show');

/*
| Payment gateways calling in. Not a page: the caller is PayMongo's or
| PayPal's server, so there is no session, no login, and no CSRF token to
| present (see bootstrap/app.php, where this path is excluded).
|
| Nothing in the body is believed. It is used only to find which payment is
| being talked about; the payment itself is then read back from the gateway.
*/
Route::post('/payments/webhook/{provider}', [TouristPaymentController::class, 'webhook'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->middleware('throttle:120,1')
    ->name('payments.webhook');

/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:20,1');

    Route::get('/register', [TouristRegistrationController::class, 'create'])->name('register');
    Route::post('/register', [TouristRegistrationController::class, 'store'])->middleware('throttle:10,1');

    Route::get('/register/owner', [OwnerRegistrationController::class, 'create'])->name('register.owner');
    Route::post('/register/owner', [OwnerRegistrationController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/register/owner/submitted', [OwnerRegistrationController::class, 'submitted'])->name('register.owner.submitted');

    /*
    | Signing in with Google or Facebook.
    |
    | Both addresses answer 404 unless that provider's keys are set, and the
    | buttons are not on the page without them either, so nothing here is
    | reachable by accident. Tourist accounts only; see App\Services\SocialLogin.
    */
    Route::get('/auth/{provider}/redirect', [SocialLoginController::class, 'redirect'])
        ->middleware('throttle:20,1')
        ->name('social.redirect');
    Route::get('/auth/{provider}/callback', [SocialLoginController::class, 'callback'])
        ->middleware('throttle:20,1')
        ->name('social.callback');

    // Password reset by email link
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.store');
});

// Signing out must work even for an account that was just deactivated,
// so this route uses "auth" without "active".
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

/*
| Email confirmation by one-time code. These routes are deliberately NOT
| behind "verified": they are where an unconfirmed account is sent, so
| guarding them would leave nowhere to go.
*/
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/verify-email', [EmailVerificationController::class, 'show'])->name('verification.notice');
    Route::post('/verify-email', [EmailVerificationController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('verification.verify');
    Route::post('/verify-email/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('verification.resend');
});

/*
|--------------------------------------------------------------------------
| SHARED (any signed-in, active account)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->middleware('verified')->name('dashboard');

    // Profile stays reachable without confirming the address. Someone who
    // mistyped their email must be able to correct it; saving a new address
    // clears the confirmation (see ProfileController::update) and sends them
    // back to the code screen.
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
});

/*
|--------------------------------------------------------------------------
| TOURIST (bookings arrive in Phases 11 and 13)
|--------------------------------------------------------------------------
*/

Route::prefix('tourist')
    ->name('tourist.')
    ->middleware(['auth', 'active', 'verified', 'role:tourist'])
    ->group(function () {
        Route::get('/dashboard', \App\Http\Controllers\Tourist\DashboardController::class)->name('dashboard');

        // Reservations
        Route::get('/bookings', [TouristBookingController::class, 'index'])->name('bookings.index');
        Route::get('/bookings/create', [TouristBookingController::class, 'create'])->name('bookings.create');
        Route::post('/bookings', [TouristBookingController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('bookings.store');
        Route::get('/bookings/{booking}', [TouristBookingController::class, 'show'])->name('bookings.show');
        Route::patch('/bookings/{booking}/cancel', [TouristBookingController::class, 'cancel'])->name('bookings.cancel');

        // Paying for a confirmed reservation. The gateway takes the payment on
        // its own pages; these routes only open a checkout and read the answer.
        Route::post('/bookings/{booking}/pay', [TouristPaymentController::class, 'start'])
            ->middleware('throttle:20,1')
            ->name('payments.start');
        Route::get('/bookings/{booking}/pay/return', [TouristPaymentController::class, 'return'])->name('payments.return');
        Route::get('/bookings/{booking}/pay/cancel', [TouristPaymentController::class, 'cancel'])->name('payments.cancel');

        Route::post('/bookings/{booking}/manual-pay/start', [ManualPaymentController::class, 'start'])
            ->middleware('throttle:20,1')
            ->name('manual-payments.start');
        Route::get('/bookings/{booking}/manual-pay', [ManualPaymentController::class, 'show'])->name('manual-payments.show');
        Route::post('/bookings/{booking}/manual-pay/submit', [ManualPaymentController::class, 'submit'])
            ->middleware('throttle:20,1')
            ->name('manual-payments.submit');

        // Reviewing a finished stay. The booking is the subject, because only
        // a completed booking entitles anyone to write one (see ReviewPolicy).
        Route::get('/bookings/{booking}/review', [TouristReviewController::class, 'create'])->name('reviews.create');
        Route::post('/bookings/{booking}/review', [TouristReviewController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('reviews.store');
        Route::get('/bookings/{booking}/review/edit', [TouristReviewController::class, 'edit'])->name('reviews.edit');
        Route::put('/bookings/{booking}/review', [TouristReviewController::class, 'update'])->name('reviews.update');
    });

/*
|--------------------------------------------------------------------------
| RESORT OWNER (resort, rooms, and bookings arrive in Phases 6, 8, and 12)
|--------------------------------------------------------------------------
*/

Route::prefix('owner')
    ->name('owner.')
    ->middleware(['auth', 'active', 'verified', 'role:resort_owner'])
    ->group(function () {
        Route::get('/dashboard', \App\Http\Controllers\Owner\DashboardController::class)->name('dashboard');

        // The owner's own resort. No resort id appears in these URLs.
        Route::get('/resort', [OwnerResortController::class, 'show'])->name('resort.show');
        Route::get('/resort/create', [OwnerResortController::class, 'create'])->name('resort.create');
        Route::post('/resort', [OwnerResortController::class, 'store'])->name('resort.store');
        Route::get('/resort/edit', [OwnerResortController::class, 'edit'])->name('resort.edit');
        Route::put('/resort', [OwnerResortController::class, 'update'])->name('resort.update');
        Route::patch('/resort/resubmit', [OwnerResortController::class, 'resubmit'])->name('resort.resubmit');

        // Room types
        /*
        | What the resort offers besides the room: kayak hire, a banana
        | boat ride, island hopping.
        |
        | Guests ASK for these rather than buy them — whether a boat
        | goes out depends on the weather and the boatman — so nothing
        | here reaches a booking total. See BookingExtraService.
        */
        Route::get('/extras', [OwnerExtraController::class, 'index'])->name('extras.index');
        Route::get('/extras/new', [OwnerExtraController::class, 'create'])->name('extras.create');
        Route::post('/extras', [OwnerExtraController::class, 'store'])->name('extras.store');
        Route::get('/extras/{extra}/edit', [OwnerExtraController::class, 'edit'])->name('extras.edit');
        Route::put('/extras/{extra}', [OwnerExtraController::class, 'update'])->name('extras.update');
        Route::patch('/extras/{extra}/status', [OwnerExtraController::class, 'status'])->name('extras.status');

        // Answering one guest's request, yes or no.
        Route::patch('/booking-extras/{extra}', [OwnerBookingExtraController::class, 'answer'])
            ->name('booking-extras.answer');

        Route::get('/rooms', [OwnerRoomController::class, 'index'])->name('rooms.index');
        Route::get('/rooms/create', [OwnerRoomController::class, 'create'])->name('rooms.create');
        Route::post('/rooms', [OwnerRoomController::class, 'store'])->name('rooms.store');
        Route::get('/rooms/{room}/edit', [OwnerRoomController::class, 'edit'])->name('rooms.edit');
        Route::put('/rooms/{room}', [OwnerRoomController::class, 'update'])->name('rooms.update');
        Route::patch('/rooms/{room}/status', [OwnerRoomController::class, 'status'])->name('rooms.status');
        Route::delete('/rooms/{room}', [OwnerRoomController::class, 'destroy'])->name('rooms.destroy');

        Route::post('/rooms/{room}/images', [OwnerRoomImageController::class, 'store'])->name('rooms.images.store');
        Route::delete('/rooms/{room}/images/{image}', [OwnerRoomImageController::class, 'destroy'])
            ->scopeBindings()
            ->name('rooms.images.destroy');

        // Reservations
        Route::get('/bookings', [OwnerBookingController::class, 'index'])->name('bookings.index');
        Route::get('/bookings/{booking}', [OwnerBookingController::class, 'show'])->name('bookings.show');
        Route::patch('/bookings/{booking}/confirm', [OwnerBookingController::class, 'confirm'])->name('bookings.confirm');
        Route::patch('/bookings/{booking}/reject', [OwnerBookingController::class, 'reject'])->name('bookings.reject');
        Route::patch('/bookings/{booking}/complete', [OwnerBookingController::class, 'complete'])->name('bookings.complete');
        Route::patch('/bookings/{booking}/cancel', [OwnerBookingController::class, 'cancel'])->name('bookings.cancel');

        Route::get('/availability', OwnerAvailabilityController::class)->name('availability');
        Route::get('/statistics', OwnerStatisticsController::class)->name('statistics');

        Route::get('/gcash', [ManualGcashController::class, 'edit'])->name('manual-gcash.edit');
        Route::put('/gcash', [ManualGcashController::class, 'update'])->name('manual-gcash.update');

        /*
        | What this resort asks for up front, and by when. The owner's own
        | commercial terms, so the office has no route into them.
        */
        Route::get('/payment-policy', [PaymentPolicyController::class, 'edit'])->name('payment-policy.edit');
        Route::put('/payment-policy', [PaymentPolicyController::class, 'update'])->name('payment-policy.update');
        Route::delete('/payment-policy', [PaymentPolicyController::class, 'destroy'])->name('payment-policy.destroy');

        Route::get('/payments/waiting', [PaymentProofController::class, 'index'])->name('payment-proofs.index');
        Route::post('/payments/{proof}/approve', [PaymentProofController::class, 'approve'])->name('payment-proofs.approve');
        Route::post('/payments/{proof}/reject', [PaymentProofController::class, 'reject'])->name('payment-proofs.reject');
        Route::get('/payments/{proof}/image', [PaymentProofController::class, 'image'])->name('payment-proofs.image');

        // Guest reviews. An owner may answer one but never remove it.
        Route::get('/reviews', [OwnerReviewController::class, 'index'])->name('reviews.index');
        Route::post('/reviews/{review}/reply', [OwnerReviewController::class, 'reply'])->name('reviews.reply');

        // Gallery photos
        Route::post('/resort/images', [OwnerResortImageController::class, 'store'])->name('resort.images.store');
        Route::patch('/resort/images/{image}/cover', [OwnerResortImageController::class, 'setCover'])->name('resort.images.cover');
        Route::delete('/resort/images/{image}', [OwnerResortImageController::class, 'destroy'])->name('resort.images.destroy');
    });

/*
|--------------------------------------------------------------------------
| BARANGAY OFFICIAL
|--------------------------------------------------------------------------
|
| Dasol has 18 barangays and one tourism office. The people who know where
| the waterfalls and viewdecks are live in the barangays, so they write them
| up here — but nothing they write reaches a visitor until the office has
| approved it. See DestinationPolicy for who may do what.
*/

Route::prefix('barangay')
    ->name('barangay.')
    ->middleware(['auth', 'active', 'verified', 'role:barangay_official'])
    ->group(function () {
        Route::get('/dashboard', \App\Http\Controllers\Barangay\DashboardController::class)->name('dashboard');

        Route::get('/tourist-spots', [BarangaySpotController::class, 'index'])->name('spots.index');
        Route::get('/tourist-spots/create', [BarangaySpotController::class, 'create'])->name('spots.create');
        Route::post('/tourist-spots', [BarangaySpotController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('spots.store');
        Route::get('/tourist-spots/{spot}/edit', [BarangaySpotController::class, 'edit'])->name('spots.edit');
        Route::put('/tourist-spots/{spot}', [BarangaySpotController::class, 'update'])->name('spots.update');
        Route::patch('/tourist-spots/{spot}/resubmit', [BarangaySpotController::class, 'resubmit'])->name('spots.resubmit');
        Route::delete('/tourist-spots/{spot}', [BarangaySpotController::class, 'destroy'])->name('spots.destroy');

        /*
         * Photos. The office's own photo controller, reused as-is: it asks
         * DestinationPolicy@manageImages, which already knows a barangay may
         * only touch its own submission while that submission is still theirs.
         * A second copy of it would be a second place for that rule to drift.
         */
        Route::post('/tourist-spots/{destination}/images', [AdminDestinationImageController::class, 'store'])
            ->name('spots.images.store');
        Route::patch('/tourist-spots/{destination}/images/{image}/cover', [AdminDestinationImageController::class, 'setCover'])
            ->scopeBindings()->name('spots.images.cover');
        Route::delete('/tourist-spots/{destination}/images/{image}', [AdminDestinationImageController::class, 'destroy'])
            ->scopeBindings()->name('spots.images.destroy');
    });

/*
|--------------------------------------------------------------------------
| MUNICIPAL TOURISM OFFICE (reviews and reports arrive in Phases 4, 7, 9, 14-17)
|--------------------------------------------------------------------------
*/

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'active', 'verified', 'role:super_admin'])
    ->group(function () {
        Route::get('/dashboard', \App\Http\Controllers\Admin\DashboardController::class)->name('dashboard');

        // Resort owner applications
        Route::get('/owner-applications', [OwnerApplicationController::class, 'index'])->name('owner-applications.index');
        Route::get('/owner-applications/{owner}', [OwnerApplicationController::class, 'show'])->name('owner-applications.show');
        Route::get('/owner-applications/{owner}/permit', [OwnerApplicationController::class, 'permit'])->name('owner-applications.permit');
        Route::patch('/owner-applications/{owner}/approve', [OwnerApplicationController::class, 'approve'])->name('owner-applications.approve');
        Route::patch('/owner-applications/{owner}/reject', [OwnerApplicationController::class, 'reject'])->name('owner-applications.reject');
        Route::patch('/owner-applications/{owner}/reopen', [OwnerApplicationController::class, 'reopen'])->name('owner-applications.reopen');

        // Accounts
        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
        Route::patch('/users/{user}/activate', [AdminUserController::class, 'activate'])->name('users.activate');
        Route::patch('/users/{user}/deactivate', [AdminUserController::class, 'deactivate'])->name('users.deactivate');

        Route::get('/owners', [AdminOwnerController::class, 'index'])->name('owners.index');

        Route::get('/map', AdminMapController::class)->name('map');

        /*
        | The municipal environmental fee.
        |
        | A SEPARATE COLLECTION, not a booking charge and not resort
        | income. Nothing under here reads a booking, a room rate or a
        | payment, and nothing adds a fee to what a guest owes a resort.
        |
        | Configuration is the office's alone, which the role middleware
        | on this whole group already enforces — and every action checks
        | its policy again on the server, because a hidden menu item is
        | not authorisation.
        */
        /*
        | Cashiers reach these screens too.
        |
        | The group around this one is role:super_admin, which is right
        | for the rest of the office's work. A cashier's job is counting
        | what the gates hand in and reading the municipal figures, and
        | both live under here — so this sub-group names its own roles
        | and drops the outer one.
        |
        | WIDER AT THE DOOR, NOT INSIDE. Every action below still calls
        | $this->authorize(), and the policies refuse a cashier
        | everything but the two things they are for: configuring the
        | fee, managing checkpoints, the GCash account and creating
        | staff all check isSuperAdmin and all fail for a cashier.
        */
        Route::withoutMiddleware('role:super_admin')
            ->middleware('role:super_admin,cashier')
            ->prefix('environmental-fee')->name('environmental-fee.')->group(function () {
            Route::get('/', [EnvironmentalFeeController::class, 'index'])->name('index');
            Route::get('/configure', [EnvironmentalFeeController::class, 'create'])->name('create');
            Route::post('/configure', [EnvironmentalFeeController::class, 'store'])->name('store');

            // Starting or stopping collection: its own action, logged
            // with a name against it.
            Route::patch('/{policy}/toggle', [EnvironmentalFeeController::class, 'toggle'])->name('toggle');

            /*
            | Changing when a fee stops applying, and only that. The
            | rate is never editable: receipts have been written at it,
            | and a changed amount would rewrite what visitors were
            | charged on days already gone. A new rate is a new policy.
            */
            Route::patch('/{policy}/extend', [EnvironmentalFeeController::class, 'extend'])->name('extend');

            Route::get('/checkpoints', [FeeCheckpointController::class, 'index'])->name('checkpoints.index');
            Route::get('/checkpoints/create', [FeeCheckpointController::class, 'create'])->name('checkpoints.create');
            Route::post('/checkpoints', [FeeCheckpointController::class, 'store'])->name('checkpoints.store');
            Route::get('/checkpoints/{checkpoint}', [FeeCheckpointController::class, 'show'])->name('checkpoints.show');
            Route::get('/checkpoints/{checkpoint}/edit', [FeeCheckpointController::class, 'edit'])->name('checkpoints.edit');
            Route::put('/checkpoints/{checkpoint}', [FeeCheckpointController::class, 'update'])->name('checkpoints.update');
            Route::patch('/checkpoints/{checkpoint}/status', [FeeCheckpointController::class, 'status'])->name('checkpoints.status');

            Route::post('/checkpoints/{checkpoint}/collectors', [FeeCheckpointController::class, 'assign'])->name('collectors.assign');
            Route::delete('/checkpoints/{checkpoint}/collectors/{assignment}', [FeeCheckpointController::class, 'withdraw'])->name('collectors.withdraw');

            /*
            | The municipal GCash account, and checking that transfers
            | arrived. NOT a payment gateway: a QR is an instruction to
            | the payer, and a person confirms every transfer by hand.
            */
            Route::get('/gcash', [FeeAccountController::class, 'edit'])->name('gcash.edit');
            Route::put('/gcash', [FeeAccountController::class, 'update'])->name('gcash.update');

            Route::get('/verify', [FeeVerificationController::class, 'index'])->name('verify');
            Route::patch('/verify/{collection}', [FeeVerificationController::class, 'settle'])->name('settle');
            Route::get('/verify/{collection}/proof', [FeeVerificationController::class, 'proof'])->name('proof');

            /*
            | Counting what the gates handed in.
            |
            | The office half of the remittance pair. Only a super
            | admin reaches this prefix at all, and
            | EnvironmentalFeeRemittancePolicy::review() narrows it
            | further: an officer may not sign off an envelope they
            | handed in themselves.
            */
            Route::prefix('remittances')->name('remittances.')->group(function () {
                Route::get('/', [AdminFeeRemittanceController::class, 'index'])->name('index');
                Route::get('/export', [AdminFeeRemittanceController::class, 'export'])->name('export');
                Route::get('/{remittance}', [AdminFeeRemittanceController::class, 'show'])->name('show');
                Route::patch('/{remittance}/review', [AdminFeeRemittanceController::class, 'review'])->name('review');
            });

            /*
            | The people who work the fee.
            |
            | Creating an account that may take municipal money at a
            | barrier is the office's alone. Every action below also
            | authorizes against UserPolicy on the server; this prefix
            | being super_admin-only is the coarse gate, not the whole
            | of it.
            |
            | Activating and deactivating are deliberately NOT here —
            | they already live on the accounts screen and go through
            | AccountManager, which ends the person's live sessions and
            | writes the audit entry.
            */
            Route::prefix('staff')->name('staff.')->group(function () {
                Route::get('/', [FeeStaffController::class, 'index'])->name('index');
                Route::get('/new', [FeeStaffController::class, 'create'])->name('create');
                Route::post('/', [FeeStaffController::class, 'store'])->name('store');
                Route::get('/{staff}/edit', [FeeStaffController::class, 'edit'])->name('edit');
                Route::put('/{staff}', [FeeStaffController::class, 'update'])->name('update');
                Route::post('/{staff}/invite', [FeeStaffController::class, 'invite'])->name('invite');
            });

            Route::get('/reports', [FeeReportController::class, 'index'])->name('reports');
            Route::get('/reports/lookup', [FeeReportController::class, 'lookup'])->name('lookup');
            Route::get('/reports/export', [FeeReportController::class, 'export'])->name('export');
        });

        Route::get('/activity-logs', [AdminActivityLogController::class, 'index'])->name('activity-logs.index');

        // Reservations across every resort (read only)
        Route::get('/reports', [AdminReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [AdminReportController::class, 'export'])->name('reports.export');
        // The Department of Tourism's monthly accommodation return, as a
        // spreadsheet the office can file.
        Route::get('/reports/accommodation', [AdminReportController::class, 'accommodation'])
            ->name('reports.accommodation');

        Route::get('/bookings', [AdminBookingController::class, 'index'])->name('bookings.index');
        Route::get('/bookings/{booking}', [AdminBookingController::class, 'show'])->name('bookings.show');

        // Tourist spots sent in by the barangays, waiting to be read.
        Route::get('/spot-submissions', [SpotSubmissionController::class, 'index'])->name('spot-submissions.index');
        Route::get('/spot-submissions/{spot}', [SpotSubmissionController::class, 'show'])->name('spot-submissions.show');
        Route::patch('/spot-submissions/{spot}/approve', [SpotSubmissionController::class, 'approve'])->name('spot-submissions.approve');
        Route::patch('/spot-submissions/{spot}/reject', [SpotSubmissionController::class, 'reject'])->name('spot-submissions.reject');

        // The barangay officials who send those in.
        Route::get('/barangay-officials', [BarangayOfficialController::class, 'index'])->name('barangay-officials.index');
        Route::get('/barangay-officials/create', [BarangayOfficialController::class, 'create'])->name('barangay-officials.create');
        Route::post('/barangay-officials', [BarangayOfficialController::class, 'store'])->name('barangay-officials.store');
        Route::get('/barangay-officials/{official}', [BarangayOfficialController::class, 'show'])->name('barangay-officials.show');

        // Tourist destinations
        Route::get('/tourist-spots', [AdminDestinationController::class, 'index'])->name('destinations.index');
        Route::get('/tourist-spots/create', [AdminDestinationController::class, 'create'])->name('destinations.create');
        Route::post('/tourist-spots', [AdminDestinationController::class, 'store'])->name('destinations.store');
        Route::get('/tourist-spots/{destination}/edit', [AdminDestinationController::class, 'edit'])->name('destinations.edit');
        Route::put('/tourist-spots/{destination}', [AdminDestinationController::class, 'update'])->name('destinations.update');
        Route::patch('/tourist-spots/{destination}/status', [AdminDestinationController::class, 'status'])->name('destinations.status');
        Route::patch('/tourist-spots/{destination}/feature', [AdminDestinationController::class, 'feature'])->name('destinations.feature');
        Route::delete('/tourist-spots/{destination}', [AdminDestinationController::class, 'destroy'])->name('destinations.destroy');

        Route::post('/tourist-spots/{destination}/images', [AdminDestinationImageController::class, 'store'])->name('destinations.images.store');
        Route::patch('/tourist-spots/{destination}/images/{image}/cover', [AdminDestinationImageController::class, 'setCover'])
            ->scopeBindings()->name('destinations.images.cover');
        Route::delete('/tourist-spots/{destination}/images/{image}', [AdminDestinationImageController::class, 'destroy'])
            ->scopeBindings()->name('destinations.images.destroy');

        // Destination categories
        Route::get('/tourist-spot-categories', [AdminDestinationCategoryController::class, 'index'])->name('destination-categories.index');
        Route::post('/tourist-spot-categories', [AdminDestinationCategoryController::class, 'store'])->name('destination-categories.store');
        Route::put('/tourist-spot-categories/{category}', [AdminDestinationCategoryController::class, 'update'])->name('destination-categories.update');
        Route::delete('/tourist-spot-categories/{category}', [AdminDestinationCategoryController::class, 'destroy'])->name('destination-categories.destroy');

        // Announcements
        Route::get('/announcements', [AdminAnnouncementController::class, 'index'])->name('announcements.index');
        Route::get('/announcements/create', [AdminAnnouncementController::class, 'create'])->name('announcements.create');
        Route::post('/announcements', [AdminAnnouncementController::class, 'store'])->name('announcements.store');
        Route::get('/announcements/{announcement}/edit', [AdminAnnouncementController::class, 'edit'])->name('announcements.edit');
        Route::put('/announcements/{announcement}', [AdminAnnouncementController::class, 'update'])->name('announcements.update');
        Route::patch('/announcements/{announcement}/publish', [AdminAnnouncementController::class, 'publish'])->name('announcements.publish');
        Route::patch('/announcements/{announcement}/unpublish', [AdminAnnouncementController::class, 'unpublish'])->name('announcements.unpublish');
        Route::patch('/announcements/{announcement}/feature', [AdminAnnouncementController::class, 'feature'])->name('announcements.feature');
        Route::delete('/announcements/{announcement}', [AdminAnnouncementController::class, 'destroy'])->name('announcements.destroy');

        // Amenity master list
        Route::get('/amenities', [AdminAmenityController::class, 'index'])->name('amenities.index');
        Route::post('/amenities', [AdminAmenityController::class, 'store'])->name('amenities.store');
        Route::put('/amenities/{amenity}', [AdminAmenityController::class, 'update'])->name('amenities.update');
        Route::patch('/amenities/{amenity}/toggle', [AdminAmenityController::class, 'toggle'])->name('amenities.toggle');
        Route::delete('/amenities/{amenity}', [AdminAmenityController::class, 'destroy'])->name('amenities.destroy');

        // Resort listings waiting for review
        Route::get('/resort-applications', [ResortApplicationController::class, 'index'])->name('resort-applications.index');
        Route::get('/resort-applications/{resort}', [ResortApplicationController::class, 'show'])->name('resort-applications.show');
        Route::patch('/resort-applications/{resort}/approve', [ResortApplicationController::class, 'approve'])->name('resort-applications.approve');
        Route::patch('/resort-applications/{resort}/reject', [ResortApplicationController::class, 'reject'])->name('resort-applications.reject');

        // All resorts
        Route::get('/resorts', [AdminResortController::class, 'index'])->name('resorts.index');
        Route::get('/resorts/{resort}', [AdminResortController::class, 'show'])->name('resorts.show');
        // Review moderation. Hiding never deletes; the row and its reason stay.
        Route::get('/reviews', [AdminReviewController::class, 'index'])->name('reviews.index');
        Route::patch('/reviews/{review}/hide', [AdminReviewController::class, 'hide'])->name('reviews.hide');
        Route::patch('/reviews/{review}/restore', [AdminReviewController::class, 'restore'])->name('reviews.restore');

        Route::get('/resorts/{resort}/edit', [AdminResortController::class, 'edit'])->name('resorts.edit');
        Route::put('/resorts/{resort}', [AdminResortController::class, 'update'])->name('resorts.update');
        Route::patch('/resorts/{resort}/activate', [AdminResortController::class, 'activate'])->name('resorts.activate');
        Route::patch('/resorts/{resort}/deactivate', [AdminResortController::class, 'deactivate'])->name('resorts.deactivate');
        Route::patch('/resorts/{resort}/feature', [AdminResortController::class, 'feature'])->name('resorts.feature');
    });

/*
|--------------------------------------------------------------------------
| LOCAL DEVELOPMENT ONLY
|--------------------------------------------------------------------------
|
| Registered only when APP_ENV=local. They never exist on a demo or
| production server. Removed entirely in Phase 21.
|
*/

if (app()->environment('local')) {
    Route::prefix('_dev')->name('dev.')->group(function () {
        // Runs without sessions so it still works when the database is misconfigured
        // (sessions are stored in the database).
        Route::get('/setup', SetupCheckController::class)
            ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class])
            ->name('setup');

        Route::view('/preview/owner', 'dev.preview-owner')->name('preview.owner');

        Route::view('/preview/admin', 'dev.preview-admin', [
            'pendingOwnerCount' => 3,
            'pendingResortCount' => 2,
        ])->name('preview.admin');

        Route::get('/preview/error/{code}', fn (int $code) => abort($code))
            ->whereIn('code', ['403', '404', '419', '500', '503'])
            ->name('preview.error');
    });
}
