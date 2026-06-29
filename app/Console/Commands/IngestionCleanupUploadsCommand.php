<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class IngestionCleanupUploadsCommand extends Command
{
    protected $signature = 'ingestion:cleanup-uploads';

    protected $description = 'Delete orphan CSV upload files older than 24 hours';

    public function handle(): int
    {
        $uploadsBase = storage_path('app/ingestion/uploads');

        if (! is_dir($uploadsBase)) {
            $this->line('No uploads directory found — nothing to clean.');
            return self::SUCCESS;
        }

        $cutoff  = now()->subHours(24)->timestamp;
        $deleted = 0;

        foreach (glob($uploadsBase . '/*/*.csv') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $deleted++;
            }
        }

        $this->line("Deleted {$deleted} orphan upload file(s).");
        return self::SUCCESS;
    }
}
