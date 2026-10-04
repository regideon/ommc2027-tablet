<?php

use App\Filament\Pages\ExpensePage;
use App\Filament\Pages\SalescallPage;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\Salescall;
use App\Models\User;
use App\Services\ExpenseReadService;
use App\Services\LocalExpenseCreationService;
use App\Services\SyncService;
use App\Support\ExpensePaymentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function omExpenseReadCreateExpense(Salescall $salescall, Customer $customer, ExpenseType $expenseType, User $user, array $attributes = []): Expense
{
    return Expense::create(array_merge([
        'local_uuid' => (string) str()->uuid(),
        'salescall_id' => $salescall->id,
        'customer_id' => $customer->id,
        'expense_type_id' => $expenseType->id,
        'created_by' => $user->id,
        'amount' => '125.0000',
        'date_filed' => '2026-10-01',
        'payment_type' => 'Cash',
        'payment_remarks' => 'Paid in cash',
        'form_data' => ['number_of_people' => 3, 'names_included' => ['Ana', 'Ben']],
        'sync_status' => 'pending',
    ], $attributes));
}

function omExpenseReadCreateSalescall(Customer $customer, User $createdBy, ?int $serverId = null): Salescall
{
    return Salescall::create([
        'itinerary_id' => 1,
        'customer_id' => $customer->id,
        'created_by' => $createdBy->id,
        'local_uuid' => (string) str()->uuid(),
        'server_id' => $serverId,
        'sync_status' => $serverId ? 'synced' : 'pending',
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create(['api_token' => 'expense-read-test-token']);
    $this->actingAs($this->user);
    $this->customer = Customer::create(['name' => 'Expense Test Customer']);
    $this->expenseType = ExpenseType::create([
        'code' => 'expense-read-test',
        'label' => 'Test Lodging',
        'is_enabled' => true,
    ]);
});

test('expense reads remain scoped to the selected sales call and retain local V2 fields', function () {
    $firstCall = omExpenseReadCreateSalescall($this->customer, $this->user);
    $secondCall = omExpenseReadCreateSalescall($this->customer, $this->user);
    $firstExpense = omExpenseReadCreateExpense($firstCall, $this->customer, $this->expenseType, $this->user);
    omExpenseReadCreateExpense($secondCall, $this->customer, $this->expenseType, $this->user);

    Livewire::test(SalescallPage::class)
        ->call('loadExpenses', $firstCall->id)
        ->assertSee('backToList()')
        ->assertSee('tab === \'expenses\'', false)
        ->assertSet('callExpenses.0.local_uuid', $firstExpense->local_uuid)
        ->assertSet('callExpenses.0.sync_status', 'pending')
        ->assertSet('callExpenses.0.customer_name', $this->customer->name)
        ->assertSet('callExpenses.0.payment_remarks', 'Paid in cash')
        ->assertSet('callExpenses.0.form_data.number_of_people', 3)
        ->call('loadExpenses', $secondCall->id)
        ->assertSet('callExpenses.0.salescall_id', $secondCall->id)
        ->assertCount('callExpenses', 1);
});

test('Expense read aliases historical Petty Cash and keeps other legacy values raw', function () {
    $salescall = omExpenseReadCreateSalescall($this->customer, $this->user);
    $legacy = omExpenseReadCreateExpense($salescall, $this->customer, $this->expenseType, $this->user, [
        'payment_type' => 'Petty Cash',
    ]);
    omExpenseReadCreateExpense($salescall, $this->customer, $this->expenseType, $this->user, [
        'payment_type' => 'Other Credit Card',
    ]);

    expect(app(ExpenseReadService::class)->forSalescall($salescall->id)->pluck('payment_type')->sort()->values()->all())
        ->toBe(['Other Credit Card', 'Petty Cash Voucher (PCV)'])
        ->and($legacy->fresh()->payment_type)->toBe('Petty Cash')
        ->and(ExpensePaymentType::newEntryValues())->toBe([
            'Cash', 'SBC Credit Card', 'Other Payment Type', 'Fleet Card',
            'Petty Cash Voucher (PCV)', 'Revolving Fund', 'Cash Advance',
        ]);
});

test('new Expense form omits Payment Remarks and requests two-decimal Amount input', function () {
    $view = file_get_contents(resource_path('views/filament/pages/expense-create-page.blade.php'));

    expect($view)->toContain('step="0.01"')
        ->and($view)->toContain('@foreach ($paymentTypes as $paymentType)')
        ->and($view)->not->toContain('payment_remarks')
        ->and($view)->not->toContain('Payment Remarks');
});

test('new Expense creation accepts positive amounts with at most two decimals and stores no remarks', function () {
    $salescall = omExpenseReadCreateSalescall($this->customer, $this->user);
    $type = ExpenseType::query()->where('code', 'communication_expenses')->firstOrFail();
    $service = app(LocalExpenseCreationService::class);
    $base = [
        'expense_type_code' => $type->code,
        'date_filed' => '2026-10-04',
        'payment_type' => 'Other Payment Type',
        'invoice_number' => 'INV-1',
        'establishment' => 'Shop',
        'location' => 'Manila',
        'purpose' => 'Business expense',
        'tin' => 'TIN-1',
        'payment_remarks' => 'must be ignored on new entry',
    ];

    foreach (['1', '1.5', '1.50', '100', '100.01'] as $amount) {
        $expense = $service->createFromSalescall($salescall, $this->user, $base + ['amount' => $amount]);
        expect($expense->payment_remarks)->toBeNull();
    }

    foreach (['0', '0.00', '-1', 'abc', '1.001', '100.999'] as $amount) {
        expect(fn () => $service->createFromSalescall($salescall, $this->user, $base + ['amount' => $amount]))
            ->toThrow(ValidationException::class);
    }
});

test('pending historical Petty Cash syncs as PCV while retaining its old remarks and raw local value', function () {
    config(['sync.server_url' => 'https://portal.example.test']);
    $salescall = omExpenseReadCreateSalescall($this->customer, $this->user, 901);
    $expense = omExpenseReadCreateExpense($salescall, $this->customer, $this->expenseType, $this->user, [
        'payment_type' => 'Petty Cash',
        'payment_remarks' => 'Legacy pending remarks',
    ]);
    Http::fake(['portal.example.test/api/sync/push/expense' => Http::response(['server_id' => 701])]);

    app(SyncService::class)->push();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://portal.example.test/api/sync/push/expense'
        && $request['payment_type'] === 'Petty Cash Voucher (PCV)'
        && $request['payment_remarks'] === 'Legacy pending remarks');
    expect($expense->fresh()->payment_type)->toBe('Petty Cash')
        ->and($expense->fresh()->payment_remarks)->toBe('Legacy pending remarks');
});

