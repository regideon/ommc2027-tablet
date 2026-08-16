<?php

use App\Filament\Pages\SettingsPage;
use App\Models\User;
use App\Support\NativeAppReleaseMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('native app release metadata reads bundled env version and build', function () {
    $path = sys_get_temp_dir().'/native-release-'.uniqid().'.env';
    file_put_contents($path, "NATIVEPHP_APP_VERSION=1.0.1\nNATIVEPHP_APP_VERSION_CODE=40\n");

    config()->set('nativephp.release_metadata_env_path', $path);

    $metadata = app(NativeAppReleaseMetadata::class);

    expect($metadata->read())->toBe([
        'version' => '1.0.1',
        'build' => '40',
    ])->and($metadata->releaseLabel())->toBe('Release 1.0.1 (40)');

    @unlink($path);
});

test('native app release metadata falls back to configured values when bundled env is unavailable', function () {
    config()->set('nativephp.release_metadata_env_path', sys_get_temp_dir().'/missing-release-'.uniqid().'.env');
    config()->set('nativephp.version', '2.0.0');
    config()->set('nativephp.version_code', 77);

    $metadata = app(NativeAppReleaseMetadata::class);

    expect($metadata->read())->toBe([
        'version' => '2.0.0',
        'build' => '77',
    ]);
});

test('settings page renders dynamic release metadata instead of a hardcoded footer', function () {
    $path = sys_get_temp_dir().'/settings-release-'.uniqid().'.env';
    file_put_contents($path, "NATIVEPHP_APP_VERSION=3.1.4\nNATIVEPHP_APP_VERSION_CODE=159\n");

    config()->set('nativephp.release_metadata_env_path', $path);

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(SettingsPage::class)
        ->assertSee('Release 3.1.4 (159)')
        ->assertDontSee('Release 1.0.0.1');

    @unlink($path);
});
