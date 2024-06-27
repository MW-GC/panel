<?php

namespace App\Filament\Resources\ServerResource\Pages;

use App\Models\Node;
use App\Models\Objects\Endpoint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use App\Models\Database;
use App\Services\Databases\DatabaseManagementService;
use App\Services\Databases\DatabasePasswordService;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Repeater;
use LogicException;
use App\Filament\Resources\ServerResource;
use App\Http\Controllers\Admin\ServersController;
use App\Services\Servers\RandomWordService;
use App\Services\Servers\SuspensionService;
use App\Services\Servers\TransferServerService;
use Filament\Actions;
use Filament\Forms;
use App\Enums\ContainerStatus;
use App\Enums\ServerState;
use App\Models\Egg;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Services\Servers\ServerDeletionService;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Validator;
use Closure;
use Webbingbrasil\FilamentCopyActions\Forms\Actions\CopyAction;

class EditServer extends EditRecord
{
    public ?Node $node = null;
    public ?Egg $egg = null;
    public array $ports = [];
    public array $eggDefaultPorts = [];

    protected static string $resource = ServerResource::class;

    public function form(Form $form): Form
    {
        return $form
            ->columns([
                'default' => 1,
                'sm' => 2,
                'md' => 2,
                'lg' => 4,
            ])
            ->schema([
                Tabs::make('Tabs')
                    ->persistTabInQueryString()
                    ->columnSpan(6)
                    ->columns([
                        'default' => 2,
                        'sm' => 2,
                        'md' => 4,
                        'lg' => 6,
                    ])
                    ->tabs([
                        Tabs\Tab::make('Information')
                            ->icon('tabler-info-circle')
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->prefixIcon('tabler-server')
                                    ->label('Display Name')
                                    ->suffixAction(Forms\Components\Actions\Action::make('random')
                                        ->icon('tabler-dice-' . random_int(1, 6))
                                        ->action(function (Forms\Set $set, Forms\Get $get) {
                                            $egg = Egg::find($get('egg_id'));
                                            $prefix = $egg ? str($egg->name)->lower()->kebab() . '-' : '';

                                            $word = (new RandomWordService())->word();

                                            $set('name', $prefix . $word);
                                        }))
                                    ->columnSpan([
                                        'default' => 2,
                                        'sm' => 1,
                                        'md' => 2,
                                        'lg' => 3,
                                    ])
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\Select::make('owner_id')
                                    ->prefixIcon('tabler-user')
                                    ->label('Owner')
                                    ->columnSpan([
                                        'default' => 2,
                                        'sm' => 1,
                                        'md' => 2,
                                        'lg' => 2,
                                    ])
                                    ->relationship('user', 'username')
                                    ->searchable()
                                    ->preload()
                                    ->required(),

                                Forms\Components\ToggleButtons::make('condition')
                                    ->label('Server Status')
                                    ->formatStateUsing(fn (Server $server) => $server->condition)
                                    ->options(fn ($state) => collect(array_merge(ContainerStatus::cases(), ServerState::cases()))
                                        ->filter(fn ($condition) => $condition->value === $state)
                                        ->mapWithKeys(fn ($state) => [$state->value => str($state->value)->replace('_', ' ')->ucwords()])
                                    )
                                    ->colors(collect(array_merge(ContainerStatus::cases(), ServerState::cases()))->mapWithKeys(
                                        fn ($status) => [$status->value => $status->color()]
                                    ))
                                    ->icons(collect(array_merge(ContainerStatus::cases(), ServerState::cases()))->mapWithKeys(
                                        fn ($status) => [$status->value => $status->icon()]
                                    ))
                                    ->columnSpan([
                                        'default' => 2,
                                        'sm' => 1,
                                        'md' => 1,
                                        'lg' => 1,
                                    ]),

                                Forms\Components\Textarea::make('description')
                                    ->label('Description')
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('uuid')
                                    ->hintAction(CopyAction::make())
                                    ->columnSpan([
                                        'default' => 2,
                                        'sm' => 1,
                                        'md' => 2,
                                        'lg' => 3,
                                    ])
                                    ->readOnly(),
                                Forms\Components\TextInput::make('uuid_short')
                                    ->label('Short UUID')
                                    ->hintAction(CopyAction::make())
                                    ->columnSpan([
                                        'default' => 2,
                                        'sm' => 1,
                                        'md' => 2,
                                        'lg' => 3,
                                    ])
                                    ->readOnly(),
                                Forms\Components\TextInput::make('external_id')
                                    ->label('External ID')
                                    ->columnSpan([
                                        'default' => 2,
                                        'sm' => 1,
                                        'md' => 2,
                                        'lg' => 3,
                                    ])
                                    ->maxLength(255),
                                Forms\Components\Select::make('node_id')
                                    ->label('Node')
                                    ->relationship('node', 'name')
                                    ->columnSpan([
                                        'default' => 2,
                                        'sm' => 1,
                                        'md' => 2,
                                        'lg' => 3,
                                    ])
                                    ->disabled(),
                            ]),
                        Tabs\Tab::make('env-tab')
                            ->label('Environment')
                            ->icon('tabler-brand-docker')
                            ->schema([
                                Forms\Components\Fieldset::make('Resource Limits')
                                    ->columnSpan([
                                        'default' => 2,
                                        'sm' => 4,
                                        'md' => 4,
                                        'lg' => 6,
                                    ])
                                    ->columns([
                                        'default' => 1,
                                        'sm' => 2,
                                        'md' => 3,
                                        'lg' => 3,
                                    ])
                                    ->schema([
                                        Forms\Components\Grid::make()
                                            ->columns(4)
                                            ->columnSpanFull()
                                            ->schema([
                                                Forms\Components\ToggleButtons::make('unlimited_mem')
                                                    ->label('Memory')->inlineLabel()->inline()
                                                    ->afterStateUpdated(fn (Forms\Set $set) => $set('memory', 0))
                                                    ->formatStateUsing(fn (Forms\Get $get) => $get('memory') == 0)
                                                    ->live()
                                                    ->options([
                                                        true => 'Unlimited',
                                                        false => 'Limited',
                                                    ])
                                                    ->colors([
                                                        true => 'primary',
                                                        false => 'warning',
                                                    ])
                                                    ->columnSpan(2),

                                                Forms\Components\TextInput::make('memory')
                                                    ->dehydratedWhenHidden()
                                                    ->hidden(fn (Forms\Get $get) => $get('unlimited_mem'))
                                                    ->label('Memory Limit')->inlineLabel()
                                                    ->suffix(config('panel.use_binary_prefix') ? 'MiB' : 'MB')
                                                    ->required()
                                                    ->columnSpan(2)
                                                    ->numeric()
                                                    ->minValue(0),
                                            ]),

                                        Forms\Components\Grid::make()
                                            ->columns(4)
                                            ->columnSpanFull()
                                            ->schema([
                                                Forms\Components\ToggleButtons::make('unlimited_disk')
                                                    ->label('Disk Space')->inlineLabel()->inline()
                                                    ->live()
                                                    ->afterStateUpdated(fn (Forms\Set $set) => $set('disk', 0))
                                                    ->formatStateUsing(fn (Forms\Get $get) => $get('disk') == 0)
                                                    ->options([
                                                        true => 'Unlimited',
                                                        false => 'Limited',
                                                    ])
                                                    ->colors([
                                                        true => 'primary',
                                                        false => 'warning',
                                                    ])
                                                    ->columnSpan(2),

                                                Forms\Components\TextInput::make('disk')
                                                    ->dehydratedWhenHidden()
                                                    ->hidden(fn (Forms\Get $get) => $get('unlimited_disk'))
                                                    ->label('Disk Space Limit')->inlineLabel()
                                                    ->suffix(config('panel.use_binary_prefix') ? 'MiB' : 'MB')
                                                    ->required()
                                                    ->columnSpan(2)
                                                    ->numeric()
                                                    ->minValue(0),
                                            ]),

                                        Forms\Components\Grid::make()
                                            ->columns(4)
                                            ->columnSpanFull()
                                            ->schema([
                                                Forms\Components\ToggleButtons::make('unlimited_cpu')
                                                    ->label('CPU')->inlineLabel()->inline()
                                                    ->afterStateUpdated(fn (Forms\Set $set) => $set('cpu', 0))
                                                    ->formatStateUsing(fn (Forms\Get $get) => $get('cpu') == 0)
                                                    ->live()
                                                    ->options([
                                                        true => 'Unlimited',
                                                        false => 'Limited',
                                                    ])
                                                    ->colors([
                                                        true => 'primary',
                                                        false => 'warning',
                                                    ])
                                                    ->columnSpan(2),

                                                Forms\Components\TextInput::make('cpu')
                                                    ->dehydratedWhenHidden()
                                                    ->hidden(fn (Forms\Get $get) => $get('unlimited_cpu'))
                                                    ->label('CPU Limit')->inlineLabel()
                                                    ->suffix('%')
                                                    ->required()
                                                    ->columnSpan(2)
                                                    ->numeric()
                                                    ->minValue(0),
                                            ]),

                                        Forms\Components\Grid::make()
                                            ->columns(4)
                                            ->columnSpanFull()
                                            ->schema([
                                                Forms\Components\ToggleButtons::make('swap_support')
                                                    ->live()
                                                    ->label('Enable Swap Memory')->inlineLabel()->inline()
                                                    ->columnSpan(2)
                                                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                                                        $value = match ($state) {
                                                            'unlimited' => -1,
                                                            'disabled' => 0,
                                                            'limited' => 128,
                                                            default => throw new LogicException('Invalid state')
                                                        };

                                                        $set('swap', $value);
                                                    })
                                                    ->formatStateUsing(function (Forms\Get $get) {
                                                        return match (true) {
                                                            $get('swap') > 0 => 'limited',
                                                            $get('swap') == 0 => 'disabled',
                                                            $get('swap') < 0 => 'unlimited',
                                                            default => throw new LogicException('Invalid state')
                                                        };
                                                    })
                                                    ->options([
                                                        'unlimited' => 'Unlimited',
                                                        'limited' => 'Limited',
                                                        'disabled' => 'Disabled',
                                                    ])
                                                    ->colors([
                                                        'unlimited' => 'primary',
                                                        'limited' => 'warning',
                                                        'disabled' => 'danger',
                                                    ]),

                                                Forms\Components\TextInput::make('swap')
                                                    ->dehydratedWhenHidden()
                                                    ->hidden(fn (Forms\Get $get) => match ($get('swap_support')) {
                                                        'disabled', 'unlimited', true => true,
                                                        default => false,
                                                    })
                                                    ->label('Swap Memory')->inlineLabel()
                                                    ->suffix(config('panel.use_binary_prefix') ? 'MiB' : 'MB')
                                                    ->minValue(-1)
                                                    ->columnSpan(2)
                                                    ->required()
                                                    ->integer(),
                                            ]),

                                        Forms\Components\Hidden::make('io')
                                            ->helperText('The IO performance relative to other running containers')
                                            ->label('Block IO Proportion'),

                                        Forms\Components\Grid::make()
                                            ->columns(4)
                                            ->columnSpanFull()
                                            ->schema([
                                                Forms\Components\ToggleButtons::make('oom_killer')
                                                    ->label('OOM Killer')->inlineLabel()->inline()
                                                    ->columnSpan(2)
                                                    ->options([
                                                        false => 'Disabled',
                                                        true => 'Enabled',
                                                    ])
                                                    ->colors([
                                                        false => 'success',
                                                        true => 'danger',
                                                    ]),

                                                Forms\Components\TextInput::make('oom_disabled_hidden')
                                                    ->hidden(),
                                            ]),
                                    ]),

                                Forms\Components\Fieldset::make('Feature Limits')
                                    ->inlineLabel()
                                    ->columnSpan([
                                        'default' => 2,
                                        'sm' => 4,
                                        'md' => 4,
                                        'lg' => 6,
                                    ])
                                    ->columns([
                                        'default' => 1,
                                        'sm' => 2,
                                        'md' => 3,
                                        'lg' => 3,
                                    ])
                                    ->schema([
                                        Forms\Components\TextInput::make('allocation_limit')
                                            ->suffixIcon('tabler-network')
                                            ->required()
                                            ->minValue(0)
                                            ->numeric(),
                                        Forms\Components\TextInput::make('database_limit')
                                            ->suffixIcon('tabler-database')
                                            ->required()
                                            ->minValue(0)
                                            ->numeric(),
                                        Forms\Components\TextInput::make('backup_limit')
                                            ->suffixIcon('tabler-copy-check')
                                            ->required()
                                            ->minValue(0)
                                            ->numeric(),
                                    ]),
                                Forms\Components\Fieldset::make('Docker Settings')
                                    ->columnSpan([
                                        'default' => 2,
                                        'sm' => 4,
                                        'md' => 4,
                                        'lg' => 6,
                                    ])
                                    ->columns([
                                        'default' => 1,
                                        'sm' => 2,
                                        'md' => 3,
                                        'lg' => 3,
                                    ])
                                    ->schema([
                                        Forms\Components\Select::make('select_image')
                                            ->label('Image Name')
                                            ->afterStateUpdated(fn (Forms\Set $set, $state) => $set('image', $state))
                                            ->options(function ($state, Forms\Get $get, Forms\Set $set) {
                                                $egg = Egg::query()->find($get('egg_id'));
                                                $images = $egg->docker_images ?? [];

                                                $currentImage = $get('image');
                                                if (!$currentImage && $images) {
                                                    $defaultImage = collect($images)->first();
                                                    $set('image', $defaultImage);
                                                    $set('select_image', $defaultImage);
                                                }

                                                return array_flip($images) + ['ghcr.io/custom-image' => 'Custom Image'];
                                            })
                                            ->selectablePlaceholder(false)
                                            ->columnSpan(1),

                                        Forms\Components\TextInput::make('image')
                                            ->label('Image')
                                            ->debounce(500)
                                            ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                                $egg = Egg::query()->find($get('egg_id'));
                                                $images = $egg->docker_images ?? [];

                                                if (in_array($state, $images)) {
                                                    $set('select_image', $state);
                                                } else {
                                                    $set('select_image', 'ghcr.io/custom-image');
                                                }
                                            })
                                            ->placeholder('Enter a custom Image')
                                            ->columnSpan(2),

                                        Forms\Components\KeyValue::make('docker_labels')
                                            ->label('Container Labels')
                                            ->keyLabel('Label Name')
                                            ->valueLabel('Label Description')
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Tabs\Tab::make('Egg')
                            ->icon('tabler-egg')
                            ->columns([
                                'default' => 1,
                                'sm' => 3,
                                'md' => 3,
                                'lg' => 5,
                            ])
                            ->schema([
                                Forms\Components\Select::make('egg_id')
                                    ->disabledOn('edit')
                                    ->prefixIcon('tabler-egg')
                                    ->columnSpan([
                                        'default' => 1,
                                        'sm' => 3,
                                        'md' => 3,
                                        'lg' => 5,
                                    ])
                                    ->relationship('egg', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required(),

                                Forms\Components\ToggleButtons::make('skip_scripts')
                                    ->label('Run Egg Install Script?')->inline()
                                    ->options([
                                        false => 'Yes',
                                        true => 'Skip',
                                    ])
                                    ->colors([
                                        false => 'primary',
                                        true => 'danger',
                                    ])
                                    ->icons([
                                        false => 'tabler-code',
                                        true => 'tabler-code-off',
                                    ])
                                    ->required(),

                                Forms\Components\TagsInput::make('ports')
                                    ->columnSpan(3)
                                    ->placeholder('Example: 25565, 8080, 1337-1340')
                                    ->splitKeys(['Tab', ' ', ','])
                                    ->helperText(new HtmlString('
                                        These are the ports that users can connect to this Server through.
                                        <br />
                                        You would typically port forward these on your home network.
                                    '))
                                    ->label('Ports')
                                    ->formatStateUsing(fn (Server $server) => $server->ports->map(fn ($port) => (string) $port)->all())
                                    ->afterStateUpdated(self::ports(...))
                                    ->live(),

                                Forms\Components\Repeater::make('portVariables')
                                    ->label('Port Assignments (SV)')
                                    ->columnSpan(3)
                                    ->addable(false)
                                    ->deletable(false)

                                    ->mutateRelationshipDataBeforeSaveUsing(function ($data) {
                                        $portIndex = $data['port'];
                                        unset($data['port']);

                                        return [
                                            'variable_value' => (string) $this->ports[$portIndex],
                                        ];
                                    })

                                    ->relationship('serverVariables', function (Builder $query) {
                                        $query->whereHas('variable', function (Builder $query) {
                                            $query->where('rules', 'like', '%port%');
                                        });
                                    })

                                    ->simple(
                                        Forms\Components\Select::make('port')
                                            ->live()
                                            ->disabled(fn (Forms\Get $get) => empty($get('../../ports')) || empty($get('../../assignments')))
                                            ->prefix(function (Forms\Components\Component $component, ServerVariable $serverVariable) {
                                                return $serverVariable->variable->env_variable;
                                            })

                                            ->formatStateUsing(function (ServerVariable $serverVariable, Forms\Get $get) {
                                                return array_search($serverVariable->variable_value, array_values($get('../../ports')));
                                            })

                                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                            ->options(fn (Forms\Get $get) => $this->ports)
                                            ->required(),
                                    )

                                    ->afterStateHydrated(function (Forms\Set $set, Forms\Get $get, Server $server) {
                                        $this->ports($ports = $get('ports'), $set);

                                        foreach ($this->portOptions($server->egg) as $key => $port) {
                                            $set("assignments.$key", ['port' => $portIndex = array_search($port, array_values($ports))]);
                                        }
                                    }),

                                Forms\Components\Textarea::make('startup')
                                    ->label('Startup Command')
                                    ->required()
                                    ->hintAction(Forms\Components\Actions\Action::make('startup-restore')
                                        ->label('Restore Default')
                                        ->icon('tabler-restore')
                                        ->action(fn (Forms\Get $get, Forms\Set $set) => $set('startup', Egg::find($get('egg_id'))?->startup ?? '')
                                        )
                                    )
                                    ->columnSpan([
                                        'default' => 2,
                                        'sm' => 4,
                                        'md' => 4,
                                        'lg' => 6,
                                    ])
                                    ->rows(fn ($state) => str($state)->explode("\n")->reduce(
                                        fn (int $carry, $line) => $carry + floor(strlen($line) / 125), 0
                                    )),

                                Forms\Components\Hidden::make('environment')->default([]),

                                Forms\Components\Repeater::make('server_variables')
                                    ->relationship('serverVariables', function (Builder $query) {
                                        $query->whereHas('variable', function (Builder $query) {
                                            $query->whereNot('rules', 'like', '%port%');
                                        });
                                    })
                                    ->grid()
                                    ->mutateRelationshipDataBeforeSaveUsing(function (array &$data): array {
                                        foreach ($data as $key => $value) {
                                            if (!isset($data['variable_value'])) {
                                                $data['variable_value'] = '';
                                            }
                                        }

                                        return $data;
                                    })
                                    ->reorderable(false)->addable(false)->deletable(false)
                                    ->schema(function () {

                                        $text = Forms\Components\TextInput::make('variable_value')
                                            ->hidden($this->shouldHideComponent(...))
                                            ->required(fn (ServerVariable $serverVariable) => in_array('required', explode('|', $serverVariable->variable->rules)))
                                            ->rules([
                                                fn (ServerVariable $serverVariable): Closure => function (string $attribute, $value, Closure $fail) use ($serverVariable) {
                                                    $validator = Validator::make(['validatorkey' => $value], [
                                                        'validatorkey' => $serverVariable->variable->rules,
                                                    ]);

                                                    if ($validator->fails()) {
                                                        $message = str($validator->errors()->first())->replace('validatorkey', $serverVariable->variable->name);

                                                        $fail($message);
                                                    }
                                                },
                                            ]);

                                        $select = Forms\Components\Select::make('variable_value')
                                            ->hidden($this->shouldHideComponent(...))
                                            ->options($this->getSelectOptionsFromRules(...))
                                            ->selectablePlaceholder(false);

                                        $components = [$text, $select];

                                        foreach ($components as &$component) {
                                            $component = $component
                                                ->live(onBlur: true)
                                                ->hintIcon('tabler-code')
                                                ->label(fn (ServerVariable $serverVariable) => $serverVariable->variable?->name)
                                                ->hintIconTooltip(fn (ServerVariable $serverVariable) => $serverVariable->variable?->rules)
                                                ->prefix(fn (ServerVariable $serverVariable) => '{{' . $serverVariable->variable?->env_variable . '}}')
                                                ->helperText(fn (ServerVariable $serverVariable) => empty($serverVariable->variable?->description) ? '—' : $serverVariable->variable->description);
                                        }

                                        return $components;
                                    })
                                    ->columnSpan(6),
                            ]),
                        Tabs\Tab::make('Mounts')
                            ->icon('tabler-layers-linked')
                            ->schema([
                                Forms\Components\CheckboxList::make('mounts')
                                    ->relationship('mounts')
                                    ->options(fn (Server $server) => $server->node->mounts->mapWithKeys(fn ($mount) => [$mount->id => $mount->name]))
                                    ->descriptions(fn (Server $server) => $server->node->mounts->mapWithKeys(fn ($mount) => [$mount->id => "$mount->source -> $mount->target"]))
                                    ->label('Mounts')
                                    ->helperText(fn (Server $server) => $server->node->mounts->isNotEmpty() ? '' : 'No Mounts exist for this Node')
                                    ->columnSpanFull(),
                            ]),
                        Tabs\Tab::make('Databases')
                            ->icon('tabler-database')
                            ->schema([
                                Repeater::make('databases')
                                    ->grid()
                                    ->helperText(fn (Server $server) => $server->databases->isNotEmpty() ? '' : 'No Databases exist for this Server')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('database')
                                            ->columnSpan(2)
                                            ->label('Database Name')
                                            ->disabled()
                                            ->formatStateUsing(fn ($record) => $record->database)
                                            ->hintAction(
                                                Action::make('Delete')
                                                    ->color('danger')
                                                    ->icon('tabler-trash')
                                                    ->action(fn (DatabaseManagementService $databaseManagementService, $record) => $databaseManagementService->delete($record))
                                            ),
                                        Forms\Components\TextInput::make('username')
                                            ->disabled()
                                            ->formatStateUsing(fn ($record) => $record->username)
                                            ->columnSpan(2),
                                        Forms\Components\TextInput::make('password')
                                            ->disabled()
                                            ->hintAction(
                                                Action::make('rotate')
                                                    ->icon('tabler-refresh')
                                                    ->requiresConfirmation()
                                                    ->action(fn (DatabasePasswordService $service, $record, $set, $get) => $this->rotatePassword($service, $record, $set, $get))
                                            )
                                            ->formatStateUsing(fn (Database $database) => $database->password)
                                            ->columnSpan(2),
                                        Forms\Components\TextInput::make('remote')
                                            ->disabled()
                                            ->formatStateUsing(fn ($record) => $record->remote)
                                            ->columnSpan(1)
                                            ->label('Connections From'),
                                        Forms\Components\TextInput::make('max_connections')
                                            ->disabled()
                                            ->formatStateUsing(fn ($record) => $record->max_connections)
                                            ->columnSpan(1),
                                        Forms\Components\TextInput::make('JDBC')
                                            ->disabled()
                                            ->label('JDBC Connection String')
                                            ->columnSpan(2)
                                            ->formatStateUsing(fn (Forms\Get $get, $record) => 'jdbc:mysql://' . $get('username') . ':' . urlencode($record->password) . '@' . $record->host->host . ':' . $record->host->port . '/' . $get('database')),
                                    ])
                                    ->relationship('databases')
                                    ->deletable(false)
                                    ->addable(false)
                                    ->columnSpan(4),
                            ])->columns(4),
                        Tabs\Tab::make('Actions')
                            ->icon('tabler-settings')
                            ->schema([
                                Forms\Components\Fieldset::make('Server Actions')
                                    ->columns([
                                        'default' => 1,
                                        'sm' => 2,
                                        'md' => 2,
                                        'lg' => 6,
                                    ])
                                    ->schema([
                                        Forms\Components\Grid::make()
                                            ->columnSpan(3)
                                            ->schema([
                                                Forms\Components\Actions::make([
                                                    Forms\Components\Actions\Action::make('toggleInstall')
                                                        ->label('Toggle Install Status')
                                                        ->disabled(fn (Server $server) => $server->isSuspended())
                                                        ->action(function (ServersController $serversController, Server $server) {
                                                            $serversController->toggleInstall($server);

                                                            $this->refreshFormData(['status', 'docker']);
                                                        }),
                                                ])->fullWidth(),
                                                Forms\Components\ToggleButtons::make('')
                                                    ->hint('If you need to change the install status from uninstalled to installed, or vice versa, you may do so with this button.'),
                                            ]),
                                        Forms\Components\Grid::make()
                                            ->columnSpan(3)
                                            ->schema([
                                                Forms\Components\Actions::make([
                                                    Forms\Components\Actions\Action::make('toggleSuspend')
                                                        ->label('Suspend')
                                                        ->color('warning')
                                                        ->hidden(fn (Server $server) => $server->isSuspended())
                                                        ->action(function (SuspensionService $suspensionService, Server $server) {
                                                            $suspensionService->toggle($server, 'suspend');
                                                            Notification::make()->success()->title('Server Suspended!')->send();

                                                            $this->refreshFormData(['status', 'docker']);
                                                        }),
                                                    Forms\Components\Actions\Action::make('toggleUnsuspend')
                                                        ->label('Unsuspend')
                                                        ->color('success')
                                                        ->hidden(fn (Server $server) => !$server->isSuspended())
                                                        ->action(function (SuspensionService $suspensionService, Server $server) {
                                                            $suspensionService->toggle($server, 'unsuspend');
                                                            Notification::make()->success()->title('Server Unsuspended!')->send();

                                                            $this->refreshFormData(['status', 'docker']);
                                                        }),
                                                ])->fullWidth(),
                                                Forms\Components\ToggleButtons::make('')
                                                    ->hidden(fn (Server $server) => $server->isSuspended())
                                                    ->hint('This will suspend the server, stop any running processes, and immediately block the user from being able to access their files or otherwise manage the server through the panel or API.'),
                                                Forms\Components\ToggleButtons::make('')
                                                    ->hidden(fn (Server $server) => !$server->isSuspended())
                                                    ->hint('This will unsuspend the server and restore normal user access.'),
                                            ]),
                                        Forms\Components\Grid::make()
                                            ->columnSpan(3)
                                            ->schema([
                                                Forms\Components\Actions::make([
                                                    Forms\Components\Actions\Action::make('transfer')
                                                        ->label('Transfer Soon™')
                                                        ->action(fn (TransferServerService $transfer, Server $server) => $transfer->handle($server, []))
                                                        ->disabled() //TODO!
                                                        ->form([ //TODO!
                                                            Forms\Components\Select::make('newNode')
                                                                ->label('New Node')
                                                                ->required()
                                                                ->options([
                                                                    true => 'on',
                                                                    false => 'off',
                                                                ]),
                                                            Forms\Components\Select::make('newMainAllocation')
                                                                ->label('New Main Allocation')
                                                                ->required()
                                                                ->options([
                                                                    true => 'on',
                                                                    false => 'off',
                                                                ]),
                                                            Forms\Components\Select::make('newAdditionalAllocation')
                                                                ->label('New Additional Allocations')
                                                                ->options([
                                                                    true => 'on',
                                                                    false => 'off',
                                                                ]),
                                                        ])
                                                        ->modalHeading('Transfer'),
                                                ])->fullWidth(),
                                                Forms\Components\ToggleButtons::make('')
                                                    ->hint('Transfer this server to another node connected to this panel. Warning! This feature has not been fully tested and may have bugs.'),
                                            ]),
                                        Forms\Components\Grid::make()
                                            ->columnSpan(3)
                                            ->schema([
                                                Forms\Components\Actions::make([
                                                    Forms\Components\Actions\Action::make('reinstall')
                                                        ->label('Reinstall')
                                                        ->color('danger')
                                                        ->requiresConfirmation()
                                                        ->modalHeading('Are you sure you want to reinstall this server?')
                                                        ->modalDescription('!! This can result in unrecoverable data loss !!')
                                                        ->disabled(fn (Server $server) => $server->isSuspended())
                                                        ->action(fn (ServersController $serversController, Server $server) => $serversController->reinstallServer($server)),
                                                ])->fullWidth(),
                                                Forms\Components\ToggleButtons::make('')
                                                    ->hint('This will reinstall the server with the assigned egg install script.'),
                                            ]),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    protected function transferServer(Form $form): Form
    {
        return $form
            ->columns()
            ->schema([
                Forms\Components\Select::make('toNode')
                    ->label('New Node'),
                Forms\Components\TextInput::make('newAllocation')
                    ->label('Allocation'),
            ]);

    }
    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make('Delete')
                ->successRedirectUrl(route('filament.admin.resources.servers.index'))
                ->color('danger')
                ->disabled(fn (Server $server) => $server->databases()->count() > 0)
                ->label(fn (Server $server) => $server->databases()->count() > 0 ? 'Server has a Database' : 'Delete')
                ->after(fn (Server $server) => resolve(ServerDeletionService::class)->handle($server))
                ->requiresConfirmation(),
            Actions\Action::make('console')
                ->label('Console')
                ->icon('tabler-terminal')
                ->url(fn (Server $server) => "/server/$server->uuid_short"),
            $this->getSaveFormAction()->formId('form'),
        ];

    }
    protected function getFormActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (!isset($data['description'])) {
            $data['description'] = '';
        }

        unset($data['docker'], $data['status']);

        return $data;
    }

    private function shouldHideComponent(ServerVariable $serverVariable, Forms\Components\Component $component): bool
    {
        $rules = str($serverVariable->variable->rules)->explode('|');

        $containsRuleIn = $rules->reduce(
            fn ($result, $value) => $result === true && !str($value)->startsWith('in:'), true
        );

        if ($rules->contains('port')) {
            return true;
        }

        if ($component instanceof Forms\Components\Select) {
            return $containsRuleIn;
        }

        if ($component instanceof Forms\Components\TextInput) {
            return !$containsRuleIn;
        }

        throw new \Exception('Component type not supported: ' . $component::class);
    }

    private function getSelectOptionsFromRules(ServerVariable $serverVariable): array
    {
        $rules = str($serverVariable->variable->rules)->explode('|');
        $inRule = $rules->reduce(
            fn ($result, $value) => str($value)->startsWith('in:') ? $value : $result, ''
        );

        return str($inRule)
            ->after('in:')
            ->explode(',')
            ->each(fn ($value) => str($value)->trim())
            ->mapWithKeys(fn ($value) => [$value => $value])
            ->all();
    }

    public function ports($state, Forms\Set $set)
    {
        $ports = collect();
        foreach ($state as $portEntry) {
            if (str_contains($portEntry, '-')) {
                [$start, $end] = explode('-', $portEntry);
                if (!is_numeric($start) || !is_numeric($end)) {
                    continue;
                }

                $start = max((int) $start, Endpoint::PORT_FLOOR);
                $end = min((int) $end, Endpoint::PORT_CEIL);
                for ($i = $start; $i <= $end; $i++) {
                    $ports->push($i);
                }
            }

            if (!is_numeric($portEntry)) {
                continue;
            }

            $ports->push((int) $portEntry);
        }

        $uniquePorts = $ports->unique()->values();
        if ($ports->count() > $uniquePorts->count()) {
            $ports = $uniquePorts;
        }

        $ports = $ports->filter(fn ($port) => $port > 1024 && $port < 65535)->values();

        $set('ports', $ports->all());
        $this->ports = $ports->all();
    }

    public function resetEggVariables(Forms\Set $set, Forms\Get $get)
    {
        $set('assignments', []);

        $i = 0;
        $this->eggDefaultPorts = [];
        if (str_contains($get('startup'), '{{SERVER_PORT}}')) {
            $this->eggDefaultPorts['SERVER_PORT'] = null;
            $set('assignments.SERVER_PORT', ['port' => null]);
        }

        $variables = $this->getRecord()->egg->variables ?? [];
        $serverVariables = collect();
        $this->ports = [];
        foreach ($variables as $variable) {
            if (str_contains($variable->rules, 'port')) {
                $this->eggDefaultPorts[$variable->env_variable] = $variable->default_value;
                $this->ports[] = (int) $variable->default_value;

                $set("assignments.$variable->env_variable", ['port' => $i++]);

                continue;
            }

            $serverVariables->add($variable->toArray());
        }

        $set('ports', $this->ports);

        $variables = [];
        $set($path = 'server_variables', $serverVariables->sortBy(['sort'])->all());
        for ($i = 0; $i < $serverVariables->count(); $i++) {
            $set("$path.$i.variable_value", $serverVariables[$i]['default_value']);
            $set("$path.$i.variable_id", $serverVariables[$i]['id']);
            $variables[$serverVariables[$i]['env_variable']] = $serverVariables[$i]['default_value'];
        }

        $set('environment', $variables);
    }

    public function portOptions(Egg $egg, string $startup = null): array
    {
        if ($egg instanceof Server) {
            $egg = $egg->egg;
        }

        $options = [];

        if (empty($startup)) {
            $startup = $egg->startup;
        }

        if (str_contains($startup, '{{SERVER_PORT}}')) {
            $options['SERVER_PORT'] = null;
        }

        foreach ($egg->variables as $variable) {
            if (!in_array('port', explode('|', $variable->rules))) {
                continue;
            }

            $options[$variable->env_variable] = $variable->default_value;
        }

        return $options;
    }

    protected function rotatePassword(DatabasePasswordService $service, $record, $set, $get): void
    {
        $newPassword = $service->handle($record);
        $jdbcString = 'jdbc:mysql://' . $get('username') . ':' . urlencode($newPassword) . '@' . $record->host->host . ':' . $record->host->port . '/' . $get('database');

        $set('password', $newPassword);
        $set('JDBC', $jdbcString);
    }
}
