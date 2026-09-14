<?php

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerCategoryHistory;
use App\Models\CustomerTradeProfile;
use App\Services\CustomerProfileFormService;

test('phase three profile helper exposes the accepted stream years and options', function () {
    expect(CustomerProfileFormService::categoryYears('outlet'))->toBe(range(2018, 2026))
        ->and(CustomerProfileFormService::categoryYears('fleet'))->toBe(range(2018, 2026))
        ->and(CustomerProfileFormService::categoryYears('oe'))->toBe(range(2018, 2025))
        ->and(CustomerProfileFormService::categoryYears('ib'))->toBe(range(2018, 2025))
        ->and(CustomerProfileFormService::categoryStreams('outlet'))->toBe(['ab', 'mcb'])
        ->and(CustomerProfileFormService::categoryOptions('ib', 'ib'))->toHaveKey('Motolite Only');
});

test('phase three hydration classifies deterministic legacy annual rows without inventing ambiguous streams', function () {
    $customer = new Customer(['company_id' => 1, 'name' => 'Legacy']);
    $customer->setRelation('company', (new Company)->forceFill(['code' => 'OMMC']));
    $customer->setRelation('municipality', null);
    $customer->setRelation('users', collect());
    $customer->setRelation('tradeProfile', new CustomerTradeProfile(['profile_type' => 'outlet', 'entry_detail' => null]));
    $customer->setRelation('categoryHistories', collect([
        new CustomerCategoryHistory(['category_year' => 2024, 'category' => 'AB Company Owned']),
        new CustomerCategoryHistory(['category_year' => 2025, 'category' => 'Unclassified Legacy']),
    ]));

    $state = CustomerProfileFormService::hydrate($customer);

    expect($state['categories']['ab'][2024])->toBe('AB Company Owned')
        ->and($state['categories']['ab'][2025])->toBeNull();
});
