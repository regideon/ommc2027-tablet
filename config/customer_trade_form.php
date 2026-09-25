<?php

return [
    'allowed_profile_types' => ['outlet', 'fleet', 'oe', 'ib'],
    'company_profile_map' => [
        'OMMC' => 'outlet', 'LAST_MILE' => 'outlet', 'CAR_CLUBS' => 'outlet',
        'FLEET' => 'fleet', 'OE' => 'oe', 'IB' => 'ib',
    ],
    'external_dependencies' => [
        'geocoder' => null, 'barangay_mapping' => null,
        'area_cluster_mapping' => null, 'serving_outlet_mapping' => null,
    ],
    'profile_contract' => [
        'common' => ['company_id', 'access_user_ids', 'name', 'person_in_charge_id', 'rsm', 'region', 'specific_region', 'province', 'municipality', 'barangay', 'area_cluster', 'address', 'latitude', 'longitude', 'contact_person', 'business_landline_number', 'business_mobile_number', 'date_established'],
        'outlet' => ['serving_outlet_id', 'entry_detail', 'annual_categories', 'classifications', 'conversion_program', 'working_days', 'operating_hours', 'motiv_user', 'warehouse_code', 'delivery_type', 'delivery_detail', 'ulab'],
        'fleet' => ['account_name', 'drm_rsr_user_ids', 'serving_outlet_id', 'annual_categories', 'account_type', 'classifications', 'battery_class', 'status'],
        'oe' => ['account_name', 'drm_rsr_user_ids', 'entry_detail', 'annual_categories', 'classifications', 'battery_class', 'sulfuric_acid'],
        'ib' => ['account_name', 'drm_rsr_user_ids', 'serving_outlet_id', 'annual_categories', 'classifications'],
        'owner' => ['owner.name', 'owner.birthday', 'owner.nickname', 'owner.successor_name', 'owner.successor_birthday', 'owner.relationship', 'owner.generation', 'owner.hobbies'],
    ],
    'field_rules' => [
        'person_in_charge_id' => ['profiles' => ['outlet'], 'required' => true, 'type' => 'user'],
        'rsm' => ['profiles' => ['outlet', 'fleet', 'oe', 'ib'], 'derived' => true, 'source' => 'assigned_users.rsm_id'],
        'region' => ['derived' => true, 'source' => 'coordinates'], 'specific_region' => ['derived' => true, 'source' => 'coordinates'],
        'province' => ['derived' => true, 'source' => 'coordinates'], 'municipality' => ['derived' => true, 'source' => 'coordinates'],
        'barangay' => ['derived' => true, 'source' => 'coordinates', 'available' => false],
        'area_cluster' => ['derived' => true, 'source' => 'province_mapping', 'available' => false],
        'serving_outlet_id' => ['available' => false, 'source' => 'serving_outlet_mapping'],
        'warehouse_code' => ['required_when' => ['motiv_user' => true]], 'delivery_detail' => ['required_when' => ['delivery_type' => true]],
        'conversion_program' => ['conditional' => true, 'source' => 'non_exclusive_prior_year_category'],
    ],
    'category_streams' => [
        'outlet' => ['years' => range(2018, 2026), 'streams' => ['ab', 'mcb']],
        'fleet' => ['years' => range(2018, 2026), 'streams' => ['fleet']],
        'oe' => ['years' => range(2018, 2025), 'streams' => ['oe']],
        'ib' => ['years' => range(2018, 2025), 'streams' => ['ib']],
    ],
    'profile_classifications' => [
        'outlet' => ['Auto Supply, Car Parts and Accessories', 'Battery Specialist', 'Hardware', 'General Merchandise', 'Motorcycle Shop, Motorcycle Parts and Accessories', 'Repair/Service Center', 'Tires, Oil, and Lubes', 'Trading', 'Trucking', 'Rent a Car'],
        'fleet' => ['Agriculture', 'Assembler', 'Bus', 'Cargo Trucking', 'Company Service Vehicle', 'Concrete Mix', 'Construction', 'Dealership', 'Distributor', 'Fishing', 'Food Manufacturing', 'Hardware', 'Hauling', 'Heavy Equipment', 'Industrial Plant', 'Logistics', 'Manufacturing', 'Mining', 'Real Estate', 'Security', 'Services', 'Shipping', 'Taxi', 'Transportation'],
        'oe' => ['Car Manufacturer', 'MCB Assembler', 'Acid Account'],
        'ib' => ['Agriculture', 'Assembler', 'Bus', 'Cargo Trucking', 'Company Service Vehicle', 'Concrete Mix', 'Construction', 'Dealership', 'Distributor', 'Fishing', 'Food Manufacturing', 'Hardware', 'Hauling', 'Heavy Equipment', 'Industrial Plant', 'Logistics', 'Manufacturing', 'Mining', 'Real Estate', 'Security', 'Services', 'Shipping', 'Taxi', 'Transportation'],
    ],
    'profile_entry_details' => ['outlet' => ['AB', 'MCB', 'AB and MCB'], 'oe' => ['AB', 'AB/MCB', 'MCB', 'Acid']],
    'profile_category_options' => [
        'outlet' => [
            'ab' => ['AB Company Owned', 'AB JV Equity', 'AB JV Retail', 'AB Non-JV', 'AB Supply Agreement', 'AB MADP Outlet', 'AB Mercury VDP Loyal', 'AB Mass V Loyal', 'AB Loyal', 'AB SMDP', 'AB VIP', 'AB Mixed', 'AB Competitor Dealer', 'AB Competitor Owned', 'Inactive', 'Closed', 'Nonexistent'],
            'mcb' => ['MCB Area Champion Exclusive', 'MCB Area Champion Mixed', 'MCB Dealer - Loyal', 'MCB Dealer - Mixed', 'MCB Dealer - Competitor', 'Inactive', 'Closed', 'Nonexistent'],
        ],
        'fleet' => ['Motolite Only', 'Ramcar Only', 'Mixed', 'Competitor', 'Closed', 'Nonexistent'],
        'oe' => ['Motolite Only', 'Ramcar Only', 'Mixed', 'Competitor', 'Inactive', 'Closed', 'Nonexistent'],
        'ib' => ['Motolite Only', 'Mixed', 'Competitor', 'Inactive', 'Closed', 'Nonexistent'],
    ],
    'category_years' => range(2018, 2026),
    'entry_details' => ['AB', 'MCB', 'AB and MCB'],
    'categories' => [
        'AB' => ['AB Company Owned', 'AB JV Equity', 'AB JV Retail', 'AB Non-JV', 'AB Supply Agreement', 'AB MADP Outlet', 'AB Mercury VDP Loyal', 'AB Mass V Loyal', 'AB Loyal', 'AB SMDP', 'AB VIP', 'AB Mixed', 'AB Competitor Dealer', 'AB Competitor Owned', 'Inactive', 'Closed', 'Nonexistent'],
        'MCB' => ['MCB Area Champion Exclusive', 'MCB Area Champion Mixed', 'MCB Dealer - Loyal', 'MCB Dealer - Mixed', 'MCB Dealer - Competitor', 'Inactive', 'Closed', 'Nonexistent'],
    ],
    'classifications' => ['Auto Supply, Car Parts and Accessories', 'Battery Specialist', 'General Merchandise', 'Hardware', 'Motorcycle Shop, Motorcycle Parts and Accessories', 'Repair/Service Center', 'Tires, Oils, and Lubes', 'Trading', 'Trucking', 'Rent a car', 'Tires, Oil, Hardware and Others'],
    'brands' => [
        'ommc_brands' => ['Motolite', 'Mercury', 'Mass V', 'Grab Brands (Supercharge, Century, etc.)', 'None'],
        'ommc_mcb_brands' => ['Motolite MCB', 'Superlite', 'Champion', 'Dynapower', 'None'],
        'tpl_pollux' => ['Amaron', 'Aspira', 'Autobacs', 'Control Plus', 'Elito', 'GS', 'Hitachi', 'Incoe', 'Nagoya', 'Quantum', 'Quick start', 'Raiden', 'Supreme', 'Tuflong', 'Others', 'None'],
        'other_competitor_brands' => ['3K', 'AC Delco', 'Asahi', 'Black Panther', 'Delkor', 'Duramax', 'Dynex', 'Emtrac', 'Enduranz', 'None'],
        'mcb_competitors' => ['Bosch', 'Dyna', 'GS', 'Yuasa', 'Others', 'None'],
    ],
    'working_days' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
    'operating_hours' => ['12:00 AM', '1:00 AM', '2:00 AM', '3:00 AM', '4:00 AM', '5:00 AM', '6:00 AM', '7:00 AM', '8:00 AM', '9:00 AM', '10:00 AM', '11:00 AM', '12:00 NN', '2:00 PM', '3:00 PM', '4:00 PM', '5:00 PM', '6:00 PM', '7:00 PM', '8:00 PM', '9:00 PM', '10:00 PM', '11:00 PM', '12:00 PM'],
    'delivery_methods' => ['resq_hub' => 'ResQ Hub', 'own_delivery' => 'Own Delivery'],
    'ulab' => ['GRC' => 'GRC', 'Consolidator' => 'Consolidator'],
];
