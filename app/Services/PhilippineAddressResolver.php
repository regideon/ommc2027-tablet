<?php

namespace App\Services;

use App\Models\AreaCluster;
use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Region;
use App\Models\RegionSpecific;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class PhilippineAddressResolver
{
    /**
     * @return array{ok: bool, reason: ?string, region_id: ?int, province_id: ?int, municipality_id: ?int, barangay_id: ?int, region_specific_id: ?int, area_cluster_id: ?int, address: ?string}
     */
    public function resolve(float $latitude, float $longitude): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) config('app.name', 'OMMC').' customer-location',
                'Accept' => 'application/json',
            ])->timeout(10)->get('https://nominatim.openstreetmap.org/reverse', [
                'format' => 'jsonv2',
                'lat' => $latitude,
                'lon' => $longitude,
                'zoom' => 18,
                'addressdetails' => 1,
            ]);
        } catch (Throwable) {
            return $this->failure('unreachable');
        }

        if (! $response->successful()) {
            return $this->failure('unreachable');
        }

        $components = (array) $response->json('address', []);
        $display = trim((string) $response->json('display_name', ''));

        $stateTokens = [$components['state'] ?? null, $components['region'] ?? null];

        $regionId = $this->match(Region::class, $stateTokens, [], true);
        $provinceId = $this->match(Province::class, [$components['county'] ?? null, $components['state_district'] ?? null], ['enabled' => true], true);
        $municipalityId = $this->match(Municipality::class, [
            $components['city'] ?? null,
            $components['municipality'] ?? null,
            $components['town'] ?? null,
            $components['city_district'] ?? null,
        ], $provinceId
            ? ['enabled' => true, 'province_id' => $provinceId]
            : ($regionId ? ['enabled' => true, 'region_id' => $regionId] : ['enabled' => true]), true);
        $barangayId = $municipalityId
            ? $this->match(Barangay::class, [
                $components['suburb'] ?? null,
                $components['village'] ?? null,
                $components['neighbourhood'] ?? null,
                $components['quarter'] ?? null,
                $components['hamlet'] ?? null,
            ], ['enabled' => true, 'municipality_id' => $municipalityId])
            : null;

        // The matched municipality is the most reliable local relationship, so
        // take the physical region/province from it rather than trusting
        // Nominatim's county/state naming.
        if ($municipalityId && ($municipality = Municipality::query()->find($municipalityId, ['id', 'region_id', 'province_id']))) {
            $regionId ??= $municipality->region_id;
            $provinceId ??= $municipality->province_id;
        }

        $regionSpecificId = $this->match(RegionSpecific::class, $stateTokens, $regionId ? ['region_id' => $regionId] : []);

        if ($regionSpecificId === null && $provinceId) {
            $regionSpecificId = Province::query()->whereKey($provinceId)->value('region_specific_id');
        }

        if ($regionSpecificId === null && $regionId) {
            $regionSpecificId = $this->onlyRegionSpecificId($regionId);
        }

        $areaClusterId = $regionSpecificId ? $this->uniqueAreaClusterId($regionSpecificId) : null;

        $street = trim(((string) ($components['house_number'] ?? '')).' '.((string) ($components['road'] ?? '')));
        $address = $street !== '' ? $street : $display;

        return [
            'ok' => true,
            'reason' => null,
            'region_id' => $regionId,
            'province_id' => $provinceId,
            'municipality_id' => $municipalityId,
            'barangay_id' => $barangayId,
            'region_specific_id' => $regionSpecificId,
            'area_cluster_id' => $areaClusterId,
            'address' => $address !== '' ? Str::limit($address, 500, '') : null,
        ];
    }

    /**
     * @return array{ok: false, reason: string, region_id: null, province_id: null, municipality_id: null, barangay_id: null, region_specific_id: null, area_cluster_id: null, address: null}
     */
    private function failure(string $reason): array
    {
        return [
            'ok' => false, 'reason' => $reason, 'region_id' => null, 'province_id' => null,
            'municipality_id' => null, 'barangay_id' => null, 'region_specific_id' => null,
            'area_cluster_id' => null, 'address' => null,
        ];
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<int, mixed>  $values
     * @param  array<string, mixed>  $constraints
     */
    private function match(string $model, array $values, array $constraints = [], bool $requirePsgc = false): ?int
    {
        $tokens = $this->tokens($values);

        if ($tokens === []) {
            return null;
        }

        $query = $model::query();

        // Prefer authoritative PSGC rows. Legacy/sample rows carry a null
        // psgc_code and incomplete relationships (e.g. a "Quezon City"
        // municipality with no barangays, or cities miscoded as provinces).
        if ($requirePsgc) {
            $query->whereNotNull('psgc_code');
        }

        foreach ($constraints as $column => $value) {
            $query->where($column, $value);
        }

        $ids = [];
        foreach ($query->get(['id', 'name']) as $row) {
            if (array_intersect($this->candidateNames($row->name), $tokens) !== []) {
                $ids[] = (int) $row->id;
            }
        }

        $ids = array_values(array_unique($ids));

        return count($ids) === 1 ? $ids[0] : null;
    }

    private function uniqueAreaClusterId(int $regionSpecificId): ?int
    {
        $ids = AreaCluster::query()
            ->where('region_specific_id', $regionSpecificId)
            ->where('enabled', true)
            ->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    private function onlyRegionSpecificId(int $regionId): ?int
    {
        $ids = RegionSpecific::query()->where('region_id', $regionId)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function tokens(array $values): array
    {
        $tokens = [];

        foreach ($values as $value) {
            if (is_string($value) && ($normalized = $this->normalize($value)) !== '') {
                $tokens[] = $normalized;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @return list<string>
     */
    private function candidateNames(?string $name): array
    {
        $name = (string) $name;
        $candidates = [$this->normalize($name)];

        if (preg_match('/\(([^)]+)\)/', $name, $match) === 1) {
            $candidates[] = $this->normalize($match[1]);
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    private function normalize(string $value): string
    {
        $value = Str::ascii($value);
        $value = Str::lower($value);
        $value = preg_replace('/\b(?:city of|municipality of|barangay|brgy\.?)\b/', ' ', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
