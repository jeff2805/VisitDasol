<?php

namespace App\Services;

use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ImageUploadService
{
    public function __construct(private readonly string $disk = 'public') {}

    public function store(UploadedFile $file, string $folder): string
    {
        $uploadedFile = Cloudinary::upload($file->getRealPath(), [
            'folder' => $folder
        ]);
        
        return $uploadedFile->getSecurePath();
    }

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

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return; 
        }

        Storage::disk($this->disk)->delete($path);
    }

    public function deleteFolder(string $folder): void
    {
        
    }
}