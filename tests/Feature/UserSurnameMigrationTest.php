<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('surname migration upgrades the previous users schema without changing existing identities or relationships', function (): void {
    $user = User::factory()->create([
        'name' => 'Jordan',
        'email' => 'jordan.legacy@example.test',
        'api_token' => 'kept-token',
        'server_id' => 871,
    ]);
    $localId = $user->id;
    $passwordHash = $user->password;

    $customerId = DB::table('customers')->insertGetId([
        'name' => 'Legacy Customer',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('customer_user')->insert([
        'customer_id' => $customerId,
        'user_id' => $localId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Schema::table('users', function (Blueprint $table): void {
        $table->dropColumn('last_name');
    });
    expect(Schema::hasColumn('users', 'last_name'))->toBeFalse();

    $migration = require database_path('migrations/2026_10_12_000001_add_last_name_to_users_table.php');
    $migration->up();

    $reloaded = User::query()->findOrFail($localId);
    expect(Schema::hasColumn('users', 'last_name'))->toBeTrue()
        ->and(Schema::getColumnType('users', 'last_name'))->toBe('varchar')
        ->and($reloaded->id)->toBe($localId)
        ->and($reloaded->name)->toBe('Jordan')
        ->and($reloaded->last_name)->toBeNull()
        ->and($reloaded->email)->toBe('jordan.legacy@example.test')
        ->and($reloaded->api_token)->toBe('kept-token')
        ->and($reloaded->password)->toBe($passwordHash)
        ->and(DB::table('customer_user')->where('customer_id', $customerId)->value('user_id'))->toBe($localId);
});
