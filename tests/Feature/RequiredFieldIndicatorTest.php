<?php

function requiredMarkerView(string $view): string
{
    return (string) file_get_contents(resource_path('views/filament/pages/'.$view));
}

test('required form labels use a red marker', function (): void {
    $expectations = [
        'crr-form-page.blade.php' => [
            'CRR Program <span class="text-red-500">*</span>',
            'Amount <span class="text-red-500">*</span>',
        ],
        'repair-form-page.blade.php' => [
            'Plate Number <span class="text-red-500">*</span>',
            'Amount <span class="text-red-500">*</span>',
            'Details <span class="text-red-500">*</span>',
            'Attachments <span class="text-red-500">*</span>',
        ],
        'signage-form-page.blade.php' => [
            'Signage Name <span class="text-red-500">*</span>',
            'Category <span class="text-red-500">*</span>',
            'Product <span class="text-red-500">*</span>',
            'Type <span class="text-red-500">*</span>',
        ],
        'trade-request-form-page.blade.php' => [
            'Product <span class="text-red-500">*</span>',
            'Trade Type <span class="text-red-500">*</span>',
            'Quantity <span class="text-red-500">*</span>',
            'Items <span class="text-red-500">*</span>',
        ],
        'training-form-page.blade.php' => [
            'Topic <span class="text-red-500">*</span>',
            'Other Topic <span class="text-red-500">*</span>',
            'Preferred At <span class="text-red-500">*</span>',
            'Name <span class="text-red-500">*</span>',
            'Position <span class="text-red-500">*</span>',
            'Participants <span class="text-red-500">*</span>',
        ],
        'marketing-form-page.blade.php' => [
            'Name <span class="text-red-500">*</span>',
            'Program <span class="text-red-500">*</span>',
            'Region Type <span class="text-red-500">*</span>',
            'Duration Start <span class="text-red-500">*</span>',
            'Duration End <span class="text-red-500">*</span>',
            'Objective <span class="text-red-500">*</span>',
            'Mechanics Description <span class="text-red-500">*</span>',
            'Before Program <span class="text-red-500">*</span>',
            'During Program <span class="text-red-500">*</span>',
            'Stage <span class="text-red-500">*</span>',
            'Volume <span class="text-red-500">*</span>',
            'Start <span class="text-red-500">*</span>',
            'End <span class="text-red-500">*</span>',
            'Period <span class="text-red-500">*</span>',
            'Unit Price <span class="text-red-500">*</span>',
            'Sub Total <span class="text-red-500">*</span>',
        ],
        'giveaway-form-page.blade.php' => [
            'Name <span class="text-red-500">*</span>',
            'Category <span class="text-red-500">*</span>',
            'Item Name <span class="text-red-500">*</span>',
            'Signature <span class="text-red-500">*</span>',
        ],
        'expense-create-page.blade.php' => [
            'Amount <span class="text-red-500">*</span>',
            'Date Filed <span class="text-red-500">*</span>',
            'Payment Type <span class="text-red-500">*</span>',
            'Invoice Number <span class="text-red-500">*</span>',
            'TIN <span class="text-red-500">*</span>',
            'Establishment <span class="text-red-500">*</span>',
            'Location <span class="text-red-500">*</span>',
            'Purpose <span class="text-red-500">*</span>',
        ],
        'salescall-page.blade.php' => [
            'Scheduled At <span class="text-red-500">*</span>',
            'Customer <span class="text-red-500">*</span>',
            'Note <span class="text-red-500">*</span>',
            'Category <span class="text-red-500">*</span>',
            'Sub Category <span class="text-red-500">*</span>',
        ],
    ];

    foreach ($expectations as $view => $needles) {
        $contents = requiredMarkerView($view);

        foreach ($needles as $needle) {
            expect($contents)->toContain($needle);
        }
    }
});

test('form labels do not use a plain non-red asterisk', function (): void {
    $views = [
        'crr-form-page.blade.php',
        'expense-create-page.blade.php',
        'giveaway-form-page.blade.php',
        'marketing-form-page.blade.php',
        'repair-form-page.blade.php',
        'salescall-page.blade.php',
        'signage-form-page.blade.php',
        'trade-request-form-page.blade.php',
        'training-form-page.blade.php',
    ];

    foreach ($views as $view) {
        expect(requiredMarkerView($view))->not->toMatch('/\*\s*<\/label>/');
    }
});
