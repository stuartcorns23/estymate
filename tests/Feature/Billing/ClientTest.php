<?php

namespace Tests\Feature\Billing;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_clients_page_is_displayed()
    {
        $this->actingAs(User::factory()->withBusiness()->create());

        $this->get('/clients')->assertOk();
        $this->get('/clients/create')->assertOk();
    }

    public function test_clients_can_be_added_to_the_current_business()
    {
        $user = User::factory()->withBusiness()->create();

        $this->actingAs($user)
            ->post('/clients', ['name' => 'Acme Ltd', 'email' => 'accounts@acme.test'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/clients');

        $this->assertDatabaseHas('clients', ['name' => 'Acme Ltd', 'business_id' => $user->current_business_id]);
    }

    public function test_clients_can_be_updated_and_deleted()
    {
        $user = User::factory()->withBusiness()->create();
        $this->actingAs($user);
        $client = Client::factory()->create();

        $this->put("/clients/{$client->id}", ['name' => 'Renamed'])->assertRedirect('/clients');
        $this->assertSame('Renamed', $client->fresh()->name);

        $this->delete("/clients/{$client->id}")->assertRedirect('/clients');
        $this->assertSoftDeleted($client);
    }

    public function test_clients_from_another_business_are_not_accessible()
    {
        $other = User::factory()->withBusiness()->create();
        $this->actingAs($other);
        $client = Client::factory()->create();

        $this->actingAs(User::factory()->withBusiness()->create())
            ->get("/clients/{$client->id}/edit")
            ->assertNotFound();
    }

    public function test_clients_can_be_searched()
    {
        $this->actingAs(User::factory()->withBusiness()->create());
        Client::factory()->create(['name' => 'Acme Ltd']);
        Client::factory()->create(['name' => 'Other Co']);

        $this->get('/clients?search=acme')
            ->assertInertia(fn ($page) => $page
                ->has('clients.data', 1)
                ->where('clients.data.0.name', 'Acme Ltd'));
    }
}
