<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Saves uploaded images on the public disk and cleans up the files they replace.
 *
 * Laravel generates a random file name, so the visitor's original file name
 * never becomes part of a URL. Only the relative path is stored in the database.
 */
class ImageUploadService
{
    public function __construct(private readonly string $disk = 'public') {}

    /**
     * @param  string  $folder  e.g. "resorts/12/gallery"
     */
    public function store(UploadedFile $file, string $folder): string
    {
        return $file->store($folder, $this->disk);
    }

    /**
     * Saves the new file, then removes the old one.
     */
    public function replace(?string $currentPath, UploadedFile $file, string $folder): string
    {
        $path = $this->store($file, $folder);

        $this->delete($currentPath);

        return $path;
    }

    public function delete(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        Storage::disk($this->disk)->delete($path);
    }

    /** Removes a whole folder, for example when a record is deleted. */
    public function deleteFolder(string $folder): void
    {
        Storage::disk($this->disk)->deleteDirectory($folder);
    }
}
