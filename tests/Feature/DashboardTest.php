<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/dashboard');
        $response->assertStatus(200);
    }

    public function test_dashboard_leaves_detail_to_other_pages()
    {
        $this->actingAs(User::factory()->create());

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('gros/Dashboard')
            ->has('plan')
            ->has('upcoming')
            ->missing('spendCats')
            ->missing('topExpenses')
            ->missing('history')
            ->missing('savingsRate')
        );
    }

    public function test_yearly_view_lives_in_analytics()
    {
        $this->actingAs(User::factory()->create());

        $this->get('/yoy')->assertRedirect('/analytics#po-rokoch');
        $this->get('/analytics')->assertInertia(fn (Assert $page) => $page
            ->has('years')
            ->missing('opportunityCost')
        );
        $this->get('/purchase')->assertInertia(fn (Assert $page) => $page
            ->has('opportunityCost.categories')
        );
    }
}
