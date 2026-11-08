<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * THE PRIVATE DISK IS FAKED FOR THE WHOLE SUITE.
         *
         * A booking request now has to carry a valid ID, so most of this
         * suite posts a file. Faking 'local' in one place rather than in
         * thirty setUp methods means a test cannot forget to, and quietly
         * leave somebody's fake passport in storage/app/private.
         *
         * Tests that assert about the stored document still call
         * Storage::fake('local') themselves to capture the disk; calling
         * it twice is harmless.
         */
        Storage::fake('local');
    }

    /**
     * A valid ID good enough for StoreBookingRequest, which requires one.
     *
     * An image rather than a PDF because the rules check the real content
     * type, not the extension, and fake()->image() produces real JPEG
     * bytes. One helper so that a change to what counts as an acceptable
     * ID is a change in one place.
     */
    protected function validId(string $name = 'valid-id.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 600, 400);
    }
}
