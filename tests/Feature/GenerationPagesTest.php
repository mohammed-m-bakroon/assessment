<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GenerationPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/')->assertRedirect('/generate');
        $this->get('/generate')->assertRedirect('/login');
        $this->get('/generations')->assertRedirect('/login');
    }

    public function test_the_generate_page_lists_the_options(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/generate')
            ->assertInertia(fn (Assert $page) => $page
                ->component('generations/Create')
                ->where('options.sizes', ['1024x1024', '1536x1024', '1024x1536'])
                ->where('options.markup_percent', 25));
    }

    public function test_history_shows_only_my_generations_with_exact_money(): void
    {
        $user = User::factory()->create();
        Generation::factory()->for($user)->accepted('succeeded')->create([
            'provider_cost' => 305_510_000,
            'charge' => 381_887_500,
        ]);
        Generation::factory()->create(); // someone else's

        $this->actingAs($user)
            ->get('/generations')
            ->assertInertia(fn (Assert $page) => $page
                ->component('generations/Index')
                ->has('generations.data', 1)
                ->where('generations.data.0.status', GenerationStatus::Succeeded->value)
                ->where('generations.data.0.charge', '0.381887500')
                ->where('generations.data.0.estimated_charge_min', '0.060000000')
                ->missing('generations.data.0.provider_cost'));
    }
}
