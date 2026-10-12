<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class LocationAreaClusterMapping extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (self $mapping): void {
            if (! in_array($mapping->location_level, ['province', 'municipality', 'barangay'], true)
                || ! in_array($mapping->decision, ['mapped', 'unresolved', 'requires_finer_location'], true)) {
                throw ValidationException::withMessages(['location_area_cluster_mapping' => 'The location mapping decision is invalid.']);
            }

            $model = match ($mapping->location_level) {
                'province' => Province::class,
                'municipality' => Municipality::class,
                'barangay' => Barangay::class,
            };
            $location = $model::query()->find($mapping->location_id);
            if (! $location) {
                throw ValidationException::withMessages(['location_area_cluster_mapping' => 'The mapped geographic reference does not exist.']);
            }
            if ($mapping->decision !== 'mapped') {
                if ($mapping->area_cluster_id !== null) {
                    throw ValidationException::withMessages(['location_area_cluster_mapping' => 'A blocked mapping cannot include an Area Cluster.']);
                }

                return;
            }

            $cluster = AreaCluster::query()->whereKey($mapping->area_cluster_id)->where('enabled', true)->first();
            $specificRegion = $cluster ? RegionSpecific::query()->whereKey($cluster->region_specific_id)->first() : null;
            $regionId = match ($mapping->location_level) {
                'province', 'municipality' => $location->region_id,
                'barangay' => Municipality::query()->whereKey($location->municipality_id)->value('region_id'),
            };
            if (! $cluster || ! $specificRegion || $regionId === null || (int) $specificRegion->region_id !== (int) $regionId) {
                throw ValidationException::withMessages(['location_area_cluster_mapping' => 'The Area Cluster must belong to the same physical Region as the mapped location.']);
            }
        });
    }
}