test('server expense replaces its synced local copy by server identity', function () {
    config(['sync.server_url' => 'https://portal.example.test']);
    Http::fake([
        'portal.example.test/api/sync/salescalls/900/expenses' => Http::response([
            'salescall_id' => 900,
            'expenses' => [[
                'id' => 700,
                'server_id' => 700,
                'local_uuid' => 'synced-expense-uuid',
                'salescall_id' => 900,
                'expense_type' => 'Test Lodging',
                'customer_name' => 'Expense Test Customer',
                'amount' => '125.0000',
                'date_filed' => '2026-10-01',
                'payment_remarks' => 'Paid in cash',
                'form_data' => ['number_of_people' => 3, 'names_included' => ['Ana', 'Ben']],
                'approved' => null,
                'attachments' => [[
                    'id' => 880,
                    'server_id' => 880,
                    'original_name' => 'receipt.jpg',
                    'mime_type' => 'image/jpeg',
                    'byte_size' => 1024,
                    'url' => 'https://files.example.test/receipt.jpg?signature=test',
                ]],
            ]],
        ]),
    ]);

    $salescall = omExpenseReadCreateSalescall($this->customer, $this->user, 900);
    omExpenseReadCreateExpense($salescall, $this->customer, $this->expenseType, $this->user, [
        'local_uuid' => 'synced-expense-uuid',
        'server_id' => 700,
        'sync_status' => 'synced',
    ]);

    Livewire::test(SalescallPage::class)
        ->call('loadExpenses', $salescall->id)
        ->assertCount('callExpenses', 1)
        ->assertSet('callExpenses.0.server_id', 700)
        ->assertSet('callExpenses.0.customer_name', 'Expense Test Customer')
        ->assertSet('callExpenses.0.payment_remarks', 'Paid in cash')
        ->assertSet('callExpenses.0.attachments.0.url', 'https://files.example.test/receipt.jpg?signature=test');
});

test('contextual expense reads reject Sales Calls outside the viewers visibility', function () {
    $other = User::factory()->create();
    $otherCall = omExpenseReadCreateSalescall($this->customer, $other);

    expect(fn () => app(ExpenseReadService::class)->forSalescall($otherCall->id))
        ->toThrow(HttpException::class);
});

