<?php

namespace App\Providers;

use App\Models\ActivityLog;
use App\Models\Amenity;
use App\Models\Announcement;
use App\Models\Booking;
use App\Models\Destination;
use App\Models\DestinationCategory;
use App\Models\DestinationImage;
use App\Models\EnvironmentalFeeAccount;
use App\Models\EnvironmentalFeeCheckpoint;
use App\Models\EnvironmentalFeeCollection;
use App\Models\BookingExtra;
use App\Models\EnvironmentalFeePolicy;
use App\Models\EnvironmentalFeeRemittance;
use App\Models\OwnerProfile;
use App\Models\Payment;
use App\Models\Resort;
use App\Models\ResortExtra;
use App\Models\ResortImage;
use App\Models\Review;
use App\Models\Room;
use App\Models\RoomImage;
use App\Models\User;
use App\Support\Asset;
use App\Support\Money;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Outside production, Eloquent throws on common mistakes instead of hiding them:
        // lazy loading in loops (N+1 queries), filling non-fillable attributes,
        // and reading attributes that were not selected.
        Model::shouldBeStrict(! $this->app->isProduction());

        // @peso($amount) prints a peso amount, e.g. ₱1,500
        Blade::directive('peso', fn (string $expression) => "<?php echo e(".Money::class."::peso({$expression})); ?>");

        /*
         * @vdasset('js/map.js') prints the URL of one of this project's own
         * files with a cache stamp on it.
         *
         * Not named @asset: Blade would then shadow nothing, but a reader
         * seeing @asset would reasonably expect Laravel's asset() helper and
         * its very different behaviour.
         */
        Blade::directive('vdasset', fn (string $expression) => "<?php echo e(".Asset::class."::url({$expression})); ?>");

        // Page links use Bootstrap markup, not Laravel's default Tailwind markup.
        Paginator::useBootstrapFive();

        /*
         * The MTO sidebar badges and the top bar's review bell show the same
         * figures, and both partials render on every office page.
         *
         * Counted once and remembered on the request, because otherwise the
         * second partial runs the same queries again for an answer it already
         * has. Kept on the Request rather than in a static, so each request
         * really does count afresh.
         */
        View::composer(['partials.sidebar-admin', 'partials.topbar-admin'], function ($view): void {
            $request = request();
            $key = 'visitdasol.pending_review_counts';

            if (! $request->attributes->has($key)) {
                $request->attributes->set($key, [
                    'pendingOwnerCount' => User::pendingOwners()->count(),
                    'pendingResortCount' => Resort::pending()->count(),
                    'pendingSpotCount' => Destination::pending()->count(),

                    /*
                     * Unchecked GCash transfers are deliberately NOT
                     * counted here. A badge on the sidebar costs one
                     * query on every office page, and the reports page
                     * was already at the ceiling QueryBudgetTest allows.
                     *
                     * Nothing is lost: the figure appears where it can
                     * be acted on — a "Not yet checked" tile on the
                     * collections report, linking straight to the
                     * screen that clears it.
                     */
                ]);
            }

            $view->with($request->attributes->get($key));
        });

        // The password reset email, in Visit Dasol's own words.
        ResetPassword::toMailUsing(function (User $user, string $token): MailMessage {
            $url = route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]);
            $minutes = (int) config('auth.passwords.users.expire', 60);

            /*
             * THE SAME LINK, TWO DIFFERENT LETTERS.
             *
             * Staff created by the office have never signed in and
             * have no password to reset — the account was made with a
             * random secret nobody holds. Telling them "someone asked
             * to reset your password" would be a puzzle at best and a
             * phishing scare at worst, so an account that has never
             * confirmed its address is welcomed instead.
             */
            $isInvitation = $user->email_verified_at === null
                && $user->role->isEnvironmentalFeeStaff();

            if ($isInvitation) {
                return (new MailMessage)
                    ->subject('Set up your Visit Dasol staff account')
                    ->greeting('Hello '.$user->first_name.',')
                    ->line('The Municipal Tourism Office of Dasol has created an account for you as '
                        .lcfirst($user->role->label()).'.')
                    ->line('Choose your own password to finish setting it up. Nobody at the office '
                        .'knows it, and nobody can see it.')
                    ->action('Set your password', $url)
                    ->line("This link works for {$minutes} minutes and can only be used once. "
                        .'If it expires, ask the office to send another.')
                    ->line('If you were not expecting this, tell the tourism office and do not use the link.');
            }

            return (new MailMessage)
                ->subject('Reset your Visit Dasol password')
                ->greeting('Hello '.$user->first_name.',')
                ->line('Someone asked to reset the password for your Visit Dasol account.')
                ->action('Choose a new password', $url)
                ->line("This link works for {$minutes} minutes and can only be used once.")
                ->line('If you did not ask for this, you can ignore this email. Your password stays the same.');
        });

        // Minimum password strength everywhere Password::defaults() is used
        // (registration and password changes).
        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        // Short, stable names stored in activity_logs.subject_type
        // instead of PHP class names.
        Relation::enforceMorphMap([
            'user' => User::class,
            'owner_profile' => OwnerProfile::class,
            'resort' => Resort::class,
            'resort_image' => ResortImage::class,
            'amenity' => Amenity::class,
            'room' => Room::class,
            'room_image' => RoomImage::class,
            'booking' => Booking::class,
            'destination_category' => DestinationCategory::class,
            'destination' => Destination::class,
            'destination_image' => DestinationImage::class,
            'announcement' => Announcement::class,
            'activity_log' => ActivityLog::class,
            'review' => Review::class,
            'payment' => Payment::class,

            // The municipal environmental fee. Its audit entries are the
            // record of a public collection, so they need the same short,
            // stable names as everything else here.
            'environmental_fee_policy' => EnvironmentalFeePolicy::class,
            'environmental_fee_checkpoint' => EnvironmentalFeeCheckpoint::class,
            'environmental_fee_collection' => EnvironmentalFeeCollection::class,
            'environmental_fee_remittance' => EnvironmentalFeeRemittance::class,
            'resort_extra' => ResortExtra::class,
            'booking_extra' => BookingExtra::class,
            'environmental_fee_account' => EnvironmentalFeeAccount::class,
        ]);
    }
}
