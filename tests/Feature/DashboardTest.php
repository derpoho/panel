<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_are_sent_to_their_workspace()
    {
        $workspace = Workspace::factory()->create();
        $this->actingAs($workspace->owner);

        $response = $this->get(route('dashboard'));
        $response->assertRedirect($workspace->url());
    }

    public function test_users_without_a_workspace_get_one_created()
    {
        $user = User::factory()->create(['name' => 'Alex Rivera']);
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));

        $workspace = $user->defaultWorkspace();
        $this->assertNotNull($workspace);
        $this->assertSame('alex-rivera', $workspace->slug);
        $response->assertRedirect($workspace->url());
    }
}
