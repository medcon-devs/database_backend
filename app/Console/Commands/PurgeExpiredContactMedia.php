<?php

namespace App\Console\Commands;

use App\Models\ContactMediaFile;
use App\Services\CloudinaryUploadService;
use Illuminate\Console\Command;
use RuntimeException;

class PurgeExpiredContactMedia extends Command
{
    protected $signature = 'contact-media:purge-expired';

    protected $description = 'Permanently remove expired soft-deleted contact media assets from Cloudinary';

    public function handle(CloudinaryUploadService $cloudinary): int
    {
        $cutoff = now()->subDays(max(1, (int) config('contact_media.recovery_days', 30)));
        $purged = 0;
        $failed = 0;

        ContactMediaFile::onlyTrashed()
            ->whereNull('asset_purged_at')
            ->where('deleted_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($files) use ($cloudinary, &$purged, &$failed) {
                foreach ($files as $file) {
                    try {
                        $cloudinary->destroyAsset($file->cloudinary_public_id, $file->resource_type);
                        $file->forceFill(['asset_purged_at' => now()])->save();
                        $purged++;
                    } catch (RuntimeException $exception) {
                        report($exception);
                        $failed++;
                    }
                }
            });

        $this->info("Purged {$purged} expired contact media asset(s); {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
