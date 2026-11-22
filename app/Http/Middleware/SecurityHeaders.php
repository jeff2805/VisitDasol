<?php

namespace App\Http\Middleware;

use App\Enums\PaymentProvider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every web page.
 *
 * Map tile hosts are allowed through img-src and routing services
 * through connect-src. Both are read from config/visitdasol.php, so a
 * provider is changed in one place and the policy follows.
 *
 * Uploaded photos are allowed through img-src from the public disk's
 * host when that disk is remote (Supabase Storage).
 *
 * Signed-in pages also get Cache-Control: no-store.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Map tile providers are read from config/visitdasol.php.
        $tiles = $this->hosts([
            config('visitdasol.map.tile_url'),
            config('visitdasol.map.satellite_url'),
            config('visitdasol.map.satellite_labels_url'),
            config('visitdasol.map.satellite_roads_url'),

            /*
             * api.mapbox.com was named here by hand while the street
             * and satellite layers came from Mapbox. They come from
             * OpenStreetMap and Esri now, and both are read from the
             * config above, so a hardcoded host would only permit a
             * provider this application no longer calls.
             *
             * Nothing replaces it: every tile host belongs in
             * config/visitdasol.php, where changing a provider updates
             * the policy with it.
             */
        ], 'tile.openstreetmap.org');

        $routing = $this->hosts([
            config('visitdasol.map.routing_url'),
            config('visitdasol.map.routing_table_url'),
        ]);

        /*
         * Payment gateway permissions.
         */
        $checkout = $this->hosts(array_merge(
            PaymentProvider::PayMongo->isConfigured()
                ? ['https://checkout.paymongo.com']
                : [],
            PaymentProvider::PayPal->isConfigured()
                ? ['https://www.paypal.com', 'https://www.sandbox.paypal.com']
                : [],
        ));

        /*
         * WHERE THE CARD NUMBER IS ALLOWED TO GO.
         *
         * The in-page card form posts the number from the browser straight
         * to PayMongo's API, so that one host has to be reachable by
         * fetch(). Without this line the request is refused by the policy
         * and the form fails with nothing in the server log to explain it —
         * the browser console is the only place it shows.
         *
         * Added only when the publishable key exists, because that is the
         * only time any card form is drawn.
         */
        $cardApi = $this->hosts(
            filled(config('services.paymongo.public'))
                ? ['https://api.paymongo.com']
                : []
        );

        $policy = implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            'img-src '.trim("'self' data: ".self::storageOrigin().' '.$tiles),
            "font-src 'self'",
            // The routing service, and PayMongo's API when a card form is
            // drawn — that is the one request carrying a card number, and
            // it goes to them rather than to us.
            'connect-src '.trim("'self' ".$routing.' '.$cardApi),
            "object-src 'none'",
            "base-uri 'self'",
            'form-action '.trim("'self' ".$checkout),
            "frame-ancestors 'self'",
            /*
             * Where a card may send the visitor next: their own bank, for
             * the 3-D Secure step. The bank is not knowable in advance —
             * it is whichever issued the card — so this cannot be a list
             * of hosts, and the redirect is a top-level navigation rather
             * than a frame either way.
             */
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

        // Prevent caching of authenticated pages.
        if ($request->user() !== null) {
            $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }

    /**
     * Extract HTTPS origins from configured URLs.
     *
     * @param list<string|null> $urls
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

    /**
     * When uploads live on a remote disk (Supabase Storage), the browser
     * must be allowed to load images from that host too.
     */
    private static function storageOrigin(): string
    {
        if (config('filesystems.disks.public.driver') === 'local') {
            return '';
        }

        $url = (string) config('filesystems.disks.public.url');
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return $scheme && $host ? "{$scheme}://{$host}" : '';
    }
}