<?php

use App\Services\StartupMigrationService;
use App\Support\StartupMigrationArtifact;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function &startupMigrationTmpDirsState(): array
{
    static $dirs = [];

    return $dirs;
}

function &startupMigrationDbPathsState(): array
{
    static $paths = [];

    return $paths;
}

afterEach(function () {
    DB::disconnect('sqlite');
    DB::purge('sqlite');
    File::delete(StartupMigrationArtifact::path());

    $dirs = &startupMigrationTmpDirsState();
    foreach ($dirs as $dir) {
        File::deleteDirectory($dir);
    }
    $dirs = [];

    $paths = &startupMigrationDbPathsState();
    foreach ($paths as $path) {
        @unlink($path);
    }
    $paths = [];
});

function startupMigrationTmpDir(): string
{
    $dirs = &startupMigrationTmpDirsState();

    $dir = sys_get_temp_dir().'/startup-migrations-'.Str::uuid();
    File::ensureDirectoryExists($dir);
    $dirs[] = $dir;

    return $dir;
}

function startupMigrationDbPath(): string
{
    $paths = &startupMigrationDbPathsState();

    $path = sys_get_temp_dir().'/startup-db-'.Str::uuid().'.sqlite';
    touch($path);
    $paths[] = $path;

    return $path;
}

function useStartupMigrationDatabase(string $path): void
{
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.database', $path);
    DB::disconnect('sqlite');
    DB::purge('sqlite');
}

function writeMigration(string $dir, string $filename, string $contents): void
{
    File::put($dir.'/'.$filename, $contents);
}

function startupMigrationServiceRun(array $paths)
{
    return app(StartupMigrationService::class)->run($paths);
}

test('startup migration gate reports current when no pending migrations remain', function () {
    $dbPath = startupMigrationDbPath();
    $dir = startupMigrationTmpDir();

    writeMigration($dir, '2026_08_17_000000_create_widgets_table.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widgets');
    }
};
PHP);

    useStartupMigrationDatabase($dbPath);

    $first = startupMigrationServiceRun([$dir]);
    $second = startupMigrationServiceRun([$dir]);

    expect($first->status)->toBe('migrated')
        ->and($second->status)->toBe('current')
        ->and($second->databasePath)->toBe($dbPath);
});

test('startup migration gate applies one pending migration and preserves existing records', function () {
    $dbPath = startupMigrationDbPath();
    $dir = startupMigrationTmpDir();

    writeMigration($dir, '2026_08_17_000000_create_widgets_table.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widgets');
    }
};
PHP);

    useStartupMigrationDatabase($dbPath);
    startupMigrationServiceRun([$dir]);

    DB::table('widgets')->insert(['name' => 'legacy widget']);

    writeMigration($dir, '2026_08_17_000100_add_status_to_widgets_table.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widgets', function (Blueprint $table) {
            $table->string('status')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('widgets', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
PHP);

    $result = startupMigrationServiceRun([$dir]);

    expect($result->status)->toBe('migrated')
        ->and($result->appliedMigrations)->toBe(['2026_08_17_000100_add_status_to_widgets_table'])
        ->and(Schema::hasColumn('widgets', 'status'))->toBeTrue()
        ->and(DB::table('widgets')->value('name'))->toBe('legacy widget');
});

test('startup migration gate runs multiple pending migrations in order', function () {
    $dbPath = startupMigrationDbPath();
    $dir = startupMigrationTmpDir();

    writeMigration($dir, '2026_08_17_000000_create_widgets_table.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widgets');
    }
};
PHP);

    useStartupMigrationDatabase($dbPath);
    startupMigrationServiceRun([$dir]);

    writeMigration($dir, '2026_08_17_000100_add_color_to_widgets_table.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widgets', function (Blueprint $table) {
            $table->string('color')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('widgets', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
PHP);

    writeMigration($dir, '2026_08_17_000200_add_size_to_widgets_table.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widgets', function (Blueprint $table) {
            $table->string('size')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('widgets', function (Blueprint $table) {
            $table->dropColumn('size');
        });
    }
};
PHP);

    $result = startupMigrationServiceRun([$dir]);

    expect($result->appliedMigrations)->toBe([
        '2026_08_17_000100_add_color_to_widgets_table',
        '2026_08_17_000200_add_size_to_widgets_table',
    ])
        ->and(Schema::hasColumns('widgets', ['color', 'size']))->toBeTrue();
});

