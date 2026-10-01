<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class LocationReferenceBaselineService
{
    public const KEY = 'tablet-location';

    private const STARTUP_DIAGNOSTICS_PATH = 'framework/nativephp/location-baseline-status.json';

    public function apply(): array
    {
        $path = base_path('database/reference/tablet-location-baseline.json.gz');
        if (! File::exists($path)) {
            throw new RuntimeException('Tablet location baseline artifact is missing.');
        }

        if (! Schema::hasTable('reference_baseline_versions')) {
            throw new RuntimeException('Reference baseline marker table is missing.');
        }

        $payload = json_decode(gzdecode(File::get($path)), true, 512, JSON_THROW_ON_ERROR);
        $this->validatePayload($payload);

        $version = (string) $payload['baseline_version'];
        $current = DB::table('reference_baseline_versions')
            ->where('key', self::KEY)
            ->value('version');

        if ($current === $version && $this->isComplete($payload)) {
            return [
                'status' => 'current',
                'version' => $version,
                'counts' => $this->counts($payload),
                'integrity_check' => 'passed',
                'repair_attempted' => false,
                'repair_completed' => false,
            ];
        }

        $repairAttempted = $current === $version;

        DB::transaction(function () use ($payload, $version): void {
            $now = now();

            $this->upsert('regions', $payload['regions'], ['code', 'psgc_code', 'name'], $now);
            $this->upsert('region_specifics', $payload['region_specifics'], ['region_id', 'name', 'sort'], $now);
            $this->upsert('provinces', $payload['provinces'], ['region_id', 'region_specific_id', 'psgc_code', 'name', 'enabled'], $now);
            $this->upsert('municipalities', $payload['municipalities'], ['psgc_code', 'region_id', 'province_id', 'locality_type', 'name', 'sort', 'enabled'], $now);
            $this->upsert('area_clusters', $payload['area_clusters'], ['region_specific_id', 'name', 'enabled'], $now);
            $this->upsert('barangays', $payload['barangays'], ['municipality_id', 'name', 'enabled'], $now);

            DB::table('reference_baseline_versions')->upsert([
                ['key' => self::KEY, 'version' => $version, 'applied_at' => $now],
            ], ['key'], ['version', 'applied_at']);
        });

        return [
            'status' => 'applied',
            'version' => $version,
            'counts' => $this->counts($payload),
            'integrity_check' => $repairAttempted ? 'failed' : 'not_applicable',
            'repair_attempted' => $repairAttempted,
            'repair_completed' => true,
        ];
    }

    public function startupDiagnosticsPath(): string
    {
        return storage_path(self::STARTUP_DIAGNOSTICS_PATH);
    }

    public function writeStartupDiagnostics(array $diagnostics): void
    {
        $path = $this->startupDiagnosticsPath();

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'recorded_at' => now()->toIso8601String(),
            ...$diagnostics,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function upsert(string $table, array $rows, array $columns, mixed $now): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            $values = array_map(function (array $row) use ($columns, $now): array {
                $value = ['id' => $row['id']];
                foreach ($columns as $column) {
                    $value[$column] = $row[$column] ?? (in_array($column, ['sort'], true) ? 0 : null);
                }
                $value['updated_at'] = $now;

                return $value;
            }, $chunk);

            DB::table($table)->upsert($values, ['id'], [...$columns, 'updated_at']);
        }
    }

    private function validatePayload(array $payload): void
    {
        foreach (['format_version', 'baseline_version', 'regions', 'region_specifics', 'provinces', 'municipalities', 'area_clusters', 'barangays'] as $key) {
            if (! array_key_exists($key, $payload)) {
                throw new RuntimeException("Tablet location baseline is missing {$key}.");
            }
        }

        if ((int) $payload['format_version'] !== 1 || ! is_string($payload['baseline_version'])) {
            throw new RuntimeException('Tablet location baseline format is unsupported.');
        }
    }

    private function counts(array $payload): array
    {
        $counts = array_fill_keys(
            ['regions', 'region_specifics', 'provinces', 'municipalities', 'area_clusters', 'barangays'],
            0
        );

        foreach (array_keys($counts) as $key) {
            $counts[$key] = count($payload[$key]);
        }

        return $counts;
    }

    private function isComplete(array $payload): bool
    {
        foreach ($this->tableMap() as $table => $payloadKey) {
            if (DB::table($table)->count() < count($payload[$payloadKey])) {
                return false;
            }
        }

        return true;
    }

    private function tableMap(): array
    {
        return [
            'regions' => 'regions',
            'region_specifics' => 'region_specifics',
            'provinces' => 'provinces',
            'municipalities' => 'municipalities',
            'area_clusters' => 'area_clusters',
            'barangays' => 'barangays',
        ];
    }
}
