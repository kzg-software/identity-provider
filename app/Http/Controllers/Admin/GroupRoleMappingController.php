<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DirectoryGroup;
use App\Models\GroupRoleMapping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GroupRoleMappingController extends Controller
{
    public function index(): View
    {
        $mappings = GroupRoleMapping::with('directoryGroup.directory')->orderBy('role')->get();
        $groups = DirectoryGroup::orderBy('name')->get();

        return view('admin.group-role-mappings.index', compact('mappings', 'groups'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'directory_group_id' => 'required|exists:directory_groups,id',
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

        GroupRoleMapping::create([
            'directory_group_id' => $data['directory_group_id'],
            'role' => $data['role'],
            'claims' => $claims,
        ]);

        AuditLog::record('admin.group_role_mapping_created', $request->user(), $data);

        return back()->with('status', 'Mapping wurde angelegt.');
    }

    public function destroy(Request $request, GroupRoleMapping $groupRoleMapping): RedirectResponse
    {
        AuditLog::record('admin.group_role_mapping_deleted', $request->user(), ['id' => $groupRoleMapping->id]);
        $groupRoleMapping->delete();

        return back()->with('status', 'Mapping wurde gelöscht.');
    }
}
