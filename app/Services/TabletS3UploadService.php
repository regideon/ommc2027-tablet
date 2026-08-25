<?php

namespace App\Services;

use App\Models\CustomerProfile;
use App\Models\SalescallImage;
use Illuminate\Support\Facades\Storage;

class TabletS3UploadService
{
    public function ensureSalescallImageUploaded(SalescallImage $image): string
    {
        return $this->ensureUploaded(
            localPath: $image->local_path,
            existingKey: $image->s3_key,
            generatedKey: $this->salescallImageKey($image),
        );
    }

    public function ensureProfileSignatureUploaded(CustomerProfile $profile): string
    {
        return $this->ensureUploaded(
            localPath: $profile->signature_path,
            existingKey: $profile->signature_s3_key,
            generatedKey: $this->customerProfileSignatureKey($profile),
        );
    }

    private function salescallImageKey(SalescallImage $image): string
    {
        $salescallIdentity = $image->salescall?->local_uuid
            ?? ($image->salescall?->server_id ? (string) $image->salescall->server_id : (string) $image->salescall_id);

        $extension = strtolower(pathinfo((string) $image->local_path, PATHINFO_EXTENSION)) ?: 'jpg';

        return "salescall_images/{$salescallIdentity}/{$image->local_uuid}.{$extension}";
    }

    private function customerProfileSignatureKey(CustomerProfile $profile): string
    {
        return "customer_profiles/signatures/{$profile->local_uuid}.png";
    }

    private function ensureUploaded(?string $localPath, ?string $existingKey, string $generatedKey): string
    {
        if (! is_string($localPath) || $localPath === '' || ! is_file($localPath)) {
            throw new \RuntimeException('Local upload source file not found.');
        }

        $disk = Storage::disk('s3');
        $key = $existingKey ?: $generatedKey;

        // Repeated sync attempts must be idempotent: if the stable key already
        // exists remotely, do nothing. Callers clear the key when a local file is
        // explicitly replaced, which is the signal to re-upload.
        if ($existingKey && $disk->exists($existingKey)) {
            return $existingKey;
        }

        $stream = fopen($localPath, 'r');

        if ($stream === false) {
            throw new \RuntimeException('Unable to open local upload source file.');
        }

        try {
            if (! $disk->put($key, $stream)) {
                throw new \RuntimeException('Failed to upload file to S3.');
            }
        } finally {
            fclose($stream);
        }

        return $key;
    }
}
