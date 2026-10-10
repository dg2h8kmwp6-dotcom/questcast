<?php

namespace App\Console\Commands\Narrator;

use App\Models\Scene;
use App\Services\Narrator\Contracts\Narrator;
use App\Services\Narrator\SilentNarrator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Works out why the story is not being read aloud.
 *
 * Every way this feature fails is silent by design — a missing key falls back
 * to silence, a failed job only logs, and an unreachable mp3 is a 404 nobody
 * sees. That is correct behaviour in front of a room and useless behaviour when
 * you are trying to fix it, so this exists to say the quiet part out loud.
 *
 * Safe to run against production: it reads configuration and writes a single
 * temporary file to the narration disk, which it then removes.
 */
class Doctor extends Command
{
    protected $signature = 'narrator:doctor';

    protected $description = 'Diagnose why the story is not being read aloud';

    private int $problems = 0;

    public function handle(): int
    {
        $this->newLine();
        $this->components->info('Checking the storyteller.');

        $this->checkMigration();
        $this->checkColumnWidths();
        $this->checkDriver();
        $this->checkDisk();
        $this->checkStoredAudio();
        $this->checkQueue();
        $this->checkBundle();

        $this->newLine();

        if ($this->problems === 0) {
            $this->components->info('Nothing obviously wrong. If it is still silent, check the host screen console for "[narrator] gave up on".');

            return self::SUCCESS;
        }

        $this->components->error("{$this->problems} problem(s) found above.");

        return self::FAILURE;
    }

    private function checkMigration(): void
    {
        $columns = ['narration_audio_path', 'resolution_audio_path'];
        $missing = array_filter($columns, fn (string $c): bool => ! Schema::hasColumn('scenes', $c));

        $missing === []
            ? $this->ok('Migration', 'scenes carries its audio columns')
            : $this->bad('Migration', 'scenes is missing '.implode(', ', $missing).' — run php artisan migrate');
    }

    private function checkDriver(): void
    {
        $configured = (string) config('questcast.narrator.driver');
        $resolved = app(Narrator::class);

        if ($resolved instanceof SilentNarrator && $configured !== 'silent') {
            $this->bad('Narrator', "driver is [{$configured}] but resolved to the silent one — ELEVENLABS_API_KEY is not set in this environment");
        } elseif ($resolved instanceof SilentNarrator) {
            $this->bad('Narrator', 'driver is [silent], so nothing will ever be spoken');
        } else {
            $this->ok('Narrator', 'driver ['.$configured.'], key present');
        }

        filled(config('questcast.narrator.voice'))
            ? $this->ok('Voice', 'ELEVENLABS_VOICE_ID is set')
            : $this->bad('Voice', 'ELEVENLABS_VOICE_ID is empty — every passage will be skipped. Run php artisan narrator:voices');
    }

    /**
     * The one that bites on a platform with more than one instance.
     *
     * The queue worker writes the mp3; a web server serves it. On a local disk
     * those are two different filesystems, so the path is stored, the screen
     * renders a URL, and the browser gets a 404 nobody ever sees.
     */
    private function checkDisk(): void
    {
        $name = (string) config('questcast.narrator.disk');
        $known = array_keys(config('filesystems.disks', []));

        // NARRATION_DISK names a disk, not a location. A bucket URL dropped in
        // here looks plausible and fails with Laravel's own wording, which does
        // not hint at what was actually meant.
        if (! in_array($name, $known, true)) {
            $this->bad('Disk', "[{$name}] is not a configured disk. NARRATION_DISK takes a disk name, not a URL — one of: ".implode(', ', $known));

            return;
        }

        $driver = (string) config("filesystems.disks.{$name}.driver");

        if ($driver === 'local' && ! app()->environment('local', 'testing')) {
            $this->bad('Disk', "narration is on [{$name}], a local disk, outside local env — the worker that writes the audio and the server that serves it are different machines. Point NARRATION_DISK at object storage.");
        } else {
            $this->ok('Disk', "narration on [{$name}] ({$driver})");
        }

        try {
            $probe = 'narration/.doctor-'.bin2hex(random_bytes(4));
            $disk = Storage::disk($name);

            $disk->put($probe, 'probe');
            $url = $disk->url($probe);
            $disk->delete($probe);

            $this->ok('Disk write', 'readable back as '.$url);
        } catch (Throwable $e) {
            $this->bad('Disk write', $e->getMessage());
        }
    }

