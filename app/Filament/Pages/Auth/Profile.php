<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * The standard account profile plus the rep's itinerary base location. The
 * tablet is authoritative for its own base once the rep edits it here: the
 * four coordinates are saved locally and flagged pending until SyncService
 * pushes them to the portal (see SyncService::push()).
 */
class Profile extends EditProfile
{
    public function form(Schema $schema): Schema
    {
        $schema = parent::form($schema);

        return $schema->components([
            ...$schema->getComponents(),
            Section::make('Base Location')
                ->description('Your itinerary route start and end points. Drag a marker, use your current location, or edit the coordinates directly.')
                ->schema([
                    View::make('filament.pages.partials.base-location-picker')
                        ->columnSpanFull(),
                    TextInput::make('base_start_latitude')
                        ->label('Base start latitude')
                        ->numeric()
                        ->minValue(-90)
                        ->maxValue(90)
                        ->extraInputAttributes(['data-base-coordinate' => 'base_start_latitude', 'step' => 'any']),
                    TextInput::make('base_start_longitude')
                        ->label('Base start longitude')
                        ->numeric()
                        ->minValue(-180)
                        ->maxValue(180)
                        ->extraInputAttributes(['data-base-coordinate' => 'base_start_longitude', 'step' => 'any']),
                    TextInput::make('base_end_latitude')
                        ->label('Base end latitude')
                        ->numeric()
                        ->minValue(-90)
                        ->maxValue(90)
                        ->extraInputAttributes(['data-base-coordinate' => 'base_end_latitude', 'step' => 'any']),
                    TextInput::make('base_end_longitude')
                        ->label('Base end longitude')
                        ->numeric()
                        ->minValue(-180)
                        ->maxValue(180)
                        ->extraInputAttributes(['data-base-coordinate' => 'base_end_longitude', 'step' => 'any']),
                ])
                ->columns(2),
        ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $baseKeys = ['base_start_latitude', 'base_start_longitude', 'base_end_latitude', 'base_end_longitude'];

        $baseChanged = collect($baseKeys)->contains(
            fn (string $key): bool => (string) ($data[$key] ?? '') !== (string) $record->getAttribute($key)
        );

        $record = parent::handleRecordUpdate($record, $data);

        if ($baseChanged) {
            $record->forceFill(['base_location_pending' => true])->save();
        }

        return $record;
    }
}
