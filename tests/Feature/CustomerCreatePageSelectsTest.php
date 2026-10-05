<?php

use App\Filament\Pages\CustomerCreatePage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('selecting mixed outlet reveals the competitor volume dropdown', function () {
    $this->actingAs(User::factory()->create());

    $mixedOutletId = DB::table('general_categories')->insertGetId([
        'name' => 'Mixed Outlet',
        'priority_visit' => 1,
        'duration_per_visit' => 60,
        'sort' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(CustomerCreatePage::class)
        ->assertDontSee('Competitor Volume')
        ->set('general_category_id', $mixedOutletId)
        ->assertSee('Competitor Volume');
});
