<?php

namespace App\Observers;

use App\Models\CSVData;
use Illuminate\Support\Facades\Log;
use Throwable;

class CSVDataSearchObserver
{
    public function saved(CSVData $contact): void
    {
        $this->sync(fn () => $contact->searchable());
    }

    public function deleted(CSVData $contact): void
    {
        if (method_exists($contact, 'isForceDeleting') && $contact->isForceDeleting()) {
            $this->sync(fn () => $contact->unsearchable());

            return;
        }

        // Soft-deleted contacts stay searchable because the application allows
        // administrators to find and restore them.
        $this->sync(fn () => $contact->searchable());
    }

    public function restored(CSVData $contact): void
    {
        $this->sync(fn () => $contact->searchable());
    }

    private function sync(callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            // MariaDB remains the source of truth. A temporary search outage
            // must never prevent contacts from being created or updated.
            Log::warning('Contact saved, but its search index could not be synchronized.', [
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
