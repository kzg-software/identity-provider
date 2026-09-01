<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Directory;
use App\Models\DirectoryGroup;
use App\Models\GroupRoleMapping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GroupRoleMappingController extends Controller
{
    public function index(): View
    {
        $mappings = GroupRoleMapping::with('directoryGroup.directory', 'directory')->orderBy('role')->get();
        $groups = DirectoryGroup::with('directory')->orderBy('name')->get();
        $directories = Directory::orderBy('name')->get();

        return view('admin.group-role-mappings.index', compact('mappings', 'groups', 'directories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'group' => 'required|string|max:255',
            'directory_id' => 'nullable|exists:directories,id',
            'role' => 'required|string|max:255',
            'claims' => 'nullable|string',
        ]);

        $claims = null;
        if (! empty($data['claims'])) {
            $decoded = json_decode($data['claims'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return back()->withInput()->withErrors(['claims' => 'Claims müssen gültiges JSON sein.']);
            }
            $claims = $decoded;
        }

        $groupName = trim($data['group']);
        $directoryId = ($data['directory_id'] ?? null) ?: null;

        // Passt der Name exakt auf eine bereits synchronisierte Gruppe, wird
        // direkt verknüpft; sonst als freier Name gespeichert.
        $match = DirectoryGroup::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($groupName)])
            ->when($directoryId, fn ($q) => $q->where('directory_id', $directoryId))
            ->first();

        $exists = GroupRoleMapping::query()
            ->where('role', $data['role'])
            ->when(
                $match,
                fn ($q) => $q->where('directory_group_id', $match->id),
                fn ($q) => $q->whereRaw('LOWER(group_name) = ?', [mb_strtolower($groupName)])
                    ->when(
                        $directoryId,
                        fn ($q2) => $q2->where('directory_id', $directoryId),
                        fn ($q2) => $q2->whereNull('directory_id'),
                    ),
            )
            ->exists();

        if ($exists) {
            return back()->withInput()->withErrors(['group' => 'Dieses Mapping gibt es bereits.']);
        }

        GroupRoleMapping::create([
            'directory_group_id' => $match?->id,
            'group_name' => $match ? null : $groupName,
            'directory_id' => $match ? null : $directoryId,
            'role' => $data['role'],
            'claims' => $claims,
        ]);

        AuditLog::record('admin.group_role_mapping_created', $request->user(), [
            'group' => $groupName,
            'role' => $data['role'],
            'linked' => (bool) $match,
        ]);

        return back()->with('status', 'Mapping wurde angelegt.');
    }

    public function destroy(Request $request, GroupRoleMapping $groupRoleMapping): RedirectResponse
    {
        AuditLog::record('admin.group_role_mapping_deleted', $request->user(), ['id' => $groupRoleMapping->id]);
        $groupRoleMapping->delete();

        return back()->with('status', 'Mapping wurde gelöscht.');
    }
}
