<?php

namespace App\Filament\Resources\NodeResource\Pages;

use App\Filament\Resources\NodeResource;
use App\Models\Node;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\Tabs;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\HtmlString;
use Webbingbrasil\FilamentCopyActions\Forms\Actions\CopyAction;

class EditNode extends EditRecord
{
    protected static string $resource = NodeResource::class;

    public function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Tabs::make('Tabs')
                ->columns(4)
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs([
                    Tabs\Tab::make('Basic Settings')
                        ->icon('tabler-server')
                        ->schema((new CreateNode())->form($form)->getComponents()),
                    Tabs\Tab::make('Advanced Settings')
                        ->icon('tabler-server-cog'),
                    Tabs\Tab::make('Configuration')
                        ->icon('tabler-code')
                        ->schema([
                            Forms\Components\Placeholder::make('instructions')
                                ->columnSpanFull()
                                ->content(new HtmlString('
                                  Save this file to your <span title="usually /etc/pelican/">daemon\'s root directory</span>, named <code>config.yml</code>
                            ')),
                            Forms\Components\Textarea::make('config')
                                ->label('/etc/pelican/config.yml')
                                ->disabled()
                                ->rows(19)
                                ->hintAction(CopyAction::make())
                                ->columnSpanFull(),
                        ]),
                    Tabs\Tab::make('Allocations')
                        ->icon('tabler-plug-connected')
                        ->columns(5)
                        ->schema([
                            Forms\Components\Repeater::make('allocations')
                                ->orderColumn('server_id')
                                ->columnSpan(3)
                                ->columns(4)
                                ->relationship()
                                ->addActionLabel('Create New Allocation')
                                ->addAction(fn ($action) => $action->color('info'))
                                ->schema([
                                    Forms\Components\TextInput::make('ip')
                                        ->label('IP Address'),
                                    Forms\Components\TextInput::make('ip_alias')
                                        ->label('Alias'),
                                    Forms\Components\TextInput::make('port')
                                        ->minValue(0)
                                        ->maxValue(65535)
                                        ->numeric(),
                                    Forms\Components\Select::make('server_id')->relationship('server', 'name'),
                                ])
                        ]),
                ])
        ]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $node = Node::findOrFail($data['id']);

        $data['config'] = $node->getYamlConfiguration();

        return $data;
    }

    protected function getSteps(): array
    {
        return [
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}