<?php

namespace App\Services;

use App\Support\Version;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Prüft das GitHub-Repository auf ein neueres veröffentlichtes Release.
 *
 * Das Ergebnis (neueste Version + Changelog) wird im Cache gehalten;
 * {@see status()} liest ausschließlich aus dem Cache (kein Netzwerkzugriff),
 * {@see refresh()} führt die eigentliche API-Abfrage durch.
 */
class UpdateChecker
{
    private const CACHE_KEY = 'updates:status';

    /**
     * Die Prüfung ist immer aktiv; einzige Voraussetzung ist ein
     * konfiguriertes Ziel-Repository (Default gesetzt).
     */
    public static function enabled(): bool
    {
        return filled(config('updates.repository'));
    }

    public static function repository(): string
    {
        return trim((string) config('updates.repository'), '/ ');
    }

    public static function repositoryUrl(): string
    {
        return rtrim((string) config('updates.repository_url'), '/');
    }

    /**
     * URL zur Release-/Tag-Seite für einen Tag (Fallback: Repo-Startseite).
     */
    public static function releaseUrl(?string $tag = null): string
    {
        if ($tag === null || Version::toSemver($tag) === null) {
            return self::repositoryUrl();
        }

        return self::repositoryUrl().'/releases/tag/'.$tag;
    }

    /**
     * Zwischengespeicherter Status – ohne Netzwerkzugriff.
     *
     * @return array{
     *   current: string,
     *   current_is_release: bool,
     *   latest: string|null,
     *   release: array{tag: string, name: string|null, body: string|null, url: string, published_at: string|null}|null,
     *   update_available: bool,
     *   checked_at: string|null,
     *   error: string|null,
     *   enabled: bool
     * }
     */
    public static function status(): array
    {
        $current = Version::current();
        $cached = Cache::get(self::CACHE_KEY);
        $cached = is_array($cached) ? $cached : [];

        $latest = $cached['latest'] ?? null;

        return [
            'current' => $current,
            'current_is_release' => Version::isRelease(),
            'latest' => $latest,
            'release' => $cached['release'] ?? null,
            'update_available' => $latest !== null && Version::isNewer($latest, $current),
            'checked_at' => $cached['checked_at'] ?? null,
            'error' => $cached['error'] ?? null,
            'enabled' => self::enabled(),
        ];
    }

    public static function isStale(): bool
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (! is_array($cached) || empty($cached['checked_at'])) {
            return true;
        }

        $ttl = max(1, (int) config('updates.ttl_hours', 24));

        try {
            return CarbonImmutable::parse($cached['checked_at'])->addHours($ttl)->isPast();
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Fragt die GitHub-API ab und aktualisiert den Cache.
     * Gibt den neuen Status zurück (Form wie {@see status()}).
     */
    public static function refresh(): array
    {
        if (! self::enabled()) {
            return self::status();
        }

        $lock = Cache::lock('updates:refresh', 120);

        if (! $lock->get()) {
            // Ein anderer Prozess prüft bereits – dessen Ergebnis genügt.
            return self::status();
        }

        try {
            $release = self::fetchLatestRelease();

            Cache::put(self::CACHE_KEY, [
                'latest' => $release['tag'] !== '' ? Version::normalize($release['tag']) : null,
                'release' => $release,
                'error' => null,
                'checked_at' => now()->toIso8601String(),
            ], now()->addDays(30));
        } catch (Throwable $e) {
            Log::warning('Update-Prüfung fehlgeschlagen: '.$e->getMessage());

            $existing = Cache::get(self::CACHE_KEY);
            $existing = is_array($existing) ? $existing : [];
            $existing['error'] = $e->getMessage();
            $existing['checked_at'] = now()->toIso8601String();

            Cache::put(self::CACHE_KEY, $existing, now()->addDays(30));
        } finally {
            $lock->release();
        }

        return self::status();
    }

    /**
     * @return array{tag: string, name: string|null, body: string|null, url: string, published_at: string|null}
     */
    private static function fetchLatestRelease(): array
    {
        $repo = self::repository();
        $request = self::request();

        $response = $request->get("https://api.github.com/repos/{$repo}/releases/latest");

        // Kein veröffentlichtes Release -> auf den neuesten Git-Tag ausweichen.
        if ($response->status() === 404) {
            return self::fetchLatestTag($request, $repo);
        }

        $response->throw();
        $data = (array) $response->json();

        return [
            'tag' => (string) ($data['tag_name'] ?? ''),
            'name' => ($data['name'] ?? null) ?: null,
            'body' => ($data['body'] ?? null) ?: null,
            'url' => $data['html_url'] ?? (self::repositoryUrl().'/releases'),
            'published_at' => $data['published_at'] ?? null,
        ];
    }

    /**
     * @return array{tag: string, name: string|null, body: string|null, url: string, published_at: string|null}
     */
    private static function fetchLatestTag(PendingRequest $request, string $repo): array
    {
        $response = $request->get("https://api.github.com/repos/{$repo}/tags", ['per_page' => 1]);
        $response->throw();

        $tag = $response->json('0.name');

        if (! $tag) {
            throw new RuntimeException('Das Repository hat weder Releases noch Tags.');
        }

        return [
            'tag' => (string) $tag,
            'name' => (string) $tag,
            'body' => null,
            'url' => self::repositoryUrl().'/releases/tag/'.$tag,
            'published_at' => null,
        ];
    }

    private static function request(): PendingRequest
    {
        $request = Http::acceptJson()
            ->withHeaders([
                'User-Agent' => 'identity-provider-update-check',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->timeout(8);

        if ($token = config('updates.token')) {
            $request = $request->withToken($token);
        }

        return $request;
    }
}
