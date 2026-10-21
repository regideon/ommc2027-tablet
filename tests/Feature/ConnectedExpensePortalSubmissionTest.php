<?php

use App\Filament\Pages\ExpenseCreatePage;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\Itinerary;
use App\Models\Salescall;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('Expense submission reaches an isolated Portal service, is acknowledged, idempotent, and visible', function () {
    $portalUrl = rtrim((string) getenv('OMMC_EXPENSE_PORTAL_URL'), '/');
    if ($portalUrl === '') {
        $this->markTestSkipped('Set OMMC_EXPENSE_PORTAL_URL to an isolated local Portal test service.');
    }

    $portalHost = parse_url($portalUrl, PHP_URL_HOST);
    expect($portalHost)->toBeIn(['127.0.0.1', 'localhost', '::1']);

    $token = 'local-expense-integration-token';
    config(['sync.server_url' => $portalUrl]);
    $user = User::factory()->create(['api_token' => $token]);
    $this->actingAs($user);

    $customer = Customer::create([
        'local_uuid' => (string) Str::uuid(),
        'server_id' => 10,
        'sync_status' => 'synced',
        'name' => 'Local Integration Customer',
    ]);
    $itinerary = Itinerary::create([
        'local_uuid' => (string) Str::uuid(),
        'server_id' => 20,
        'sync_status' => 'synced',
        'created_by' => $user->id,
    ]);
    $salescall = Salescall::create([
        'itinerary_id' => $itinerary->id,
        'customer_id' => $customer->id,
        'created_by' => $user->id,
        'local_uuid' => (string) Str::uuid(),
        'server_id' => 90,
        'sync_status' => 'synced',
    ]);
    $lodging = ExpenseType::query()->where('code', 'lodging')->firstOrFail();

    Livewire::withQueryParams([
        'salescall' => $salescall->id,
        'type' => $lodging->code,
    ])->test(ExpenseCreatePage::class)
        ->call('saveExpense', [
            'amount' => '250.00',
            'date_filed' => '2026-10-21',
            'payment_type' => 'Cash',
            'invoice_number' => 'INV-CONNECTED-1',
            'establishment' => 'Hotel',
            'location' => 'Manila',
            'purpose' => 'Local connected sync verification',
        ])
        ->assertReturned(fn (array $result): bool => $result['ok'] === true)
        ->assertNotified('Expense saved and synchronized.');

    $expense = Expense::query()->sole();
    expect($expense->sync_status)->toBe('synced')
        ->and($expense->server_id)->toBeGreaterThan(0);

    $retryPayload = [
        'salescall_server_id' => 90,
        'local_uuid' => $expense->local_uuid,
        'expense_type_code' => $lodging->code,
        'amount' => number_format((float) $expense->amount, 2, '.', ''),
        'date_filed' => $expense->date_filed->format('Y-m-d'),
        'payment_type' => $expense->payment_type,
        'payment_remarks' => $expense->payment_remarks,
        'invoice_number' => $expense->invoice_number,
        'with_invoice' => $expense->with_invoice,
        'establishment' => $expense->establishment,
        'location' => $expense->location,
        'purpose' => $expense->purpose,
        'tin' => $expense->tin,
        'latitude' => $expense->latitude,
        'longitude' => $expense->longitude,
        'form_data' => $expense->form_data,
        'form_schema_version' => $expense->form_schema_version,
        'attachment_count' => $expense->attachments()->count(),
    ];

    $duplicateAcknowledgment = Http::withToken($token)
        ->acceptJson()
        ->post($portalUrl.'/api/expenses/push', $retryPayload)
        ->throw()
        ->json('server_id');

    $visibleExpenses = Http::withToken($token)
        ->acceptJson()
        ->get($portalUrl.'/api/expenses/list')
        ->throw()
        ->json('expenses');

    expect($duplicateAcknowledgment)->toBe($expense->server_id)
        ->and(Expense::query()->count())->toBe(1)
        ->and(collect($visibleExpenses)->firstWhere('local_uuid', $expense->local_uuid))
        ->toMatchArray([
            'id' => $expense->server_id,
            'salescall_id' => 90,
            'customer_id' => 10,
            'created_by' => 1,
        ]);

    expect(app(SyncService::class)->hasPendingChanges())->toBeFalse();
});
