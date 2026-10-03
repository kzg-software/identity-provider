<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\ScimConnection;
use App\Scim\ScimClient;
use App\Scim\ScimException;
use App\Scim\ScimSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ScimController extends Controller
{
    public function save(Request $request, Application $application): RedirectResponse
    {
        $connection = $application->scimConnection;

        $data = $request->validate([
            'base_url' => 'required|url|max:2048',
            'auth_token' => ($connection ? 'nullable' : 'required').'|string|max:4096',
            'on_removal' => 'required|in:deactivate,delete',
        ]);

        $syncUsers = $request->boolean('sync_users');
        $attributes = [
            'base_url' => rtrim($data['base_url'], '/'),
            'on_removal' => $data['on_removal'],
            'sync_users' => $syncUsers,
            'sync_groups' => $syncUsers && $request->boolean('sync_groups'),
            'is_active' => $request->boolean('is_active'),
        ];

        if (! empty($data['auth_token'])) {
            $attributes['auth_token'] = $data['auth_token'];
        }

        if ($connection) {
            $connection->update($attributes);
        } else {
            $application->scimConnection()->create($attributes);
        }

        AuditLog::record('scim.connection_saved', $request->user(), ['url' => $attributes['base_url']], $application);

        return $this->back($application)->with('status', 'SCIM-Verbindung wurde gespeichert.');
    }

    public function test(Request $request, Application $application): RedirectResponse
    {
        $connection = $application->scimConnection;
        abort_unless($connection, 404);

        try {
            (new ScimClient($connection))->serviceProviderConfig();
        } catch (ScimException $e) {
            return $this->back($application)->withErrors(['scim' => 'Verbindungstest fehlgeschlagen: '.$e->getMessage()]);
        }

        return $this->back($application)->with('status', 'Verbindung erfolgreich: Die Zielanwendung antwortet auf SCIM.');
    }

    public function sync(Request $request, Application $application, ScimSyncService $service): RedirectResponse
    {
        $connection = $application->scimConnection;
        abort_unless($connection, 404);

        $stats = $service->sync($connection);

        if ($stats['errors'] !== []) {
            return $this->back($application)->withErrors(['scim' => 'Synchronisierung mit Fehlern: '.implode(' | ', array_slice($stats['errors'], 0, 3))]);
        }

        return $this->back($application)->with('status', sprintf(
            'Synchronisiert: %d angelegt, %d aktualisiert, %d entfernt.',
            $stats['created'], $stats['updated'], $stats['removed'],
        ));
    }

    public function destroy(Request $request, Application $application): RedirectResponse
    {
        $application->scimConnection?->delete();

        AuditLog::record('scim.connection_deleted', $request->user(), [], $application);

        return $this->back($application)->with('status', 'SCIM-Verbindung wurde entfernt. Bereits angelegte Konten in der Zielanwendung bleiben bestehen.');
    }

    private function back(Application $application): RedirectResponse
    {
        return redirect()->route('admin.applications.show', ['application' => $application, 'tab' => 'provisionierung']);
    }
}
