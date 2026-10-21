<?php

namespace Tests\Feature\Settings;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use App\Notifications\BusinessInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_page_is_displayed()
    {
        $this->actingAs(User::factory()->withBusiness()->create());

        $this->get('/settings/team')->assertOk();
    }

    public function test_owners_can_invite_people()
    {
        Notification::fake();
        $owner = User::factory()->withBusiness()->create();

        $this->actingAs($owner)
            ->post('/settings/team/invitations', ['email' => 'new@example.com', 'role' => 'member'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/team');

        $this->assertDatabaseHas('business_invitations', [
            'business_id' => $owner->current_business_id,
            'email' => 'new@example.com',
        ]);
        Notification::assertSentOnDemand(BusinessInvitationNotification::class);
    }

    public function test_people_cannot_be_invited_as_owners()
    {
        $owner = User::factory()->withBusiness()->create();

        $this->actingAs($owner)
            ->post('/settings/team/invitations', ['email' => 'new@example.com', 'role' => 'owner'])
            ->assertSessionHasErrors('role');
    }

    public function test_existing_members_cannot_be_invited_again()
    {
        $owner = User::factory()->withBusiness()->create();

        $this->actingAs($owner)
            ->post('/settings/team/invitations', ['email' => $owner->email, 'role' => 'member'])
            ->assertSessionHasErrors('email');
    }

    public function test_members_cannot_invite_people()
    {
        $member = User::factory()->withBusiness(BusinessRole::Member)->create();

        $this->actingAs($member)
            ->post('/settings/team/invitations', ['email' => 'new@example.com', 'role' => 'member'])
            ->assertForbidden();
    }

    public function test_invited_users_can_accept_an_invitation()
    {
        $business = Business::factory()->create();
        $invitation = $business->invitations()->create(['email' => 'new@example.com', 'role' => 'admin']);
        $user = User::factory()->create(['email' => 'new@example.com']);

        $this->actingAs($user)
            ->get("/invitations/{$invitation->token}")
            ->assertRedirect('/dashboard');

        $user->refresh();
        $this->assertSame($business->id, $user->current_business_id);
        $this->assertSame(BusinessRole::Admin, $user->roleIn($business));
        $this->assertModelMissing($invitation);
    }

    public function test_invitations_cannot_be_accepted_by_someone_else()
    {
        $business = Business::factory()->create();
        $invitation = $business->invitations()->create(['email' => 'new@example.com', 'role' => 'member']);

        $this->actingAs(User::factory()->create(['email' => 'other@example.com']))
            ->get("/invitations/{$invitation->token}")
            ->assertForbidden();

        $this->assertModelExists($invitation);
    }

    public function test_owners_can_cancel_invitations()
    {
        $owner = User::factory()->withBusiness()->create();
        $invitation = $owner->currentBusiness->invitations()->create(['email' => 'new@example.com', 'role' => 'member']);

        $this->actingAs($owner)
            ->delete("/settings/team/invitations/{$invitation->id}")
            ->assertRedirect('/settings/team');

        $this->assertModelMissing($invitation);
    }

    public function test_invitations_from_another_business_cannot_be_cancelled()
    {
        $owner = User::factory()->withBusiness()->create();
        $invitation = Business::factory()->create()->invitations()->create(['email' => 'new@example.com', 'role' => 'member']);

        $this->actingAs($owner)
            ->delete("/settings/team/invitations/{$invitation->id}")
            ->assertNotFound();

        $this->assertModelExists($invitation);
    }

    public function test_owners_can_remove_members()
    {
        $owner = User::factory()->withBusiness()->create();
        $member = User::factory()->create();
        $owner->currentBusiness->addUser($member, BusinessRole::Member);

        $this->actingAs($owner)
            ->delete("/settings/team/members/{$member->id}")
            ->assertRedirect('/settings/team');

        $member->refresh();
        $this->assertNull($member->roleIn($owner->currentBusiness));
        $this->assertNull($member->current_business_id);
    }

    public function test_owners_cannot_be_removed()
    {
        $owner = User::factory()->withBusiness()->create();
        $admin = User::factory()->create();
        $owner->currentBusiness->addUser($admin, BusinessRole::Admin);

        $this->actingAs($admin)
            ->delete("/settings/team/members/{$owner->id}")
            ->assertForbidden();
    }

    public function test_pending_invitations_are_shown_on_the_setup_page()
    {
        $business = Business::factory()->create(['name' => 'Acme Ltd']);
        $business->invitations()->create(['email' => 'new@example.com', 'role' => 'member']);

        $this->actingAs(User::factory()->create(['email' => 'new@example.com']))
            ->get('/businesses/create')
            ->assertInertia(fn ($page) => $page
                ->component('businesses/create')
                ->where('invitations.0.business', 'Acme Ltd'));
    }

    public function test_tokens_are_not_exposed_on_the_team_page()
    {
        $owner = User::factory()->withBusiness()->create();
        $invitation = $owner->currentBusiness->invitations()->create(['email' => 'new@example.com', 'role' => 'member']);

        $this->actingAs($owner)
            ->get('/settings/team')
            ->assertDontSee($invitation->token);
    }
}
