<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('tablet has the customer location reference schema', function () {
    expect(Schema::hasTable('barangays'))->toBeTrue()
        ->and(Schema::hasTable('area_clusters'))->toBeTrue()
        ->and(Schema::hasTable('customer_access_mappings'))->toBeTrue()
        ->and(Schema::hasColumns('customers', ['province_id', 'barangay_id', 'area_cluster_id']))->toBeTrue()
        ->and(Schema::hasColumns('barangays', ['municipality_id', 'psgc_code', 'code', 'name', 'enabled']))->toBeTrue()
        ->and(Schema::hasColumns('area_clusters', ['region_specific_id', 'code', 'name', 'enabled']))->toBeTrue()
        ->and(Schema::hasColumns('customer_access_mappings', ['scope', 'mapping_key', 'is_complete', 'user_references']))->toBeTrue();
});
