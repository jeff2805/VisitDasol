<?php

use Illuminate\Support\Facades\Storage;

$from = Storage::build(['driver' => 'local', 'root' => storage_path('app/public')]);
$copied = 0;
$failed = 0;

foreach ($from->allFiles() as $file) {
    if (str_starts_with(basename($file), '.')) {
        continue;
    }
    Storage::disk('public')->writeStream($file, $from->readStream($file)) ? $copied++ : $failed++;
}

echo "Copied {$copied}, failed {$failed}\n";
