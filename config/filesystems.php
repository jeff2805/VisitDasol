<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

    /*
    | WHERE UPLOADED PHOTOS GO.
    |
    | Two shapes of the same disk. On a server with its own filesystem this
    | is storage/app/public behind the public/storage link; on the deployed
    | container it is Supabase Storage, because a container's filesystem is
    | wiped on every redeploy and photos uploaded to it would not survive
    | the week.
    |
    | THE SWITCH IS AN ENV VAR, AND IT FAILS QUIETLY. With PUBLIC_DISK_DRIVER
    | unset, this falls through to the local branch — no error, no warning,
    | just uploads written into a container that is about to be thrown away
    | and URLs pointing at a public/storage link that was never created
    | there. `php artisan visitdasol:check-production` says plainly which of
    | the two is in force and whether it actually works.
    |
    */
    'public' => env('PUBLIC_DISK_DRIVER', 'local') === 's3' ? [
        'driver' => 's3',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION'),
        'bucket' => env('AWS_BUCKET'),
        'url' => env('AWS_URL'),
        'endpoint' => env('AWS_ENDPOINT'),
        'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),

        /*
         * Every upload is world-readable, said here rather than left to the
         * bucket's own default.
         *
         * This was missing, and the local branch below has always had it.
         * These are resort photographs meant to be seen by anybody browsing
         * the site; without it the object is created with whatever the
         * bucket happens to be set to, so a bucket left Private serves a 400
         * for a URL the application believes is fine — which is a broken
         * image with nothing in the log to explain it.
         */
        'visibility' => 'public',

        'request_checksum_calculation' => 'when_required',
        'response_checksum_validation' => 'when_required',
        'throw' => false,
    ] : [
        'driver' => 'local',
        'root' => storage_path('app/public'),
        'url' => env('APP_URL').'/storage',
        'visibility' => 'public',
        'throw' => false,
        'report' => false,
    ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