test('startup migration gate fails closed and preserves sqlite file on migration error', function () {
    $dbPath = startupMigrationDbPath();
    $dir = startupMigrationTmpDir();

    writeMigration($dir, '2026_08_17_000000_create_widgets_table.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widgets');
    }
};
PHP);

    useStartupMigrationDatabase($dbPath);
    startupMigrationServiceRun([$dir]);
    DB::table('widgets')->insert(['name' => 'keep me']);
    $beforeSize = filesize($dbPath);

    writeMigration($dir, '2026_08_17_000100_fail_widgets_upgrade.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        throw new RuntimeException('forced startup migration failure');
    }

    public function down(): void
    {
        //
    }
};
PHP);

    $result = startupMigrationServiceRun([$dir]);

    expect($result->status)->toBe('failed')
        ->and($result->errorMessage)->toContain('forced startup migration failure')
        ->and(file_exists($dbPath))->toBeTrue()
        ->and(filesize($dbPath))->toBeGreaterThanOrEqual($beforeSize)
        ->and(DB::table('widgets')->count())->toBe(1);
});

test('startup migration gate handles first install and is idempotent across repeated cold starts', function () {
    $dbPath = startupMigrationDbPath();
    $dir = startupMigrationTmpDir();

    writeMigration($dir, '2026_08_17_000000_create_first_install_table.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('first_install_rows', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('first_install_rows');
    }
};
PHP);

    useStartupMigrationDatabase($dbPath);

    $first = startupMigrationServiceRun([$dir]);
    $second = startupMigrationServiceRun([$dir]);

    expect($first->status)->toBe('migrated')
        ->and($second->status)->toBe('current')
        ->and(Schema::hasTable('first_install_rows'))->toBeTrue();
});

test('startup migration command emits machine readable markers and non-zero status on failure', function () {
    $dbPath = startupMigrationDbPath();
    $dir = startupMigrationTmpDir();

    writeMigration($dir, '2026_08_17_000000_fail_boot.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        throw new RuntimeException('command failure');
    }

    public function down(): void
    {
        //
    }
};
PHP);

    useStartupMigrationDatabase($dbPath);

    $runId = (string) Str::uuid();

    $this->artisan('app:startup-migrate', ['--path' => [$dir], '--run-id' => $runId])
        ->expectsOutputToContain("STARTUP_MIGRATION_RUN_ID={$runId}")
        ->expectsOutputToContain('STARTUP_MIGRATION_STATUS=failed')
        ->expectsOutputToContain("STARTUP_MIGRATION_DB_PATH={$dbPath}")
        ->expectsOutputToContain('STARTUP_MIGRATION_ARTIFACT='.StartupMigrationArtifact::path())
        ->expectsOutputToContain('STARTUP_MIGRATION_ERROR_MESSAGE=command failure')
        ->assertFailed();

    $artifact = json_decode((string) file_get_contents(StartupMigrationArtifact::path()), true, 512, JSON_THROW_ON_ERROR);

    expect($artifact)
        ->toMatchArray([
            'run_id' => $runId,
            'status' => 'failed',
            'database_path' => $dbPath,
            'connection' => 'sqlite',
            'failed_migration' => '2026_08_17_000000_fail_boot',
            'exception_class' => 'RuntimeException',
            'exception_message' => 'command failure',
        ])
        ->and($artifact['applied_migrations'])->toBeArray()
        ->and($artifact['timestamp'])->not->toBeEmpty();
});

