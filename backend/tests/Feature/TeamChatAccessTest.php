<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Support\Modules;
use Database\Seeders\PermissionsSeeder;
use Tests\TestCase;

/** Team chat is a plan / add-on feature with its own permissions — the wiring that makes the plan and the role decide who may use it. */
class TeamChatAccessTest extends TestCase
{
    public function test_team_chat_is_a_feature_a_plan_can_switch_and_an_addon_can_unlock(): void
    {
        $m = Modules::get('team_chat');
        $this->assertNotNull($m);
        $this->assertSame('Team chat', $m['label']);
        $this->assertArrayHasKey('team_chat', Modules::REGISTRY);
    }

    public function test_every_team_chat_url_is_gated_by_the_feature(): void
    {
        $this->assertSame('team_chat', Modules::forPath('api/v1/team-chat/chats'));
        $this->assertSame('team_chat', Modules::forPath('v1/team-chat/chats/5/messages'));
        $this->assertNull(Modules::forPath('api/v1/internal/team-chat-access'), 'the server-to-server check is guarded by its own key, not by a plan');
    }

    public function test_the_three_permissions_exist_and_belong_to_the_feature(): void
    {
        $keys = array_column(PermissionsSeeder::PERMISSIONS, 'key');
        foreach (['team_chat.view', 'team_chat.groups', 'team_chat.moderate', 'team_chat.delete'] as $k) {
            $this->assertContains($k, $keys);
            $this->assertSame('team_chat', Modules::permissionModule($k));
        }
    }

    public function test_a_plan_with_a_permission_list_only_allows_what_is_listed(): void
    {
        $p = new Plan(); $p->permissions = ['team_chat.view'];
        $this->assertTrue($p->allowsPermission('team_chat.view'));
        $this->assertFalse($p->allowsPermission('team_chat.groups'));
        $this->assertFalse($p->allowsPermission('team_chat.moderate'));
        $all = new Plan(); $all->permissions = null;
        $this->assertTrue($all->allowsPermission('team_chat.moderate'));
    }

    public function test_the_routes_demand_the_permission(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/team-chat/'));
        $this->assertGreaterThan(20, $routes->count());
        foreach ($routes as $r) {
            $this->assertContains('permission:team_chat.view', $r->gatherMiddleware(), $r->uri() . ' is open to anyone');
        }
        $this->assertContains('api/v1/internal/team-chat-access', collect(app('router')->getRoutes()->getRoutes())->map->uri()->all());
    }

    public function test_default_roles_get_sensible_chat_rights(): void
    {
        $r = (new PermissionsSeeder())->rolePermissions(array_column(PermissionsSeeder::PERMISSIONS, 'key'));
        $this->assertContains('team_chat.view', $r['counsellor']);
        $this->assertNotContains('team_chat.moderate', $r['counsellor']);
        $this->assertNotContains('team_chat.delete', $r['counsellor'], 'deleting a conversation is opt-in');
        $this->assertContains('team_chat.delete', $r['team_lead']);
        $this->assertContains('team_chat.moderate', $r['team_lead']);
        $this->assertContains('team_chat.view', $r['team_lead'], 'the .view suffix puts it in every viewer set');
    }
}
