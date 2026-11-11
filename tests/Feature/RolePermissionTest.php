<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppCampaign;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class RolePermissionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_permission_catalog_is_grouped_for_the_frontend(): void
    {
        $admin = $this->userWithRole('catalog-admin', ['users.view']);

        $this->withToken(JWTAuth::fromUser($admin))->getJson('/api/permissions')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'contacts')
            ->assertJsonFragment(['name' => 'whatsapp.campaigns.send', 'label' => 'Send or schedule campaigns']);
    }

    public function test_custom_role_can_view_campaigns_but_cannot_send_them(): void
    {
        $user = $this->userWithRole('campaign-viewer', ['whatsapp.campaigns.view']);
        $token = JWTAuth::fromUser($user);
        $campaign = WhatsAppCampaign::create([
            'name' => 'Permission test',
            'template_name' => 'permission_test',
            'template_language' => 'en_US',
            'created_by' => $user->id,
        ]);

        $this->withToken($token)->getJson('/api/whatsapp/campaigns')->assertOk();
        $this->withToken($token)->postJson("/api/whatsapp/campaigns/{$campaign->id}/send")->assertForbidden();
    }

    public function test_role_manager_can_create_a_custom_role(): void
    {
        $admin = $this->userWithRole('role-manager', ['roles.manage']);
        $permission = Permission::where('name', 'whatsapp.templates.view')->firstOrFail();

        $this->withToken(JWTAuth::fromUser($admin))->postJson('/api/roles', [
            'name' => 'template_viewer_'.Str::lower(Str::random(6)),
            'label' => 'Template Viewer',
            'permission_ids' => [$permission->id],
        ])->assertCreated()->assertJsonFragment(['name' => 'whatsapp.templates.view']);
    }

    public function test_get_user_returns_fresh_effective_permission_objects_after_role_assignment(): void
    {
        $target = User::factory()->create(['access_type' => 'R']);
        $newRole = Role::create(['name' => 'activity-reader_'.Str::lower(Str::random(8)), 'label' => 'Activity Reader']);
        $newRole->permissions()->sync(Permission::whereIn('name', ['activity.view_own', 'activity.view_contact'])->pluck('id'));

        $target->update(['role_id' => $newRole->id]);
        $this->withToken(JWTAuth::fromUser($target))->getJson('/api/get-user')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.role.id', $newRole->id)
            ->assertJsonFragment(['name' => 'activity.view_own'])
            ->assertJsonFragment(['name' => 'activity.view_contact']);
    }

    private function userWithRole(string $prefix, array $permissions): User
    {
        $role = Role::create(['name' => $prefix.'_'.Str::lower(Str::random(8)), 'label' => $prefix]);
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

        return User::factory()->create(['access_type' => 'R', 'role_id' => $role->id]);
    }
}
