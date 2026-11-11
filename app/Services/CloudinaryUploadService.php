<?php

namespace App\Services;

use App\Models\CSVData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class CloudinaryUploadService
{
    public function campaignSignature(string $campaignName): array
    {
        $cloudName = (string) config('services.cloudinary.cloud_name');
        $apiKey = (string) config('services.cloudinary.api_key');
        $secret = (string) config('services.cloudinary.api_secret');
        if ($cloudName === '' || $apiKey === '' || $secret === '') {
            throw new RuntimeException('Cloudinary signed uploads are not fully configured.');
        }

        $base = trim((string) config('services.cloudinary.base_folder', 'database'), '/ ');
        $slug = Str::slug($campaignName);
        if ($slug === '') {
            $slug = 'campaign';
        }
        $folder = ($base === '' ? 'database' : $base).'/campaigns/'.$slug;
        $parameters = [
            'asset_folder' => $folder,
            'public_id_prefix' => $folder,
            'timestamp' => now()->timestamp,
            'unique_filename' => 'true',
            'use_asset_folder_as_public_id_prefix' => 'false',
            'use_filename' => 'true',
        ];
        ksort($parameters);
        $signaturePayload = collect($parameters)
            ->map(fn ($value, $key) => $key.'='.$value)
            ->implode('&');

        return [
            'cloud_name' => $cloudName,
            'api_key' => $apiKey,
            'signature' => sha1($signaturePayload.$secret),
            'timestamp' => $parameters['timestamp'],
            'folder' => $folder,
            'upload_url' => "https://api.cloudinary.com/v1_1/{$cloudName}/auto/upload",
            'upload_parameters' => $parameters,
            'expires_at' => now()->addMinutes(55)->toIso8601String(),
        ];
    }

    public function contactMediaSignature(CSVData $contact): array
    {
        $base = trim((string) config('services.cloudinary.base_folder', 'database'), '/ ');
        $folder = ($base === '' ? 'database' : $base).'/contacts/'.$contact->id;

        return $this->signature($folder);
    }

    private function signature(string $folder): array
    {
        $cloudName = (string) config('services.cloudinary.cloud_name');
        $apiKey = (string) config('services.cloudinary.api_key');
        $secret = (string) config('services.cloudinary.api_secret');
        if ($cloudName === '' || $apiKey === '' || $secret === '') {
            throw new RuntimeException('Cloudinary signed uploads are not fully configured.');
        }

        $parameters = [
            'asset_folder' => $folder,
            'public_id_prefix' => $folder,
            'timestamp' => now()->timestamp,
            'unique_filename' => 'true',
            'use_asset_folder_as_public_id_prefix' => 'false',
            'use_filename' => 'true',
        ];
        ksort($parameters);
        $signaturePayload = collect($parameters)
            ->map(fn ($value, $key) => $key.'='.$value)
            ->implode('&');

        return [
            'cloud_name' => $cloudName,
            'api_key' => $apiKey,
            'signature' => sha1($signaturePayload.$secret),
            'timestamp' => $parameters['timestamp'],
            'folder' => $folder,
            'upload_url' => "https://api.cloudinary.com/v1_1/{$cloudName}/auto/upload",
            'upload_parameters' => $parameters,
            'expires_at' => now()->addMinutes(55)->toIso8601String(),
        ];
    }

    /**
     * Build a signed Cloudinary delivery URL for an image asset. Works for
     * public (type=upload) assets and continues to work if the account later
     * enforces strict/signed delivery. Returns null when Cloudinary is not
     * configured or the asset is not an image.
     */
    public static function signedImageUrl(?string $publicId, ?string $format): ?string
    {
        $cloudName = (string) config('services.cloudinary.cloud_name');
        $secret = (string) config('services.cloudinary.api_secret');
        if ($cloudName === '' || $secret === '' || ! $publicId) {
            return null;
        }

        $path = $publicId.($format ? '.'.ltrim($format, '.') : '');
        $signature = substr(
            rtrim(strtr(base64_encode(sha1($path.$secret, true)), '+/', '-_'), '='),
            0,
            8
        );

        return "https://res.cloudinary.com/{$cloudName}/image/upload/s--{$signature}--/{$path}";
    }

    public function destroyAsset(string $publicId, string $resourceType): void
    {
        $cloudName = (string) config('services.cloudinary.cloud_name');
        $apiKey = (string) config('services.cloudinary.api_key');
        $secret = (string) config('services.cloudinary.api_secret');
        if ($cloudName === '' || $apiKey === '' || $secret === '') {
            throw new RuntimeException('Cloudinary asset deletion is not configured.');
        }
        if (! in_array($resourceType, ['image', 'video', 'raw'], true)) {
            throw new RuntimeException('Unsupported Cloudinary resource type.');
        }
        $parameters = ['invalidate' => 'true', 'public_id' => $publicId, 'timestamp' => now()->timestamp];
        ksort($parameters);
        $payload = collect($parameters)->map(fn ($value, $key) => $key.'='.$value)->implode('&');
        $response = Http::asForm()->timeout(20)->post(
            "https://api.cloudinary.com/v1_1/{$cloudName}/{$resourceType}/destroy",
            [...$parameters, 'api_key' => $apiKey, 'signature' => sha1($payload.$secret)]
        );
        if (! $response->successful() || ! in_array($response->json('result'), ['ok', 'not found'], true)) {
            throw new RuntimeException('Cloudinary could not delete the asset.');
        }
    }
}
