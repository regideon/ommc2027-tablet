<?php

namespace App\Services;

use App\Models\AreaCluster;
use App\Models\Barangay;
use App\Models\LocationAreaClusterMapping;
use App\Models\Municipality;
use App\Models\RegionSpecific;

class MetroManilaAreaClusterResolver
{
    /** @return array<int, int>|null Null means outside this NCR rule; empty means fail closed. */
    public function eligibleClusterIds(?int $municipalityId): ?array
    {
        $codes = $municipalityId === null ? null : config('metro_manila_area_cluster_mapping.municipality_area_codes.'.$municipalityId);
        if (! is_array($codes) || $codes === []) {
            return null;
        }

        $physicalId = (int) config('metro_manila_area_cluster_mapping.physical_region_id');
        $specificId = (int) config('metro_manila_area_cluster_mapping.operational_region_specific_id');
        $parentId = (int) config('metro_manila_area_cluster_mapping.operational_parent_region_id');
        $municipality = Municipality::query()->find($municipalityId);
        if (! $municipality || ! $municipality->enabled || (int) $municipality->region_id !== $physicalId
            || $municipality->province_id !== null
            || ! RegionSpecific::query()->whereKey($specificId)->where('region_id', $parentId)->exists()) {
            return [];
        }

        $clusters = AreaCluster::query()->where('region_specific_id', $specificId)->where('enabled', true)
            ->whereIn('code', $codes)->get()->keyBy('code');
        if ($clusters->count() !== count($codes)) {
            return [];
        }

        return collect($codes)->map(fn (string $code): int => (int) $clusters[$code]->id)->all();
    }

    /** @return array{decision:string,area_cluster_id:?int,region_specific_id:?int,source_level:?string}|null */
    public function resolve(?int $provinceId, ?int $municipalityId, ?int $barangayId): ?array
    {
        $eligible = $this->eligibleClusterIds($municipalityId);
        if ($eligible === null) {
            return null;
        }
        $specificId = (int) config('metro_manila_area_cluster_mapping.operational_region_specific_id');
        if ($eligible === [] || $provinceId !== null) {
            return $this->result('unresolved', null, $specificId, 'municipality');
        }

        if ($barangayId !== null) {
            $mapping = LocationAreaClusterMapping::query()->where('location_level', 'barangay')->where('location_id', $barangayId)->first();
            if ($mapping) {
                if ($mapping->decision !== 'mapped') {
                    return $this->result($mapping->decision, null, $specificId, 'barangay');
                }
                if (in_array((int) $mapping->area_cluster_id, $eligible, true)) {
                    return $this->result('mapped', (int) $mapping->area_cluster_id, $specificId, 'barangay');
                }

                return $this->result('invalid', null, null, 'barangay');
            }
        }

        return count($eligible) === 1
            ? $this->result('mapped', $eligible[0], $specificId, 'municipality')
            : $this->result('requires_finer_location', null, $specificId, 'municipality');
    }

    public function isApprovedLocationMapping(string $level, int $locationId, int $clusterId): bool
    {
        $municipalityId = match ($level) {
            'municipality' => $locationId,
            'barangay' => Barangay::query()->whereKey($locationId)->value('municipality_id'),
            default => null,
        };
        $eligible = $this->eligibleClusterIds($municipalityId === null ? null : (int) $municipalityId);

        return $eligible !== null && in_array($clusterId, $eligible, true);
    }

    /** @return array{decision:string,area_cluster_id:?int,region_specific_id:?int,source_level:?string} */
    private function result(string $decision, ?int $clusterId, ?int $specificId, ?string $level): array
    {
        return ['decision' => $decision, 'area_cluster_id' => $clusterId, 'region_specific_id' => $specificId, 'source_level' => $level];
    }
}
