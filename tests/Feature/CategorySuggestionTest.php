<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategorySuggester;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategorySuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $account;

    protected Category $food;

    protected Category $electronics;

    protected CategorySuggester $suggester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = Account::create(['user_id' => $this->user->id, 'name' => 'Bežný', 'type' => 'cash', 'balance' => 1000, 'color' => '#4c8dff']);
        $this->food = Category::create(['user_id' => $this->user->id, 'name' => 'Potraviny', 'type' => 'expense', 'color' => '#e8544e', 'icon' => 'cart', 'position' => 1]);
        $this->electronics = Category::create(['user_id' => $this->user->id, 'name' => 'Elektronika', 'type' => 'expense', 'color' => '#6c5ce7', 'icon' => 'chip', 'position' => 2]);

        $this->suggester = app(CategorySuggester::class);
    }

    protected function past(string $note, Category $category, ?User $owner = null): Transaction
    {
        return Transaction::create([
            'user_id' => ($owner ?? $this->user)->id,
            'account_id' => $this->account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 20,
            'note' => $note,
            'date' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function test_suggests_the_category_used_for_the_same_note(): void
    {
        $this->past('Lidl', $this->food);

        $this->assertSame($this->food->id, $this->suggester->suggest($this->user, 'lidl', 'expense'));
    }

    public function test_matches_ignoring_diacritics_and_punctuation(): void
    {
        $this->past('Kaviareň U Trúbky', $this->food);

        $this->assertSame($this->food->id, $this->suggester->suggest($this->user, 'kaviaren u trubky!', 'expense'));
    }

    public function test_a_longer_note_still_finds_the_merchant(): void
    {
        $this->past('Lidl', $this->food);

        $this->assertSame($this->food->id, $this->suggester->suggest($this->user, 'Lidl Bratislava', 'expense'));
    }

    public function test_the_most_used_category_wins(): void
    {
        $this->past('Alza', $this->electronics);
        $this->past('Alza', $this->electronics);
        $this->past('Alza', $this->food);

        $this->assertSame($this->electronics->id, $this->suggester->suggest($this->user, 'Alza', 'expense'));
    }

    public function test_suggests_nothing_for_an_unknown_note(): void
    {
        $this->past('Lidl', $this->food);

        $this->assertNull($this->suggester->suggest($this->user, 'Zoologická záhrada', 'expense'));
    }

    public function test_very_short_notes_are_not_guessed(): void
    {
        $this->past('Lidl', $this->food);

        $this->assertNull($this->suggester->suggest($this->user, 'ab', 'expense'));
    }

    public function test_does_not_leak_across_users_or_types(): void
    {
        $other = User::factory()->create();
        $this->past('Lidl', $this->food, $other);

        $this->assertNull($this->suggester->suggest($this->user, 'Lidl', 'expense'));
        $this->assertNull($this->suggester->suggest($this->user, 'Lidl', 'income'));
    }

    public function test_the_endpoint_returns_the_suggestion(): void
    {
        $this->past('Lidl', $this->food);

        $this->actingAs($this->user)
            ->getJson('/transactions/suggest-category?note=Lidl&type=expense')
            ->assertOk()
            ->assertJson(['category_id' => $this->food->id]);
    }

    public function test_the_endpoint_rejects_a_transfer(): void
    {
        $this->actingAs($this->user)
            ->getJson('/transactions/suggest-category?note=Lidl&type=transfer')
            ->assertStatus(422);
    }
}
