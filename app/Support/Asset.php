<?php

namespace App\Support;

/**
 * URLs for this project's own CSS and JavaScript, stamped so a browser picks
 * up a change.
 *
 * There is no build step here — no Vite, no manifest — so nothing renames a
 * file when its contents change. Without a stamp, a visitor who has been to
 * the site before keeps running whatever version their browser cached, which
 * can be months old, and a fix that is live on the server simply does not
 * reach them.
 *
 * Before this, the stamp was written out by hand at each call site and only
 * two of the nine scripts and stylesheets actually had one.
 *
 * The stamp is the file's own modification time, so it changes exactly when
 * the file does and never otherwise.
 */
class Asset
{
    public static function url(string $path): string
    {
        $file = public_path($path);

        // A missing file still gets a URL: a broken <script> tag is easier to
        // find in the browser's console than a page that silently fails.
        $stamp = is_file($file) ? filemtime($file) : null;

        return asset($path).($stamp ? '?v='.$stamp : '');
    }
}
