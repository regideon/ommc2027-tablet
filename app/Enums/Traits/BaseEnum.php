<?php

namespace App\Enums\Traits;

use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;

trait BaseEnum
{
    /**
     * Meta properties.
     */
    abstract public static function metaProperties(): array;

    /**
     * Return the enum's value when it's $invoked().
     */
    public function __invoke(): mixed
    {
        return $this->value;
    }

    /**
     * Return the enum's value or name when it's called ::STATICALLY().
     */
    public static function __callStatic(mixed $name, mixed $args): mixed
    {
        $cases = static::cases();

        foreach ($cases as $case) {
            if ($case->name === $name) {
                return $case->value;
            }
        }

        throw new BackedEnumCaseNotFoundException(static::class, $name);
    }

    /**
     * Render meta property.
     */
    public function meta(?string $property = null): mixed
    {
        $meta = static::metaProperties();

        if (! $property) {
            return $meta[$this->value] ?? [];
        }

        return $meta[$this->value][$property] ?? null;
    }

    /**
     * Render select
     */
    public static function renderSelect(string $orderBy = 'label', array $cases = []): array
    {
        $arr = [];

        foreach ($cases ?: static::cases() as $case) {
            $arr[] = [
                'label' => $case->meta('title') ?? $case->name,
                'value' => $case->value,
            ];
        }

        array_multisort(array_column($arr, $orderBy), SORT_ASC, $arr);

        return $arr;
    }

    /**
     * Get all values
     */
    public static function values(): array
    {
        return array_map(fn ($item) => $item->value, static::cases());
    }

    /**
     * Get a random value
     */
    public static function randomValue(): mixed
    {
        $values = static::values();
        $index = rand(1, count($values)) - 1;

        return $values[$index];
    }

    /**
     * Render pill
     */
    public function pill(): array
    {
        return [
            'id' => $this->value,
            'text' => $this->meta('title') ?? $this->name,
            'color' => $this->meta('color') ?? null,
        ];
    }

    /**
     * List all values for import option.
     */
    public static function getImportOptionList(): array
    {
        $options = [];

        foreach (static::cases() as $case) {
            $options[] = "{$case->value} - {$case->name}";
        }

        return $options;
    }

    /**
     * Get filtered meta properties.
     */
    public static function getFilteredMetaProperties(array $cases = []): array
    {
        $metaProperties = static::metaProperties();

        if (empty($cases)) {
            return $metaProperties;
        }

        return array_filter($metaProperties, fn ($key) => in_array($key, $cases), ARRAY_FILTER_USE_KEY);
    }
}
