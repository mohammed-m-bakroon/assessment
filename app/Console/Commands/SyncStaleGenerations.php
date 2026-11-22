<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\GenerationSynchronizer;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Console\Command;

/**
 * Webhook delivery isn't guaranteed, so every minute we poll Pixelforge for
 * generations that have been in flight without news for a while.
 */
class SyncStaleGenerations extends Command
{
    protected $signature = 'pixelforge:sync-stale {--after=60 : Seconds without an update before polling} {--limit=20}';

    protected $description = 'Poll Pixelforge for in-flight generations we have not heard about recently';

    public function handle(PixelforgeClient $client, GenerationSynchronizer $sync): int
    {
        $cutoff = now()->subSeconds((int) $this->option('after'));

        $generations = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', $cutoff))
            ->orderBy('last_synced_at')
            ->limit((int) $this->option('limit')) // stay well inside 60 requests/minute
            ->get();

        foreach ($generations as $generation) {
            try {
                $sync->apply($generation, $client->getGeneration($generation->provider_id));
            } catch (PixelforgeException $e) {
                $this->warn("{$generation->public_id}: {$e->errorCode}");
                if ($e->errorCode === 'rate_limit_exceeded') {
                    break;
                }
            }
        }

        $this->info("Checked {$generations->count()} generation(s).");

        return self::SUCCESS;
    }
}
