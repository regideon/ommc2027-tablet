<?php

use Illuminate\Support\Facades\Validator;

test('conversion program contract exposes only the canonical values', function () {
    $options = config('customer_trade_form.conversion_programs');

    expect($options)->toBe([
        'For Conversion' => 'For Conversion',
        'Increase Share of Wallet' => 'Increase Share of Wallet',
        'Head On' => 'Head On',
    ]);

    expect(Validator::make(['conversion_program' => null], [
        'conversion_program' => ['nullable', 'in:'.implode(',', [...array_keys($options), ''])],
    ])->passes())->toBeTrue();

    expect(Validator::make(['conversion_program' => ''], [
        'conversion_program' => ['nullable', 'in:'.implode(',', [...array_keys($options), ''])],
    ])->passes())->toBeTrue();

    expect(Validator::make(['conversion_program' => 'legacy free text'], [
        'conversion_program' => ['nullable', 'in:'.implode(',', [...array_keys($options), ''])],
    ])->fails())->toBeTrue();
});
