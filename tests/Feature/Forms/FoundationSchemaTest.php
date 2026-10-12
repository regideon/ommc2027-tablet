<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('every forms table exists', function () {
    $tables = [
        'crr_programs', 'crrs', 'crr_attachments',
        'marketing_programs', 'marketings', 'marketing_volumes', 'marketing_realizations', 'marketing_mechanics',
        'trade_checks', 'trade_check_groups', 'trade_check_items', 'trade_check_answers', 'trade_check_answer_items', 'trade_check_attachments',
        'giveaways', 'giveaway_items', 'giveaway_answers', 'giveaway_answer_items', 'giveaway_signatures',
        'repairs', 'repair_attachments',
        'signage_products', 'signage_types', 'signages', 'signage_attachments',
        'trade_products', 'trade_types', 'trade_requests', 'trade_request_items', 'trade_request_attachments',
        'training_topics', 'training_positions', 'trainings', 'training_participants', 'training_attachments',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Missing table: {$table}");
    }
});

test('master tables cache server rows by server id', function () {
    $masters = [
        'crr_programs', 'marketing_programs', 'signage_products', 'signage_types',
        'trade_products', 'trade_types', 'training_topics', 'training_positions',
    ];

    foreach ($masters as $table) {
        expect(Schema::hasColumns($table, ['id', 'server_id', 'name']))->toBeTrue("Bad master: {$table}");
    }
});

test('submission headers carry the offline sync envelope', function () {
    $headers = [
        'crrs', 'marketings', 'trade_checks', 'giveaways', 'repairs',
        'signages', 'trade_requests', 'trainings',
    ];

    foreach ($headers as $table) {
        expect(Schema::hasColumns($table, [
            'local_uuid', 'server_id', 'submitted_by', 'salescall_id',
            'sync_status', 'sync_attempts', 'sync_error', 'synced_at',
        ]))->toBeTrue("Bad header: {$table}");
    }
});

test('attachment tables store local files and a server id', function () {
    $attachments = [
        'crr_attachments', 'repair_attachments', 'signage_attachments',
        'trade_request_attachments', 'training_attachments',
        'trade_check_attachments', 'giveaway_signatures',
    ];

    foreach ($attachments as $table) {
        expect(Schema::hasColumns($table, [
            'local_uuid', 'server_id', 'local_path', 'original_name',
            'mime_type', 'extension', 'byte_size', 'sync_status', 'synced_at',
        ]))->toBeTrue("Bad attachment table: {$table}");
    }
});

test('status columns exist on the headers that use them', function () {
    expect(Schema::hasColumns('crrs', ['rsm_status', 'sps_status', 'spm_status']))->toBeTrue()
        ->and(Schema::hasColumns('marketings', ['sps_status', 'rac_status', 'rac_return_count']))->toBeTrue()
        ->and(Schema::hasColumns('trade_check_answers', ['rsm_status', 'tdh_status']))->toBeTrue()
        ->and(Schema::hasColumns('giveaway_answers', ['rsm_status']))->toBeTrue()
        ->and(Schema::hasColumns('repairs', ['rsm_status', 'sps_status', 'spm_status']))->toBeTrue()
        ->and(Schema::hasColumns('signages', ['rsm_status', 'tdh_status', 'tds_status']))->toBeTrue()
        ->and(Schema::hasColumns('trade_requests', ['rsm_status', 'tdh_status', 'tds_status']))->toBeTrue()
        ->and(Schema::hasColumns('trainings', ['rsm_status', 'tdh_status', 'tds_status']))->toBeTrue();
});

test('the portal-only tables are not mirrored locally', function () {
    expect(Schema::hasTable('uploaded_files'))->toBeFalse()
        ->and(Schema::hasTable('form_approvals'))->toBeFalse()
        ->and(Schema::hasTable('forms'))->toBeFalse();
});
