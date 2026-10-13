<?php

use App\Filament\Pages\SalescallPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the sales call page wires redirect buttons to the Signage, Marketing, and Trade Check forms', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(SalescallPage::class)
        ->assertOk()
        ->assertSee('CRR Signage')
        ->assertSee('Local Store Marketing')
        ->assertSee('Trade Check')
        ->assertSeeHtml("openSalescallForm('signage')")
        ->assertSeeHtml("openSalescallForm('marketing')")
        ->assertSeeHtml("openSalescallForm('tradeCheck')")
        ->assertSee('\/app\/forms\/crr-signage', false)
        ->assertSee('\/app\/forms\/marketing', false)
        ->assertSee('\/app\/forms\/trade-check', false);
});
