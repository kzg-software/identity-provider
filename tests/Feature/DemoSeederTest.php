<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_data_can_be_seeded_and_used(): void
    {
        Storage::fake('public');

        $this->seed(DemoSeeder::class);

        $this->assertTrue(User::where('username', 'demo')->value('is_admin'));
        $this->assertSame(7, Application::count());

        $this->post(route('login.attempt'), ['username' => 'demo', 'password' => 'demo'])->assertRedirect();
        $this->assertAuthenticatedAs(User::where('username', 'demo')->first());
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_refuses_to_run_on_a_database_with_users(): void
    {
        User::factory()->create();

        $this->expectException(RuntimeException::class);

        $this->seed(DemoSeeder::class);
    }
}
