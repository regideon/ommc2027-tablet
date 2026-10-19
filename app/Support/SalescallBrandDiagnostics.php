<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SalescallBrandDiagnostics
{
    private const MAX_SELECTED_BRANDS = 25;

    private const MAX_FOREIGN_KEY_VIOLATIONS = 25;

    private const RELEVANT_MIGRATIONS = [
        '2026_06_01_032129_create_material_groups_table',
        '2026_06_01_032136_create_brands_table',
        '2026_07_06_000001_create_salescall_brands_table',
        '2026_07_06_000002_seed_none_brand_for_material_groups',
        '2026_07_06_000003_add_customer_id_to_salescall_brands_table',
    ];

    /**
     * @return array<string, mixed>
     */
    public function collect(int $salescallId, array $brandSelections): array
    {
        $metadata = app(NativeAppReleaseMetadata::class)->read();
        $driver = DB::connection()->getDriverName();
        $salescall = Schema::hasTable('salescalls')
            ? DB::table('salescalls')->where('id', $salescallId)->first(['id', 'customer_id'])
            : null;
        $customerId = $salescall?->customer_id;
        $customer = $customerId !== null && Schema::hasTable('customers')
            ? DB::table('customers')->where('id', $customerId)->whereNull('deleted_at')->first(['id', 'server_id'])
            : null;
        $serverIdMatches = $customerId !== null && Schema::hasTable('customers')
            ? DB::table('customers')->where('server_id', $customerId)->whereNull('deleted_at')->limit(2)->get(['id', 'server_id'])
            : collect();

        $diagnostics = [
            'app' => [
                'version' => $metadata['version'],
                'build' => $metadata['build'],
            ],
            'database' => [
                'driver' => $driver,
                'schema_version' => null,
            ],
            'migrations' => $this->migrationStatus(),
            'salescall_brands_foreign_keys' => [],
            'foreign_key_check' => [
                'rows' => [],
                'truncated' => false,
                'limit' => self::MAX_FOREIGN_KEY_VIOLATIONS,
            ],
            'references' => [
                'salescall' => [
                    'local_id' => $salescallId,
                    'exists' => $salescall !== null,
                    'customer_id' => $customerId,
                ],
                'customer' => [
                    'local_id' => $customerId,
                    'exists' => $customer !== null,
                    'server_id' => $customer?->server_id,
                    'server_id_match' => [
                        'count_capped_at_two' => $serverIdMatches->count(),
                        'unique_local_id' => $serverIdMatches->count() === 1 ? (int) $serverIdMatches->first()->id : null,
                        'ambiguous' => $serverIdMatches->count() > 1,
                        'conflicts_with_local_id' => $customer !== null
                            && $serverIdMatches->count() === 1
                            && (int) $serverIdMatches->first()->id !== (int) $customer->id,
                    ],
                ],
                'selected_brands' => $this->selectedBrandDiagnostics($brandSelections),
            ],
            'lookup_sync_status' => [
                'available' => false,
                'reason' => 'Lookup tables do not expose synchronization status columns.',
            ],
        ];

        if ($driver !== 'sqlite') {
            $diagnostics['database']['note'] = 'SQLite-specific checks were not run because the active connection is not SQLite.';

            return $diagnostics;
        }

        $schemaVersion = DB::selectOne('PRAGMA schema_version');
        $diagnostics['database']['schema_version'] = $schemaVersion?->schema_version;
        $diagnostics['database']['foreign_keys_enabled'] = (int) (DB::selectOne('PRAGMA foreign_keys')?->foreign_keys ?? 0) === 1;

        if (Schema::hasTable('salescall_brands')) {
            $diagnostics['salescall_brands_foreign_keys'] = DB::select("PRAGMA foreign_key_list('salescall_brands')");
            $violations = DB::select('SELECT * FROM pragma_foreign_key_check LIMIT '.(self::MAX_FOREIGN_KEY_VIOLATIONS + 1));
            $diagnostics['foreign_key_check'] = [
                'rows' => array_slice($violations, 0, self::MAX_FOREIGN_KEY_VIOLATIONS),
                'truncated' => count($violations) > self::MAX_FOREIGN_KEY_VIOLATIONS,
                'limit' => self::MAX_FOREIGN_KEY_VIOLATIONS,
            ];
        }

        $syncColumns = collect(['material_groups', 'brands'])
            ->filter(fn (string $table): bool => Schema::hasTable($table) && Schema::hasColumn($table, 'sync_status'))
            ->values();

        if ($syncColumns->isNotEmpty()) {
            $diagnostics['lookup_sync_status'] = [
                'available' => true,
                'tables' => $syncColumns->mapWithKeys(fn (string $table): array => [
                    $table => $this->syncStatusForSelectedRows($table, $brandSelections),
                ])->all(),
            ];
        }

        return $diagnostics;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function selectedBrandDiagnostics(array $brandSelections): array
    {
        $diagnostics = [];

        foreach ($brandSelections as $materialGroupId => $rows) {
            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (! is_array($row) || ! isset($row['brand_id']) || $row['brand_id'] === '') {
                    continue;
                }

                if (count($diagnostics) >= self::MAX_SELECTED_BRANDS) {
                    return $diagnostics;
                }

                $localMaterialGroupId = $this->localId($materialGroupId);
                $localBrandId = $this->localId($row['brand_id']);
                $materialGroupExists = $localMaterialGroupId !== null
                    && Schema::hasTable('material_groups')
                    && DB::table('material_groups')->where('id', $localMaterialGroupId)->exists();
                $brand = $localBrandId !== null && Schema::hasTable('brands')
                    ? DB::table('brands')->where('id', $localBrandId)->first(['id', 'material_group_id'])
                    : null;

                $diagnostics[] = [
                    'material_group_id' => $localMaterialGroupId,
                    'material_group_exists' => $materialGroupExists,
                    'brand_id' => $localBrandId,
                    'brand_exists' => $brand !== null,
                    'brand_material_group_id' => $brand?->material_group_id,
                    'brand_matches_selected_material_group' => $brand && $localMaterialGroupId !== null
                        ? (int) $brand->material_group_id === $localMaterialGroupId
                        : null,
                ];
            }
        }

        return $diagnostics;
    }

    private function localId(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT);

        return $id === false ? null : $id;
    }

    private function syncStatusForSelectedRows(string $table, array $brandSelections): array
    {
        $ids = [];

        foreach ($brandSelections as $materialGroupId => $rows) {
            if ($table === 'material_groups') {
                $id = $this->localId($materialGroupId);
                if ($id !== null) {
                    $ids[$id] = $id;
                }

                continue;
            }

            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                $id = is_array($row) ? $this->localId($row['brand_id'] ?? null) : null;
                if ($id !== null) {
                    $ids[$id] = $id;
                }
            }
        }

        return collect(array_slice(array_values($ids), 0, self::MAX_SELECTED_BRANDS))
            ->mapWithKeys(fn (int $id): array => [$id => DB::table($table)->where('id', $id)->value('sync_status')])
            ->all();
    }

    /**
     * @return array<string, array{applied: bool, batch: int|null}>
     */
    private function migrationStatus(): array
    {
        if (! Schema::hasTable('migrations')) {
            return array_fill_keys(self::RELEVANT_MIGRATIONS, ['applied' => false, 'batch' => null]);
        }

        return collect(self::RELEVANT_MIGRATIONS)->mapWithKeys(function (string $migration): array {
            $row = DB::table('migrations')->where('migration', $migration)->first(['batch']);

            return [$migration => ['applied' => $row !== null, 'batch' => $row?->batch]];
        })->all();
    }
}
