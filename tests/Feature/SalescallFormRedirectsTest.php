<?php

use App\Filament\Pages\CrrFormPage;
use App\Filament\Pages\GiveawayFormPage;
use App\Filament\Pages\MarketingFormPage;
use App\Filament\Pages\SalescallPage;
use App\Filament\Pages\SignageFormPage;
use App\Filament\Pages\TradeCheckFormPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the sales call page wires redirect buttons to the CRR, Signage, Marketing, Trade Check, and Giveaway forms', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(SalescallPage::class)
        ->assertOk()
        ->assertSeeHtml('title="CRR — Customer Call Report"')
        ->assertSee('CRR Signage')
        ->assertSee('Local Store Marketing')
        ->assertSee('Trade Check')
        ->assertSee('Giveaway form')
        ->assertSeeHtml("openSalescallForm('crr')")
        ->assertSeeHtml("openSalescallForm('signage')")
        ->assertSeeHtml("openSalescallForm('marketing')")
        ->assertSeeHtml("openSalescallForm('tradeCheck')")
        ->assertSeeHtml("openSalescallForm('giveaway')");

    expect($component->html())
        ->toContain('crr: '.Js::from(CrrFormPage::getUrl()).',')
        ->toContain('signage: '.Js::from(SignageFormPage::getUrl()).',')
        ->toContain('marketing: '.Js::from(MarketingFormPage::getUrl()).',')
        ->toContain('tradeCheck: '.Js::from(TradeCheckFormPage::getUrl()).',')
        ->toContain('giveaway: '.Js::from(GiveawayFormPage::getUrl()).',');
});
