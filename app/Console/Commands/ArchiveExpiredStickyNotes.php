<?php

namespace App\Console\Commands;

use App\Models\StickyNote;
use Illuminate\Console\Command;

class ArchiveExpiredStickyNotes extends Command
{
    protected $signature = 'sticky-notes:archive-expired';

    protected $description = 'Archive active sticky notes whose seven-day active window has expired';

    public function handle(): int
    {
        $now = now();
        $count = StickyNote::query()
            ->where('status', StickyNote::STATUS_ACTIVE)
            ->where('archive_due_at', '<=', $now)
            ->update([
                'status' => StickyNote::STATUS_ARCHIVED,
                'archived_at' => $now,
                'archived_by' => null,
                'updated_at' => $now,
            ]);

        $this->info("Archived {$count} expired sticky note(s).");

        return self::SUCCESS;
    }
}
