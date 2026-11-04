<?php

namespace App\Models;

use App\Models\Concerns\ResolvesPublicImage;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A resort's own GCash account, for guests paying by hand.
 *
 * One per resort. `active` is the switch the owner controls, and it is
 * what PaymentPreference::isAvailable() reads: an account switched off is
 * not offered and is not accepted.
 */
class ResortManualPaymentAccount extends Model
{
    use ResolvesPublicImage;

    protected $guarded = ['id'];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function resort(): BelongsTo
    {
        return $this->belongsTo(Resort::class);
    }

    /**
     * The QR code's address, or null when there is not a usable one.
     *
     * WHY THIS IS NOT asset('storage/'.$path) ANY MORE.
     *
     * Two controllers built the URL that way by hand, and it is wrong in
     * both of the ways ResolvesPublicImage was written to fix:
     *
     *   - The public disk is only served out of public/storage when its
     *     driver is local. This deployment can run it on S3 (Supabase
     *     Storage, PUBLIC_DISK_DRIVER=s3), where that path points at
     *     nothing and every QR code on the site is a broken image.
     *
     *   - A row can outlive its file. asset() answers with a URL either
     *     way, so a deleted QR rendered as a broken-image icon beside the
     *     payment instructions.
     *
     * NULL RATHER THAN THE GREY PLACEHOLDER, which is the one way this
     * differs from a photograph. A placeholder where a QR code should be
     * invites a guest to scan it; null lets the page fall back to the
     * account name and number, which is the information they actually
     * need to pay.
     */
    protected function qrUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            $path = $this->qr_image;

            if (blank($path)) {
                return null;
            }

            $url = $this->publicImageUrl($path);

            // publicImageUrl() answers with the placeholder when a local
            // file has gone. For a QR code that is worse than nothing.
            return $url === $this->placeholderImageUrl() ? null : $url;
        });
    }

    /**
     * Whether this account can actually be offered to a guest.
     *
     * The QR is optional — an account name and a number are enough to
     * send money — so this asks about the switch and the details, not
     * about the picture.
     */
    public function isUsable(): bool
    {
        return (bool) $this->active
            && filled($this->account_name)
            && filled($this->gcash_number);
    }

    /** Removes the stored QR file, if there is one. Safe to call twice. */
    public function deleteQrFile(): void
    {
        if (filled($this->qr_image)) {
            Storage::disk('public')->delete($this->qr_image);
        }
    }
}
