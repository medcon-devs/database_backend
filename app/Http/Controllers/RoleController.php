<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function permissions()
    {
        $permissions = Permission::all()->keyBy('name');
        $groups = [];
        foreach (config('permissions.groups') as $groupName => $definition) {
            $group = ['name' => $groupName, 'label' => $definition['label']];
            if (isset($definition['subgroups'])) {
                $group['subgroups'] = [];
                foreach ($definition['subgroups'] as $subgroupName => $subgroup) {
                    $group['subgroups'][] = [
                        'name' => $subgroupName,
                        'label' => $subgroup['label'],
                        'permissions' => $this->permissionRows($subgroup['permissions'], $permissions),
                    ];
                }
            } else {
                $group['permissions'] = $this->permissionRows($definition['permissions'], $permissions);
            }
            $groups[] = $group;
        }

        return response()->json(['data' => $groups, 'status_code' => 200, 'message' => 'Permission catalog retrieved successfully.', 'status' => true]);
    }

    public function index()
    {
        $roles = Role::with('permissions:id,name,label,group,subgroup')->withCount('users')->orderBy('label')->get();

        return response()->json(['data' => $roles, 'status_code' => 200, 'message' => 'Roles retrieved successfully.', 'status' => true]);
    }

    public function store(Request $request)
    {
        $input = $this->validated($request);
        $role = DB::transaction(function () use ($input) {
            $role = Role::create([
                'name' => $input['name'] ?? Str::slug($input['label'], '_'),
                'label' => $input['label'],
                'description' => $input['description'] ?? null,
            ]);
            $role->permissions()->sync($input['permission_ids']);
            return $role;
        });

        return response()->json(['data' => $role->load('permissions'), 'status_code' => 201, 'message' => 'Role created successfully.', 'status' => true], 201);
    }

    public function update(Request $request, Role $role)
    {
        if ($role->is_system) {
            return $this->protectedRoleResponse();
        }
        $input = $this->validated($request, $role);
        DB::transaction(function () use ($role, $input) {
            $role->update([
                'name' => $input['name'] ?? $role->name,
                'label' => $input['label'],
                'description' => $input['description'] ?? null,
            ]);
            $role->permissions()->sync($input['permission_ids']);
        });

        return response()->json(['data' => $role->load('permissions'), 'status_code' => 200, 'message' => 'Role updated successfully.', 'status' => true]);
    }

    public function destroy(Role $role)
    {
        if ($role->is_system || $role->users()->exists()) {
            return response()->json(['data' => null, 'status_code' => 422, 'message' => 'System roles and roles assigned to users cannot be deleted.', 'status' => false], 422);
        }
        $role->delete();
        return response()->json(['data' => null, 'status_code' => 200, 'message' => 'Role deleted successfully.', 'status' => true]);
    }

    public function assign(Request $request, User $user)
    {
        $input = $request->validate(['role_id' => ['required', 'integer', Rule::exists('roles', 'id')]]);
        $newRole = Role::findOrFail($input['role_id']);
        if ($user->role?->is_super_admin && ! $user->is_banned && ! $newRole->is_super_admin
            && User::where('is_banned', false)->whereHas('role', fn ($q) => $q->where('is_super_admin', true))->count() <= 1) {
            return response()->json(['data' => null, 'status_code' => 422, 'message' => 'The last active super administrator cannot be demoted.', 'status' => false], 422);
        }
        $legacyAccess = [
            'viewer' => 'R',
            'contact_importer' => 'RW',
            'contact_editor' => 'RWE',
            'contact_manager' => 'RWED',
            'super_administrator' => 'FULL',
        ];
        $user->update([
            'role_id' => $newRole->id,
            'access_type' => $legacyAccess[$newRole->name] ?? 'R',
        ]);

        return response()->json(['data' => $user->load('role.permissions:id,name,label'), 'status_code' => 200, 'message' => 'User role assigned successfully.', 'status' => true]);
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/', Rule::unique('roles')->ignore($role?->id)],
            'label' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => ['integer', 'distinct', Rule::exists('permissions', 'id')],
        ]);
    }

    private function permissionRows(array $definitions, $permissions): array
    {
        return collect($definitions)->map(fn ($label, $name) => [
            'id' => $permissions->get($name)?->id,
            'name' => $name,
            'label' => $label,
        ])->values()->all();
    }

    private function protectedRoleResponse()
    {
        return response()->json(['data' => null, 'status_code' => 422, 'message' => 'System roles cannot be modified.', 'status' => false], 422);
    }
}
