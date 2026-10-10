<?php

namespace App\Console\Commands\Narrator;

use App\Models\Scene;
use App\Services\Narrator\Contracts\Narrator;
use App\Services\Narrator\SilentNarrator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
    protected $signature = 'narrator:doctor {--live : Actually synthesise a line and play the whole chain through}';

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

        if ($this->option('live')) {
            $this->checkEndToEnd();
        }

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
        $this->reportPlatformDisks();

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

        if (! $this->checkDiskCredentials($name, $driver)) {
            return;
        }

        if ($driver === 'local' && ! app()->environment('local', 'testing')) {
            $this->bad('Disk', "narration is on [{$name}], a local disk, outside local env — the worker that writes the audio and the server that serves it are different machines. Point NARRATION_DISK at object storage.");
        } else {
            $this->ok('Disk', "narration on [{$name}] ({$driver})");
        }

        try {
            $probe = 'narration/.doctor-'.bin2hex(random_bytes(4));
            $disk = Storage::disk($name);

            // put() returns false rather than raising, because this disk is
            // configured with throw => false. Taking it on trust is how a
            // broken bucket reported itself as healthy.
            if ($disk->put($probe, 'probe') !== true || ! $disk->exists($probe)) {
                $this->bad('Disk write', 'the write did not land. This disk swallows its own errors (throw => false), so check the credentials above before anything else.');

                return;
            }

            $this->ok('Disk write', 'wrote and read back '.$probe);

            // Both, because they fail for different reasons and only one of
            // them is what the host screen actually hands the browser.
            $plainWorks = $this->reaches($disk->url($probe));

            $plainWorks
                ? $this->ok('Plain URL', 'HTTP 200 — the bucket serves files directly')
                : $this->ok('Plain URL', 'not readable directly, which is normal for a private bucket');

            try {
                $signed = $disk->temporaryUrl($probe, now()->addMinutes(5));

                $this->reaches($signed)
                    ? $this->ok('Signed URL', 'HTTP 200 — this is the link the big screen uses')
                    : $this->bad('Signed URL', 'signing works but the link still will not load — check AWS_URL and the bucket endpoint');
            } catch (Throwable) {
                // Falling back to the plain URL is the designed behaviour, so
                // this is only a problem when there is nothing to fall back to.
                $plainWorks
                    ? $this->ok('Signed URL', 'unsupported on this disk; the plain URL works, so links fall back to it')
                    : $this->bad('Signed URL', 'this disk cannot sign links and the plain URL is unreadable — nothing can play');
            }

            $disk->delete($probe);
        } catch (Throwable $e) {
            $this->bad('Disk write', $e->getMessage());
        }
    }

    /**
     * Which disks the platform has already wired up for us.
     *
     * Laravel Cloud attaches a bucket by injecting one variable describing the
     * disks it configures, rather than the individual AWS_* values. Pointing
     * NARRATION_DISK at a disk the platform never configured is then an easy
     * and completely silent mistake, so say out loud what is actually on offer.
     * Only names are read here — the blob also carries live credentials.
     */
    private function reportPlatformDisks(): void
    {
        $raw = env('LARAVEL_CLOUD_DISK_CONFIG');

        if (blank($raw)) {
            return;
        }

        $disks = collect(json_decode((string) $raw, true) ?: [])
            ->pluck('disk')
            ->filter()
            ->values();

        if ($disks->isEmpty()) {
            return;
        }

        $this->ok('Platform disks', 'the platform configures: '.$disks->implode(', '));

        $chosen = (string) config('questcast.narrator.disk');

        if (! $disks->contains($chosen)) {
            $this->bad('Disk choice', "NARRATION_DISK is [{$chosen}], which the platform does not configure. Set it to: ".$disks->implode(' or '));
        }
    }

    /**
     * Half-configured object storage fails in ways that read like other bugs.
     *
     * An empty bucket name does not announce itself: writes return false, URL
     * signing throws, and the SDK complains about HeadObject somewhere deep in
     * a queued job. Checking the values first turns all of that into one line.
     */
    private function checkDiskCredentials(string $name, string $driver): bool
    {
        if ($driver !== 's3') {
            return true;
        }

        $envFor = [
            'key' => 'AWS_ACCESS_KEY_ID',
            'secret' => 'AWS_SECRET_ACCESS_KEY',
            'region' => 'AWS_DEFAULT_REGION',
            'bucket' => 'AWS_BUCKET',
        ];

        $config = config("filesystems.disks.{$name}", []);

        $missing = collect($envFor)
            ->filter(fn (string $env, string $key): bool => blank($config[$key] ?? null))
            ->values()
            ->all();

        if ($missing !== []) {
            $this->bad('Disk config', 'empty: '.implode(', ', $missing));

            return false;
        }

        $this->ok('Disk config', 'credentials, region and bucket are all set');

        return true;
    }

    /**
     * A generated URL is not the same as a reachable one.
     *
     * The host screen fetches these mp3s straight from the browser, so every
     * other check here can pass while the room hears nothing: the file is
     * written, the path is stored, the link is rendered, and the fetch 403s.
     * This is the only check that looks at it the way a browser does.
     */
    private function fetch(string $label, string $url, string $hint): void
    {
        $this->reaches($url)
            ? $this->ok($label, 'HTTP 200 — a browser can play this')
            : $this->bad($label, $hint);
    }

    private function reaches(string $url): bool
    {
        try {
            return Http::timeout(10)->withoutRedirecting()->get($url)->status() === 200;
        } catch (Throwable) {
            return false;
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

        $newest = Scene::latest('id')->first();
        $age = Carbon::parse($newest->created_at);

        if ($voiced === 0) {
            // Nothing is ever voiced after the fact, so a pile of old silent
            // scenes says nothing at all about whether narration works now.
            $age->lt(now()->subHour())
                ? $this->ok('Scenes', "none of {$total} scenes has audio, but the newest is from {$age->diffForHumans()} — scenes are never voiced retroactively, so play a round to test this")
                : $this->bad('Scenes', "the newest scene is from {$age->diffForHumans()} and still has no audio — the job is failing, see below");

            return;
        }

        $this->ok('Scenes', "{$voiced} of {$total} scenes have audio, newest scene {$age->diffForHumans()}");

        $newest = Scene::whereNotNull('narration_audio_path')->latest('id')->first();
        $disk = Storage::disk(config('questcast.narrator.disk'));

        if (! $disk->exists($newest->narration_audio_path)) {
            $this->bad('Newest file', $newest->narration_audio_path.' is recorded but NOT on this disk.');

            return;
        }

        $this->ok('Newest file', $newest->narration_audio_path.' is on the disk');

        // The exact link the big screen would put in front of a browser.
        if ($url = $newest->narrationAudioUrl()) {
            $this->fetch('Newest playable', $url, 'the host screen cannot play this scene');
        }
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

    /**
     * The only check that proves the whole thing works.
     *
     * Everything above tests a link in the chain in isolation; this one speaks
     * a real line, stores it where a real scene would go, and fetches it back
     * the way the browser will. It costs a few hundred characters of quota,
     * which is why it is opt-in.
     */
    private function checkEndToEnd(): void
    {
        $this->newLine();
        $this->components->info('Running the whole chain for real.');

        $line = 'The cart track gives way to open meadow, and the light is going.';
        $utterance = app(Narrator::class)->speak($line);

        if (! $utterance) {
            $this->bad('Live synthesis', 'the narrator returned nothing — see the Narrator line above');

            return;
        }

        $this->ok('Live synthesis', number_format(strlen($utterance->audio)).' bytes of '.$utterance->contentType);

        $path = 'narration/.live-'.bin2hex(random_bytes(4)).'.'.$utterance->extension;

        try {
            $disk = Storage::disk(config('questcast.narrator.disk'));

            if ($disk->put($path, $utterance->audio, ['ContentType' => $utterance->contentType]) !== true) {
                $this->bad('Live store', 'the audio would not write to the narration disk');

                return;
            }
        } catch (Throwable $e) {
            // A disk missing its configuration throws while the client is being
            // built, long before anything can return false, so this has to be
            // caught or the command dies mid-diagnosis.
            $this->bad('Live store', $e->getMessage());

            return;
        }

        $this->ok('Live store', 'stored at '.$path);

        $this->fetch('Live playback', $disk->url($path), 'stored fine but a browser cannot fetch it');

        $disk->delete($path);
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
