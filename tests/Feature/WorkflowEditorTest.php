<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Workflow;
use App\Workflow\DefaultWorkflows;
use App\Workflow\WorkflowResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', '1');
        $this->admin = User::factory()->create(['username' => 'admin', 'is_admin' => true, 'is_active' => true, 'auth_source' => 'local']);
    }

    private static function graph(array $nodes, array $edges): array
    {
        return [
            'nodes' => array_map(fn ($n) => ['id' => $n[0], 'type' => $n[1], 'config' => $n[2] ?? [], 'position' => ['x' => 0, 'y' => 0]], $nodes),
            'edges' => array_map(fn ($e) => ['from' => $e[0], 'output' => $e[1], 'to' => $e[2]], $edges),
        ];
    }

    public function test_index_and_editor_render_for_admins_only(): void
    {
        $login = WorkflowResolver::global('login');

        $this->actingAs($this->admin)->get(route('admin.workflows.index'))->assertOk()->assertSee('Standard-Login');
        $this->actingAs($this->admin)->get(route('admin.workflows.edit', $login))->assertOk()->assertSee('Zwei-Faktor verlangen');

        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->actingAs($user)->get(route('admin.workflows.index'))->assertForbidden();
    }

    public function test_valid_graph_is_saved_as_new_version(): void
    {
        $login = WorkflowResolver::global('login');

        $this->actingAs($this->admin)->putJson(route('admin.workflows.update', $login), [
            'name' => 'Login mit Hinweis',
            'graph' => self::graph(
                [['start', 'start'], ['notice', 'notice', ['title' => 'Hallo', 'text' => 'Willkommen']], ['allow', 'allow']],
                [['start', 'weiter', 'notice'], ['notice', 'weiter', 'allow']],
            ),
        ])->assertOk()->assertJson(['ok' => true, 'version' => 2]);

        $login->refresh();
        $this->assertSame('Login mit Hinweis', $login->name);
        $this->assertSame(1, $login->versions()->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.workflow_updated']);
    }

    public function test_invalid_graphs_are_rejected_with_node_errors(): void
    {
        $login = WorkflowResolver::global('login');

        // Ausgang „nein“ offen, Pflichtfeld leer
        $response = $this->actingAs($this->admin)->putJson(route('admin.workflows.update', $login), [
            'name' => 'Kaputt',
            'graph' => self::graph(
                [['start', 'start'], ['role', 'condition.role', ['roles' => '']], ['allow', 'allow']],
                [['start', 'weiter', 'role'], ['role', 'ja', 'allow']],
            ),
        ])->assertStatus(422);

        $this->assertNotEmpty($response->json('errors.nodes.role'));
        $this->assertSame(1, $login->fresh()->version);

        // Nur „Ablehnen“ erreichbar → Aussperrschutz
        $this->actingAs($this->admin)->putJson(route('admin.workflows.update', $login), [
            'name' => 'Alle raus',
            'graph' => self::graph([['start', 'start'], ['deny', 'deny']], [['start', 'weiter', 'deny']]),
        ])->assertStatus(422)->assertJsonFragment(['Mindestens ein Weg muss bei „Erlauben“ enden, sonst könnte sich niemand mehr anmelden.']);

        // Schleife
        $this->actingAs($this->admin)->putJson(route('admin.workflows.update', $login), [
            'name' => 'Schleife',
            'graph' => self::graph(
                [['start', 'start'], ['a', 'condition.admin'], ['b', 'condition.admin'], ['allow', 'allow']],
                [['start', 'weiter', 'a'], ['a', 'ja', 'b'], ['a', 'nein', 'allow'], ['b', 'ja', 'a'], ['b', 'nein', 'allow']],
            ),
        ])->assertStatus(422);

        // Baustein aus falscher Phase
        $this->actingAs($this->admin)->putJson(route('admin.workflows.update', $login), [
            'name' => 'Falsche Phase',
            'graph' => self::graph(
                [['start', 'start'], ['maint', 'check.maintenance'], ['allow', 'allow']],
                [['start', 'weiter', 'maint'], ['maint', 'ok', 'allow'], ['maint', 'blockiert', 'allow']],
            ),
        ])->assertStatus(422);
    }

    public function test_application_override_is_created_as_copy_of_global(): void
    {
        $application = Application::create(['name' => 'Gitea', 'slug' => 'gitea', 'consent_required' => false, 'consent_mode' => 'skip', 'login_mode' => 'user_choice', 'is_active' => true]);

        $response = $this->actingAs($this->admin)->post(route('admin.applications.workflows.store', $application), ['phase' => 'authorize']);

        $workflow = Workflow::query()->where('application_id', $application->id)->firstOrFail();
        $response->assertRedirect(route('admin.workflows.edit', $workflow));
        $this->assertEquals(WorkflowResolver::global('authorize')->graph, $workflow->graph);

        $this->actingAs($this->admin)->get(route('admin.applications.show', [$application, 'tab' => 'workflow']))
            ->assertOk()->assertSee('Eigener Ablauf');
    }

    public function test_simulation_runs_without_side_effects(): void
    {
        $login = WorkflowResolver::global('login');
        User::factory()->create(['username' => 'extern', 'is_active' => true, 'manual_roles' => ['extern']]);

        $response = $this->actingAs($this->admin)->postJson(route('admin.workflows.simulate', $login), [
            'username' => 'extern',
            'graph' => self::graph(
                [['start', 'start'], ['role', 'condition.role', ['roles' => 'extern']], ['deny', 'deny', ['message' => 'Kein Zugang']], ['allow', 'allow']],
                [['start', 'weiter', 'role'], ['role', 'ja', 'deny'], ['role', 'nein', 'allow']],
            ),
        ])->assertOk();

        $response->assertJson(['ok' => true, 'outcome' => 'deny', 'message' => 'Kein Zugang']);
        $this->assertSame(['start', 'role', 'deny'], array_column($response->json('steps'), 'node'));
        $this->assertDatabaseMissing('audit_logs', ['event' => 'workflow.denied']);
        $this->assertSame(1, $login->fresh()->version);
    }

    public function test_reset_command_restores_defaults_and_disables_overrides(): void
    {
        $application = Application::create(['name' => 'Wiki', 'slug' => 'wiki', 'consent_required' => false, 'consent_mode' => 'skip', 'login_mode' => 'user_choice', 'is_active' => true]);
        $override = Workflow::create(['phase' => 'authorize', 'application_id' => $application->id, 'name' => 'Wiki', 'is_active' => true, 'graph' => DefaultWorkflows::graph('authorize'), 'version' => 1]);

        $login = WorkflowResolver::global('login');
        $login->replaceGraph(self::graph([['start', 'start'], ['allow', 'allow']], [['start', 'weiter', 'allow']]));

        $this->artisan('workflow:reset')->assertSuccessful();

        $this->assertEquals(json_decode(json_encode(DefaultWorkflows::graph('login')), true), $login->fresh()->graph);
        $this->assertFalse($override->fresh()->is_active);
    }
}
