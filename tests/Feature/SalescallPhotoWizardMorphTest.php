<?php

test('sales call photo wizard survives a Livewire-like parent morph', function () {
    $script = base_path('tests/Browser/salescall-photo-wizard-morph.mjs');
    expect(is_file($script))->toBeTrue();

    $hasPlaywright = false;
    foreach ([base_path('node_modules/playwright'), '/tmp/node_modules/playwright'] as $candidate) {
        if (is_dir($candidate)) {
            $hasPlaywright = true;
            break;
        }
    }

    if (! $hasPlaywright) {
        $this->markTestSkipped('playwright is not installed locally for the WebKit morph regression.');
    }

    $process = proc_open(
        ['node', $script],
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        base_path()
    );

    expect(is_resource($process))->toBeTrue();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    expect($exitCode)->toBe(0, trim($stdout."\n".$stderr));
    expect($stdout)->toContain('PASS');
});