test('startup migration command writes a fresh artifact for the current run id', function () {
    $dbPath = startupMigrationDbPath();
    $dir = startupMigrationTmpDir();

    writeMigration($dir, '2026_08_17_000000_create_widgets_table.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widgets');
    }
};
PHP);

    useStartupMigrationDatabase($dbPath);

    File::ensureDirectoryExists(dirname(StartupMigrationArtifact::path()));
    file_put_contents(StartupMigrationArtifact::path(), json_encode([
        'run_id' => 'stale-run-id',
        'status' => 'failed',
        'database_path' => '/stale.sqlite',
        'connection' => 'sqlite',
        'applied_migrations' => [],
        'failed_migration' => null,
        'exception_class' => null,
        'exception_message' => 'stale',
        'timestamp' => now()->subMinute()->toIso8601String(),
    ], JSON_THROW_ON_ERROR));

    $runId = (string) Str::uuid();

    $this->artisan('app:startup-migrate', ['--path' => [$dir], '--run-id' => $runId])
        ->expectsOutputToContain("STARTUP_MIGRATION_RUN_ID={$runId}")
        ->expectsOutputToContain('STARTUP_MIGRATION_STATUS=migrated')
        ->assertSuccessful();

    $artifact = json_decode((string) file_get_contents(StartupMigrationArtifact::path()), true, 512, JSON_THROW_ON_ERROR);

    expect($artifact['run_id'])->toBe($runId)
        ->and($artifact['status'])->toBe('migrated')
        ->and($artifact['database_path'])->toBe($dbPath)
        ->and($artifact['exception_message'])->toBeNull()
        ->and($artifact['applied_migrations'])->not->toBeEmpty();
});

test('startup migration gate repairs the s3 schema drift before application use', function () {
    $dbPath = startupMigrationDbPath();
    $dir = startupMigrationTmpDir();

    writeMigration($dir, '2026_08_17_000000_create_salescall_images_table.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salescall_images', function (Blueprint $table) {
            $table->id();
            $table->string('local_path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salescall_images');
    }
};
PHP);

    writeMigration($dir, '2026_08_17_000100_create_customer_profiles_table.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('signature_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_profiles');
    }
};
PHP);

    useStartupMigrationDatabase($dbPath);
    startupMigrationServiceRun([$dir]);

    DB::table('salescall_images')->insert(['local_path' => 'legacy-image.jpg']);
    DB::table('customer_profiles')->insert(['signature_path' => 'legacy-signature.png']);

    File::copy(
        base_path('database/migrations/2026_08_16_120000_add_s3_keys_to_tablet_uploads.php'),
        $dir.'/2026_08_16_120000_add_s3_keys_to_tablet_uploads.php',
    );

    $result = startupMigrationServiceRun([$dir]);

    DB::table('salescall_images')->where('id', 1)->update(['s3_key' => 'images/example-key']);
    DB::table('customer_profiles')->where('id', 1)->update(['signature_s3_key' => 'profiles/example-key']);

    expect($result->status)->toBe('migrated')
        ->and($result->appliedMigrations)->toBe(['2026_08_16_120000_add_s3_keys_to_tablet_uploads'])
        ->and(Schema::hasColumns('salescall_images', ['local_path', 's3_key']))->toBeTrue()
        ->and(Schema::hasColumns('customer_profiles', ['signature_path', 'signature_s3_key']))->toBeTrue()
        ->and(DB::table('salescall_images')->value('s3_key'))->toBe('images/example-key')
        ->and(DB::table('customer_profiles')->value('signature_s3_key'))->toBe('profiles/example-key')
        ->and(DB::table('salescall_images')->value('local_path'))->toBe('legacy-image.jpg')
        ->and(DB::table('customer_profiles')->value('signature_path'))->toBe('legacy-signature.png');
});
