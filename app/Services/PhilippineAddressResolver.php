<?php

namespace App\Services;

use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Region;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class PhilippineAddressResolver
{
    /**
     * @return array{ok: bool, reason: ?string, region_id: ?int, province_id: ?int, municipality_id: ?int, barangay_id: ?int, region_specific_id: ?int, area_cluster_id: ?int, address: ?string, address_is_partial: bool}
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

        $regionId = $this->matchRegion($stateTokens);
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
            if ((int) $municipality->region_id === (int) config('metro_manila_area_cluster_mapping.physical_region_id')) {
                $regionId = $municipality->region_id;
                $provinceId = $municipality->province_id;
            } else {
                $regionId ??= $municipality->region_id;
                $provinceId ??= $municipality->province_id;
            }
        }

        $locationMapping = app(LocationAreaClusterMappingResolver::class)->resolve($provinceId, $municipalityId, $barangayId);
        $regionSpecificId = in_array($locationMapping['decision'], ['mapped', 'requires_finer_location', 'unresolved'], true)
            ? $locationMapping['region_specific_id']
            : null;
        $areaClusterId = $locationMapping['decision'] === 'mapped' ? $locationMapping['area_cluster_id'] : null;

        $street = trim(implode(' ', array_filter([
            trim((string) ($components['house_number'] ?? '')),
            trim((string) ($components['road'] ?? '')),
        ], fn (string $component): bool => $component !== '')));
        $componentAddress = implode(', ', array_values(array_unique(array_filter([
            $street,
            $components['suburb'] ?? null,
            $components['city_district'] ?? null,
            $components['city'] ?? null,
            $components['municipality'] ?? null,
            $components['town'] ?? null,
            $components['county'] ?? null,
            $components['state_district'] ?? null,
            $components['state'] ?? null,
            $components['postcode'] ?? null,
            $components['country'] ?? null,
        ], fn (mixed $component): bool => is_string($component) && trim($component) !== ''))));
        $address = $display !== '' ? $display : $componentAddress;
        $addressIsPartial = $display === '' || mb_strlen($display) > 500;

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
            'address_is_partial' => $addressIsPartial,
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
            'area_cluster_id' => null, 'address' => null, 'address_is_partial' => true,
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function matchRegion(array $values): ?int
    {
        $regionId = $this->match(Region::class, $values, [], true);

        if ($regionId !== null) {
            return $regionId;
        }

        $aliases = (array) config('customer_location_lookup.region_aliases', []);
        $ids = [];

        foreach ($this->tokens($values) as $token) {
            $psgcCode = $aliases[$token] ?? null;

            if (! is_string($psgcCode) || $psgcCode === '') {
                continue;
            }

            $ids = [...$ids, ...Region::query()->where('psgc_code', $psgcCode)->pluck('id')->map(fn ($id): int => (int) $id)->all()];
        }

        $ids = array_values(array_unique($ids));

        return count($ids) === 1 ? $ids[0] : null;
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

        $rows = $query->get(['id', 'name']);
        $ids = $this->matchingIds($rows, $tokens, false);

        // Retry without a trailing "City" only when nothing matched exactly,
        // so a provider "Quezon" cannot silently resolve to "Quezon City".
        if ($ids === []) {
            $ids = $this->matchingIds($rows, $tokens, true);
        }

        return count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * @param  iterable<int, Model>  $rows
     * @param  list<string>  $tokens
     * @return list<int>
     */
    private function matchingIds(iterable $rows, array $tokens, bool $withoutCitySuffix): array
    {
        $tokens = $withoutCitySuffix
            ? array_map(fn (string $token): string => $this->withoutCitySuffix($token), $tokens)
            : $tokens;
        $ids = [];

        foreach ($rows as $row) {
            $candidates = $this->candidateNames($row->name);

            if ($withoutCitySuffix) {
                $candidates = array_map(fn (string $candidate): string => $this->withoutCitySuffix($candidate), $candidates);
            }

            if (array_intersect($candidates, $tokens) !== []) {
                $ids[] = (int) $row->id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function withoutCitySuffix(string $value): string
    {
        $stripped = preg_replace('/\s+city$/', '', $value);

        return is_string($stripped) && $stripped !== '' ? $stripped : $value;
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