    private function checkStoredAudio(): void
    {
        if (! Schema::hasColumn('scenes', 'narration_audio_path')) {
            return;
        }

        $voiced = Scene::whereNotNull('narration_audio_path')->count();
        $total = Scene::count();

        if ($total === 0) {
            $this->ok('Scenes', 'no scenes yet, nothing to say');

            return;
        }

        if ($voiced === 0) {
            $this->bad('Scenes', "none of {$total} scenes has audio — the job never completed. Check the queue and the narrator above.");

            return;
        }

        $this->ok('Scenes', "{$voiced} of {$total} scenes have audio");

        $newest = Scene::whereNotNull('narration_audio_path')->latest('id')->first();
        $disk = Storage::disk(config('questcast.narrator.disk'));

        $disk->exists($newest->narration_audio_path)
            ? $this->ok('Newest file', $newest->narration_audio_path.' is on the disk')
            : $this->bad('Newest file', $newest->narration_audio_path.' is recorded but NOT on this disk — this is the ephemeral-disk failure.');
    }

    private function checkQueue(): void
    {
        if (config('queue.default') === 'sync') {
            $this->ok('Queue', 'sync, so nothing is queued');

            return;
        }

        try {
            $failed = DB::table('failed_jobs')->count();
            $pending = DB::table('jobs')->count();

            $this->ok('Queue', "{$pending} pending, {$failed} failed");

            if ($pending > 5) {
                $this->bad('Queue', "{$pending} jobs are waiting — is a worker running?");
            }

            $this->reportFailedJobs();
        } catch (Throwable $e) {
            $this->ok('Queue', 'not using database tables, skipped');
        }
    }

    /**
     * What actually went wrong, rather than how many times.
     *
     * A worker is a long-running process that builds its container once, so one
     * started before a provider or a job class existed will fail every job it
     * is handed and keep doing so until it is restarted. That failure only ever
     * shows up here, which is why the exception matters more than the count.
     */
    private function reportFailedJobs(): void
    {
        $recent = DB::table('failed_jobs')->latest('failed_at')->limit(3)->get();

        $stale = 0;

        foreach ($recent as $job) {
            $name = json_decode($job->payload, true)['displayName'] ?? 'unknown job';
            $line = strtok((string) $job->exception, "\n");
            $when = Carbon::parse($job->failed_at);

            if ($when->lt(now()->subHour())) {
                $stale++;
            }

            $this->bad('Failed job', "{$when->diffForHumans()} — {$name} — ".mb_substr((string) $line, 0, 140));

            if (str_contains((string) $job->exception, 'not instantiable')) {
                $this->bad('Likely cause', 'that worker booted before this code was deployed — restart it (php artisan queue:restart)');
            }
        }

        if ($stale > 0) {
            $this->ok('Note', "{$stale} of those are over an hour old and may already be fixed — php artisan queue:flush clears the history so this reads live");
        }
    }

    /**
     * The column that quietly killed whole rounds on MySQL.
     *
     * A varchar(255) holding storyteller-written text aborts the round with
     * "Data too long" the moment it overflows, and SQLite never reproduces it.
     */
    private function checkColumnWidths(): void
    {
        try {
            $type = Schema::getColumnType('scenes', 'image_prompt');
        } catch (Throwable) {
            return;
        }

        in_array(strtolower($type), ['text', 'longtext', 'mediumtext'], true)
            ? $this->ok('Column widths', 'image_prompt is text')
            : $this->bad('Column widths', "image_prompt is [{$type}], not text — a long prompt will abort the round. Run php artisan migrate");
    }

    private function checkBundle(): void
    {
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            $this->bad('Assets', 'no vite manifest — npm run build has not run here');

            return;
        }

        $entry = json_decode((string) file_get_contents($manifest), true)['resources/js/app.js']['file'] ?? null;
        $bundle = $entry ? public_path('build/'.$entry) : null;

        if (! $bundle || ! is_file($bundle)) {
            $this->bad('Assets', 'app.js is not in the manifest');

            return;
        }

        str_contains((string) file_get_contents($bundle), '[narrator]')
            ? $this->ok('Assets', 'the narrator is in the built bundle')
            : $this->bad('Assets', 'the built bundle predates the narrator — run npm run build and redeploy');
    }

    private function ok(string $label, string $detail): void
    {
        $this->components->twoColumnDetail("<fg=green>✔</> {$label}", "<fg=gray>{$detail}</>");
    }

    private function bad(string $label, string $detail): void
    {
        $this->problems++;
        $this->components->twoColumnDetail("<fg=red>✖</> {$label}", "<fg=yellow>{$detail}</>");
    }
}