test('Expense navigation opens a shared global list scoped to the RSM team', function () {
    config(['sync.server_url' => 'https://portal.example.test']);
    Http::fake([
        'portal.example.test/api/sync/expenses' => Http::response([
            'expenses' => [[
                'id' => 700,
                'server_id' => 700,
                'local_uuid' => 'global-synced-expense',
                'salescall_id' => 900,
                'expense_type' => 'Test Lodging',
                'customer_name' => 'Expense Test Customer',
                'amount' => '125.0000',
                'date_filed' => '2026-10-01',
                'created_at' => '2026-10-02T10:00:00Z',
                'payment_remarks' => 'Paid in cash',
                'form_data' => ['number_of_people' => 3],
                'approved' => null,
                'attachments' => [],
            ], [
                'id' => 701,
                'server_id' => 701,
                'local_uuid' => 'global-server-expense',
                'salescall_id' => 901,
                'expense_type' => 'Test Lodging',
                'customer_name' => 'Expense Test Customer',
                'amount' => '200.0000',
                'date_filed' => '2026-10-02',
                'created_at' => '2026-10-03T10:00:00Z',
                'payment_remarks' => 'Server record',
                'form_data' => [],
                'approved' => null,
                'attachments' => [],
            ]],
        ]),
    ]);

    $this->user->assignRole(Role::create(['name' => 'rsm', 'guard_name' => 'web']));
    $rep = User::factory()->create(['rsm_id' => $this->user->id]);
    $other = User::factory()->create();
    $teamCall = omExpenseReadCreateSalescall($this->customer, $rep);
    $otherCall = omExpenseReadCreateSalescall($this->customer, $other);
    $pending = omExpenseReadCreateExpense($teamCall, $this->customer, $this->expenseType, $rep);
    omExpenseReadCreateExpense($teamCall, $this->customer, $this->expenseType, $rep, [
        'local_uuid' => 'global-synced-expense',
        'server_id' => 700,
        'sync_status' => 'synced',
    ]);
    omExpenseReadCreateExpense($otherCall, $this->customer, $this->expenseType, $other);

    expect(ExpensePage::shouldRegisterNavigation())->toBeTrue();

    Livewire::test(ExpensePage::class)
        ->assertSee('Expenses filed across your accessible Sales Calls')
        ->assertSee('Select an Expense to view its details.')
        ->assertSee('Record Information')
        ->assertSee('x-show="!isWide"', false)
        ->assertSee('lg:grid-cols-[minmax(17rem,0.38fr)_minmax(0,0.62fr)]', false)
        ->assertSee('max-h-[calc(100dvh-11rem)] overflow-y-auto')
        ->assertSee('selectedExpenseKey === expenseKey(expense)', false)
        ->assertSee('this.expenseKey(this.expenseItems[0])', false)
        ->assertCount('expenses', 3)
        ->assertSet('expenses.0.local_uuid', $pending->local_uuid)
        ->assertSet('expenses.1.server_id', 701)
        ->assertSet('expenses.2.server_id', 700);
});

test('compiled Expense reader styles provide the wide master detail grid', function () {
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
    $themePath = public_path('build/'.$manifest['resources/css/filament/saleshub/theme.css']['file']);
    $themeCss = file_get_contents($themePath);

    expect($themeCss)
        ->toContain('.lg\\:grid{display:grid}')
        ->toContain('.lg\\:grid-cols-\\[minmax\\(17rem\\,0\\.38fr\\)_minmax\\(0\\,0\\.62fr\\)\\]{grid-template-columns:minmax(17rem,.38fr) minmax(0,.62fr)}')
        ->toContain('max-height:calc(100dvh - 11rem)')
        ->toContain('overflow-y:auto');
});

test('global Expense route renders its page lifecycle and populated reader', function () {
    config(['app.env' => 'local', 'sync.server_url' => 'https://portal.example.test']);
    Http::fake([
        'portal.example.test/api/sync/expenses' => Http::response([
            'expenses' => [[
                'id' => 702,
                'server_id' => 702,
                'local_uuid' => 'http-page-expense',
                'expense_type' => 'HTTP Page Lodging',
                'customer_name' => 'HTTP Page Customer',
                'created_at' => '2026-10-04T10:00:00Z',
                'approved' => null,
                'attachments' => [],
            ]],
        ]),
    ]);

    $response = $this->get('/app/expense-page')->assertOk()
        ->assertSee('Expenses filed across your accessible Sales Calls')
        ->assertSee('No Expenses found.')
        ->assertSee('Select an Expense to view its details.')
        ->assertSee('lg:grid-cols-[minmax(17rem,0.38fr)_minmax(0,0.62fr)]', false)
        ->assertSee('HTTP Page Lodging');

    expect($response->getContent())
        ->toContain('window.ommcExpenseReader = window.ommcExpenseReader ||')
        ->toContain('this.expenseKey(this.expenseItems[0])')
        ->not->toContain('\\`');
});

test('global Expense route renders an explicit empty state', function () {
    config(['app.env' => 'local', 'sync.server_url' => 'https://portal.example.test']);
    Http::fake([
        'portal.example.test/api/sync/expenses' => Http::response(['expenses' => []]),
    ]);

    $this->get('/app/expense-page')->assertOk()
        ->assertSee('<h3 class="text-base font-bold text-[#191c1e]">Expenses</h3>', false)
        ->assertSee('No Expenses found.')
        ->assertSee('Select an Expense to view its details.');
});

test('global Expense route retains its shell and shows an error when the server read fails', function () {
    config(['app.env' => 'local', 'sync.server_url' => 'https://portal.example.test']);
    Http::fake([
        'portal.example.test/api/sync/expenses' => Http::response([], 503),
    ]);

    $this->get('/app/expense-page')->assertOk()
        ->assertSee('role="alert"', false)
        ->assertSee('Some Expenses could not be loaded. Refresh to try again.')
        ->assertSee('Expenses filed across your accessible Sales Calls')
        ->assertSee('No Expenses found.')
        ->assertSee('Select an Expense to view its details.');
});
