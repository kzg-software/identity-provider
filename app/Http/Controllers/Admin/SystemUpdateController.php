<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\UpdateChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SystemUpdateController extends Controller
{
    public function index(): View
    {
        // Veraltetes Ergebnis im Hintergrund auffrischen – blockiert den
        // Seitenaufruf nicht (läuft nach dem Ausliefern der Antwort und
        // funktioniert damit auch ohne laufenden Queue-Worker).
        if (UpdateChecker::isStale()) {
            dispatch(fn () => UpdateChecker::refresh())->afterResponse();
        }

        $status = UpdateChecker::status();

        $changelogHtml = null;
        if (! empty($status['release']['body'])) {
            $changelogHtml = Str::markdown($status['release']['body'], [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]);
        }

        [$badgeColor, $badgeText] = match (true) {
            (bool) $status['error'] => ['red', 'Prüfung fehlgeschlagen'],
            $status['update_available'] => ['amber', 'Update verfügbar'],
            $status['latest'] !== null => ['green', 'Aktuell'],
            default => ['gray', 'Noch nicht geprüft'],
        };

        return view('admin.updates.index', [
            'status' => $status,
            'badgeColor' => $badgeColor,
            'badgeText' => $badgeText,
            'changelogHtml' => $changelogHtml,
            'repositoryUrl' => UpdateChecker::repositoryUrl(),
            'currentReleaseUrl' => UpdateChecker::releaseUrl($status['current']),
            'latestReleaseUrl' => $status['release']['url'] ?? UpdateChecker::releaseUrl($status['latest']),
        ]);
    }

    public function check(): RedirectResponse
    {
        $status = UpdateChecker::refresh();

        if ($status['error']) {
            return back()->with('error', 'Prüfung fehlgeschlagen: '.$status['error']);
        }

        return back()->with('status', $status['update_available']
            ? 'Neue Version verfügbar: '.$status['latest'].' (installiert: '.$status['current'].').'
            : 'Das System ist auf dem neuesten Stand ('.$status['current'].').');
    }
}
