<?php

namespace App\Http\Middleware;

use App\Enums\PaymentProvider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every web page.
 *
 * The Content Security Policy is the important one: the browser will only
 * run scripts that come from this site's own files. Even if someone managed
 * to slip markup into a resort description, a <script> tag or an onclick
 * handler in it would simply not run.
 *
 *   script-src 'self'   no inline scripts, no scripts from other sites
 *   img-src             this site, data: URIs, and OpenStreetMap map tiles
 *   style-src           'unsafe-inline' is allowed because Blade templates use
 *                       a few style="" attributes; styles cannot run code
 *   frame-ancestors     no other site can put these pages in a frame
 *   form-action         forms can only be sent to this site
 *
 * Signed-in pages also get Cache-Control: no-store (see below).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Map tiles are pictures, so their servers are named in img-src, and
        // the routing service is reached by fetch(), so it belongs to
        // connect-src. Both are read from config/visitdasol.php rather than
        // written twice, so changing a provider there cannot leave the policy
        // behind and silently break the map.
        $tiles = $this->hosts([
            config('visitdasol.map.tile_url'),
            config('visitdasol.map.satellite_url'),

            // The transparent name and road overlays drawn on the
            // imagery. Named here even though they share a host with it
            // today: the day somebody moves the imagery to another
            // provider, the labels must not silently stop loading.
            config('visitdasol.map.satellite_labels_url'),
            config('visitdasol.map.satellite_roads_url'),
        ], 'tile.openstreetmap.org');

        $routing = $this->hosts([config('visitdasol.map.routing_url')]);

        /*
         * Paying means leaving this site for the gateway's own page, and the
         * browser treats the redirect that follows the "Pay" form as a form
         * submission. Without the gateway named here, form-action 'self'
         * silently blocks it.
         *
         * Only gateways whose keys are actually set widen the policy, so an
         * installation that takes no payments keeps exactly the policy it had.
         */
        $checkout = $this->hosts(array_merge(
            PaymentProvider::PayMongo->isConfigured() ? ['https://checkout.paymongo.com'] : [],
            PaymentProvider::PayPal->isConfigured()
                ? ['https://www.paypal.com', 'https://www.sandbox.paypal.com']
                : [],
        ));

        $policy = implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            'img-src '.trim("'self' data: ".$tiles),
            "font-src 'self'",
            'connect-src '.trim("'self' ".$routing),
            "object-src 'none'",
            "base-uri 'self'",
            'form-action '.trim("'self' ".$checkout),
            "frame-ancestors 'self'",
        ]);

        $headers = [
            'Content-Security-Policy' => $policy,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), payment=(), geolocation=(self)',
        ];

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        // Pages shown to a signed-in person must never be kept by the browser.
        // Otherwise, after signing out on a shared computer (an internet cafe,
        // the office front desk), pressing Back shows the previous person's
        // reservations and contact details again. Found in Phase 20 testing.
        if ($request->user() !== null) {
            $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }

    /**
     * The https:// origins of the given URLs, ready to drop into a policy.
     *
     * Only the host is taken, never the path, so a provider cannot widen the
     * policy by what it puts after the domain. Anything unparseable is left
     * out rather than guessed at.
     *
     * @param  list<string|null>  $urls
     */
    private function hosts(array $urls, ?string $fallback = null): string
    {
        $hosts = [];

        foreach ($urls as $url) {
            $host = is_string($url) && $url !== ''
                ? parse_url($url, PHP_URL_HOST)
                : null;

            if (is_string($host) && $host !== '') {
                $hosts[] = 'https://'.$host;
            }
        }

        if ($hosts === [] && $fallback !== null) {
            $hosts[] = 'https://'.$fallback;
        }

        return implode(' ', array_unique($hosts));
    }
}
