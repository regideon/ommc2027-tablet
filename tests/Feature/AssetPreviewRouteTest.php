<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the web serves _assets previews from the public directory', function () {
    $user = User::factory()->create();
    $relative = 'staged_attachments/test-preview-asset.png';
    $path = public_path($relative);

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }

    file_put_contents($path, 'bytes');

    try {
        $this->actingAs($user)->get('/_assets/'.$relative)->assertOk();
        $this->actingAs($user)->get('/_assets/staged_attachments/does-not-exist.png')->assertNotFound();
    } finally {
        @unlink($path);
    }
});

test('the _assets route cannot escape the public directory', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/_assets/../.env')->assertNotFound();
    $this->actingAs($user)->get('/_assets/%2e%2e/.env')->assertNotFound();
});

test('guests are redirected to the panel login for _assets', function () {
    $this->get('/_assets/staged_attachments/missing.png')
        ->assertRedirect(route('filament.saleshub.auth.login'));
});
