<?php

namespace App\Filament\Pages;

use App\Models\Customer;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CustomerPage extends Page
{
    protected string $view = 'filament.pages.customer-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;
    protected static ?string $navigationLabel = 'Customers';
    protected static ?string $title = '';
    protected static ?int $navigationSort = 450;

    // public function getViewData(): array
    // {
    //     $user = Auth::user();
    //     $roles = $user->getRoleNames()->toArray();

    //     if (in_array('rsm_approver', $roles)) {
    //         $customers = Customer::where('is_active', true)->orderBy('name')->get();
    //     } elseif (array_intersect(['rsm', 'drm_approver'], $roles)) {
    //         $drmIds = User::where('rsm_id', $user->id)->pluck('id');
    //         $customerIds = DB::table('customer_user')->whereIn('user_id', $drmIds)->pluck('customer_id');
    //         $customers = Customer::whereIn('id', $customerIds)->where('is_active', true)->orderBy('name')->get();
    //     } else {
    //         // DRM
    //         $customerIds = DB::table('customer_user')->where('user_id', $user->id)->pluck('customer_id');
    //         $customers = Customer::whereIn('id', $customerIds)->where('is_active', true)->orderBy('name')->get();
    //     }

    //     return ['customers' => $customers];
    // }

    protected function getViewData(): array
    {
        $customers = Customer::where('is_active', true)->orderBy('name')->get();

        return ['customers' => $customers];
    }
}
