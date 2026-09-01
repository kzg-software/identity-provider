<?php

namespace App\Http\Controllers\Admin;

use App\Directory\DirectorySyncService;
use App\Directory\DirectoryTestService;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Directory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DirectoryController extends Controller
{
    public function index(): View
    {
        $directories = Directory::query()->orderByDesc('priority')->get();

        return view('admin.directories.index', compact('directories'));
    }

    public function create(): View
    {
        return view('admin.directories.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateDirectory($request);

        $directory = Directory::create($this->mapData($data, $request));

        AuditLog::record('admin.directory_created', $request->user(), ['directory_id' => $directory->id]);

        return redirect()->route('admin.directories.show', $directory)->with('status', 'Verzeichnis wurde angelegt.');
    }

    public function show(Directory $directory): View
    {
        $directory->load(['directoryGroups' => fn ($q) => $q->orderBy('name')->limit(50)]);

        return view('admin.directories.show', compact('directory'));
    }

    public function edit(Directory $directory): View
    {
        return view('admin.directories.edit', compact('directory'));
    }

    public function update(Request $request, Directory $directory): RedirectResponse
    {
        $data = $this->validateDirectory($request, $directory);
        $mapped = $this->mapData($data, $request);

        if (empty($data['bind_password'])) {
            unset($mapped['bind_password_encrypted']);
        }

        $directory->update($mapped);

        AuditLog::record('admin.directory_updated', $request->user(), ['directory_id' => $directory->id]);

        return redirect()->route('admin.directories.show', $directory)->with('status', 'Verzeichnis wurde aktualisiert.');
    }

    public function destroy(Request $request, Directory $directory): RedirectResponse
    {
        AuditLog::record('admin.directory_deleted', $request->user(), ['directory_id' => $directory->id]);
        $directory->delete();

        return redirect()->route('admin.directories.index')->with('status', 'Verzeichnis wurde gelöscht.');
    }

    public function activate(Request $request, Directory $directory): RedirectResponse
    {
        $directory->update(['is_active' => true]);
        AuditLog::record('admin.directory_activated', $request->user(), ['directory_id' => $directory->id]);

        return back()->with('status', 'Verzeichnis wurde aktiviert.');
    }

    public function deactivate(Request $request, Directory $directory): RedirectResponse
    {
        $directory->update(['is_active' => false]);
        AuditLog::record('admin.directory_deactivated', $request->user(), ['directory_id' => $directory->id]);

        return back()->with('status', 'Verzeichnis wurde deaktiviert.');
    }

    public function testConnection(Directory $directory): RedirectResponse
    {
        $result = (new DirectoryTestService)->testConnection($directory);

        return back()->with($result['ok'] ? 'status' : 'ldap_error', $result['ok'] ? $result['message'] : $result['message']);
    }

    public function searchUser(Request $request, Directory $directory): RedirectResponse
    {
        $term = $request->validate(['term' => 'required|string|min:2'])['term'];
        $result = (new DirectoryTestService)->searchUser($directory, $term);

        return back()->withInput()->with('ldap_search_result', $result);
    }

    public function searchGroup(Request $request, Directory $directory): RedirectResponse
    {
        $term = $request->validate(['term' => 'required|string|min:2'])['term'];
        $result = (new DirectoryTestService)->searchGroup($directory, $term);

        return back()->withInput()->with('ldap_search_result', $result);
    }

    public function testAuthenticate(Request $request, Directory $directory): RedirectResponse
    {
        $data = $request->validate(['username' => 'required|string', 'password' => 'required|string']);
        $result = (new DirectoryTestService)->testAuthenticate($directory, $data['username'], $data['password']);

        return back()->with($result['ok'] ? 'status' : 'ldap_error', $result['message']);
    }

    public function rawQuery(Request $request, Directory $directory): RedirectResponse
    {
        $data = $request->validate(['filter' => 'required|string|max:2000']);
        $result = (new DirectoryTestService)->rawQuery($directory, $data['filter']);

        return back()->withInput()->with('ldap_search_result', $result);
    }

    public function sync(Request $request, Directory $directory): RedirectResponse
    {
        $result = (new DirectorySyncService)->syncAll($directory);

        AuditLog::record('admin.directory_synced', $request->user(), [
            'directory_id' => $directory->id,
            'result' => $result,
        ]);

        if (! $result['ok']) {
            return back()->with('ldap_error', $result['message']);
        }

        $message = "Synchronisierung abgeschlossen: {$result['users']} Benutzer, {$result['groups']} Gruppen";
        if (($result['removed'] ?? 0) > 0) {
            $verb = $directory->stalePolicy() === 'delete' ? 'gelöscht' : 'gesperrt';
            $message .= ", {$result['removed']} nicht mehr im Verzeichnis ({$verb})";
        }
        $message .= " ({$result['duration']}s).";

        return back()->with('status', $message);
    }

    private function validateDirectory(Request $request, ?Directory $directory = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:active_directory,ldap',
            'domain' => 'nullable|string',
            'realm' => 'nullable|string',
            'domain_controller' => 'nullable|string',
            'ldap_server' => 'nullable|string',
            'ldap_port' => 'nullable|numeric',
            'use_ldaps' => 'nullable|boolean',
            'base_dn' => 'nullable|string',
            'user_dn' => 'nullable|string',
            'group_dn' => 'nullable|string',
            'bind_user' => 'nullable|string',
            'bind_password' => 'nullable|string',
            'upn_suffix' => 'nullable|string',
            'netbios_domain' => 'nullable|string',
            'kerberos_realm' => 'nullable|string',
            'priority' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'stale_user_handling' => 'nullable|in:keep,disable,delete',
        ]);
    }

    private function mapData(array $data, Request $request): array
    {
        return [
            'name' => $data['name'],
            'type' => $data['type'],
            'domain' => $data['domain'] ?? null,
            'realm' => $data['realm'] ?? null,
            'netbios_domain' => $data['netbios_domain'] ?? null,
            'domain_controller' => $data['domain_controller'] ?? null,
            'ldap_server' => $data['ldap_server'] ?? null,
            'ldap_port' => $data['ldap_port'] ?? null,
            'use_ldaps' => $request->boolean('use_ldaps'),
            'base_dn' => $data['base_dn'] ?? null,
            'user_dn' => $data['user_dn'] ?? null,
            'group_dn' => $data['group_dn'] ?? null,
            'bind_user' => $data['bind_user'] ?? null,
            'bind_password_encrypted' => $data['bind_password'] ?? null,
            'upn_suffix' => $data['upn_suffix'] ?? null,
            'kerberos_realm' => $data['kerberos_realm'] ?? null,
            'priority' => $data['priority'] ?? 0,
            'is_active' => $request->boolean('is_active'),
            'stale_user_handling' => $data['stale_user_handling'] ?? 'keep',
        ];
    }
}
