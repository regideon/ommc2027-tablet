<?php

use App\Filament\Pages\SalescallPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('sales call page serves the capture-photo step gate in its HTML', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(SalescallPage::class)
        ->assertOk()
        ->assertSee('photoStep === 3', false)
        ->assertSee('Capture Photo', false)
        ->assertSee('browser-camera-input', false)
        ->assertSee('browser-gallery-input', false)
        ->assertSee('Take Photo', false)
        ->assertSee('Gallery', false);
});

test('logPhotoFlow writes a photoflow diagnostic entry', function () {
    Log::spy();

    Livewire::test(SalescallPage::class)
        ->call('logPhotoFlow', [
            'event' => 'selectPhotoType',
            'photoStep' => 3,
        ]);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === '[photoflow]' && $context['event'] === 'selectPhotoType');
});
