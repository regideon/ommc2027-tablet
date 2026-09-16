<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerCategoryEvent;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class CustomerCategoryTimelineService
{
    private const SOURCES = ['portal', 'tablet', 'sync', 'recovery_baseline', 'correction'];

    public function record(Customer $customer, array $payload): CustomerCategoryEvent
    {
        $profileType = (string) ($payload['profile_type'] ?? '');
        $stream = (string) ($payload['stream'] ?? '');
        $source = (string) ($payload['source'] ?? 'tablet');
        $eventKey = (string) ($payload['event_key'] ?? '');
        $effectiveAt = $this->normalizeTimestamp($payload['effective_at'] ?? null);
        $category = (string) ($payload['category'] ?? '');

        $this->validateContract($profileType, $stream, $category, $source, $eventKey, $effectiveAt);

        $existing = CustomerCategoryEvent::where('event_key', $eventKey)->first();
        if ($existing) {
            if ($this->canonical($existing) !== $this->canonicalPayload($customer, $payload, $effectiveAt)) {
                throw ValidationException::withMessages(['event_key' => 'Event key is already used for different category data.']);
            }
            return $existing;
        }

        $supersedesId = null;
        $supersedesKey = $payload['supersedes_event_key'] ?? null;
        if (filled($supersedesKey)) {
            $superseded = CustomerCategoryEvent::where('event_key', $supersedesKey)->first();
            if (! $superseded || (int) $superseded->customer_id !== (int) $customer->id || $superseded->profile_type !== $profileType || $superseded->stream !== $stream) {
                throw ValidationException::withMessages(['supersedes_event_key' => 'Superseded event does not belong to this Customer stream.']);
            }
            if (CustomerCategoryEvent::where('supersedes_event_id', $superseded->id)->exists()) {
                throw ValidationException::withMessages(['supersedes_event_key' => 'Only the latest event may be corrected.']);
            }
            $supersedesId = $superseded->id;
        }

        $event = CustomerCategoryEvent::create([
            'customer_id' => $customer->id,
            'profile_type' => $profileType,
            'stream' => $stream,
            'category' => $category,
            'effective_at' => $effectiveAt,
            'event_key' => $eventKey,
            'source' => $source,
            'supersedes_event_id' => $supersedesId,
            'supersedes_event_key' => $supersedesKey,
        ]);

        $customer->update(['sync_status' => 'pending', 'sync_error' => null]);

        return $event;
    }

    public function monthlyCategories(Customer $customer, string $profileType, string $stream, int $year): array
    {
        $events = $this->eventsForCustomers([$customer->id], $profileType, $stream, $year)->get($customer->id, collect());
        $result = [];
        $state = null;
        foreach (range(1, 12) as $month) {
            $cutoff = CarbonImmutable::create($year, $month, 1, 0, 0, 0, config('app.timezone'))->endOfMonth();
            foreach ($events as $event) {
                if ($event->effective_at->lte($cutoff)) $state = $event->category;
            }
            $result[$month] = $state;
        }
        return $result;
    }

    public function eventsForCustomers(array $customerIds, string $profileType, string $stream, int $year)
    {
        $end = CarbonImmutable::create($year, 12, 31, 23, 59, 59, config('app.timezone'));
        return CustomerCategoryEvent::whereIn('customer_id', $customerIds)
            ->where('profile_type', $profileType)->where('stream', $stream)
            ->where('effective_at', '<=', $end)
            ->whereNotIn('id', CustomerCategoryEvent::query()->select('supersedes_event_id')->whereNotNull('supersedes_event_id'))
            ->orderBy('effective_at')->orderBy('id')->get()->groupBy('customer_id');
    }

    private function validateContract(string $profileType, string $stream, string $category, string $source, string $eventKey, ?CarbonImmutable $effectiveAt): void
    {
        abort_unless(in_array($profileType, config('customer_trade_form.allowed_profile_types', []), true), 422, 'Invalid category event profile.');
        abort_unless(in_array($stream, config("customer_trade_form.category_streams.{$profileType}.streams", []), true), 422, 'Invalid category event stream.');
        $options = config("customer_trade_form.profile_category_options.{$profileType}.{$stream}");
        $options ??= config("customer_trade_form.profile_category_options.{$profileType}", []);
        abort_unless(in_array($category, $options, true), 422, 'Invalid category event value.');
        abort_unless(in_array($source, self::SOURCES, true), 422, 'Invalid category event source.');
        abort_unless($eventKey !== '' && strlen($eventKey) <= 100, 422, 'A category event key is required.');
        abort_unless($effectiveAt !== null, 422, 'A category event effective timestamp is required.');
    }

    private function normalizeTimestamp(mixed $value): ?CarbonImmutable
    {
        return blank($value) ? null : CarbonImmutable::parse($value)->setTimezone(config('app.timezone'));
    }

    private function canonical(CustomerCategoryEvent $event): array
    {
        return [$event->customer_id, $event->profile_type, $event->stream, $event->category, $event->effective_at?->utc()->toISOString(), $event->source, $event->supersedes_event_key];
    }

    private function canonicalPayload(Customer $customer, array $payload, CarbonImmutable $effectiveAt): array
    {
        return [$customer->id, $payload['profile_type'], $payload['stream'], $payload['category'], $effectiveAt->utc()->toISOString(), $payload['source'] ?? 'tablet', $payload['supersedes_event_key'] ?? null];
    }
}
