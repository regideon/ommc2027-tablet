<?php

namespace App\Services;

use App\Models\AreaCluster;
use App\Models\Barangay;
use App\Models\LocationAreaClusterMapping;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\RegionSpecific;
use Illuminate\Support\Facades\Schema;

class LocationAreaClusterMappingResolver
{
    /** @return array{decision: string, area_cluster_id: ?int, region_specific_id: ?int, source_level: ?string} */
    public function resolve(?int $provinceId, ?int $municipalityId, ?int $barangayId): array
    {
        if (! $this->hierarchyIsConsistent($provinceId, $municipalityId, $barangayId)) {
            return ['decision' => 'invalid', 'area_cluster_id' => null, 'region_specific_id' => null, 'source_level' => null];
        }

        if (! Schema::hasTable('location_area_cluster_mappings')) {
            return $this->unmapped();
        }

        foreach (['barangay' => $barangayId, 'municipality' => $municipalityId, 'province' => $provinceId] as $level => $id) {
            if (! $id) {
                continue;
            }

            $mapping = LocationAreaClusterMapping::query()->where('location_level', $level)->where('location_id', $id)->first();
            if (! $mapping) {
                continue;
            }
            if ($mapping->decision !== 'mapped') {
                return ['decision' => $mapping->decision, 'area_cluster_id' => null, 'region_specific_id' => null, 'source_level' => $level];
            }

            $cluster = AreaCluster::query()->whereKey($mapping->area_cluster_id)->where('enabled', true)->first();
            $specificRegion = $cluster ? RegionSpecific::query()->whereKey($cluster->region_specific_id)->first() : null;
            $regionId = $this->physicalRegionId($level, (int) $id);
            if (! $cluster || ! $specificRegion || $regionId === null || (int) $specificRegion->region_id !== $regionId) {
                return ['decision' => 'invalid', 'area_cluster_id' => null, 'region_specific_id' => null, 'source_level' => $level];
            }

            return ['decision' => 'mapped', 'area_cluster_id' => (int) $cluster->id, 'region_specific_id' => (int) $specificRegion->id, 'source_level' => $level];
        }

        return $this->unmapped();
    }

    private function physicalRegionId(string $level, int $id): ?int
    {
        $regionId = match ($level) {
            'province' => Province::query()->whereKey($id)->value('region_id'),
            'municipality' => Municipality::query()->whereKey($id)->value('region_id'),
            'barangay' => Barangay::query()->join('municipalities', 'municipalities.id', '=', 'barangays.municipality_id')->where('barangays.id', $id)->value('municipalities.region_id'),
            default => null,
        };

        return $regionId === null ? null : (int) $regionId;
    }

    private function hierarchyIsConsistent(?int $provinceId, ?int $municipalityId, ?int $barangayId): bool
    {
        if ($municipalityId) {
            $municipality = Municipality::query()->find($municipalityId, ['id', 'province_id']);
            if (! $municipality || ($provinceId && (int) $municipality->province_id !== $provinceId)
                || (! $provinceId && $municipality->province_id !== null)) {
                return false;
            }
        }
        if ($barangayId) {
            $barangay = Barangay::query()->find($barangayId, ['id', 'municipality_id']);
            if (! $barangay || ($municipalityId && (int) $barangay->municipality_id !== $municipalityId)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{decision: string, area_cluster_id: null, region_specific_id: null, source_level: null} */
    private function unmapped(): array
    {
        return ['decision' => 'unmapped', 'area_cluster_id' => null, 'region_specific_id' => null, 'source_level' => null];
    }
}
