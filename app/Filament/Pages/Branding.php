<?php

namespace App\Filament\Pages;

use App\Models\BrandingSetting;
use App\Services\BrandingService;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;

class Branding extends Page implements HasForms
{
    use InteractsWithFormActions;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-paint-brush';

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationGroup = 'Streaming';

    protected static ?string $navigationLabel = 'Branding';

    protected static ?string $title = 'Branding';

    protected static string $view = 'filament.pages.branding';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill(app(BrandingService::class)->all());
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Section::make('Identity')
                    ->description('Who this installation belongs to, and where people get an account.')
                    ->schema([
                        TextInput::make('convention_name')
                            ->label('Convention name')
                            ->helperText(BrandingService::EDITABLE['convention_name'])
                            ->required(),
                        TextInput::make('site_name')
                            ->label('Site name')
                            ->helperText(BrandingService::EDITABLE['site_name'])
                            ->required(),
                        TextInput::make('identity_name')
                            ->label('Identity provider name')
                            ->helperText(BrandingService::EDITABLE['identity_name'])
                            ->required(),
                        TextInput::make('identity_register_url')
                            ->label('Register URL')
                            ->helperText(BrandingService::EDITABLE['identity_register_url'])
                            ->url(),
                        TextInput::make('identity_logout_url')
                            ->label('Logout URL')
                            ->helperText(BrandingService::EDITABLE['identity_logout_url'])
                            ->url(),
                    ])
                    ->columns(2),

                Section::make('Login screen')
                    ->description('Everything shown to visitors before they sign in.')
                    ->schema([
                        TextInput::make('login_eyebrow')
                            ->label('Eyebrow')
                            ->helperText(BrandingService::EDITABLE['login_eyebrow']),
                        TextInput::make('login_headline')
                            ->label('Headline')
                            ->helperText(BrandingService::EDITABLE['login_headline'])
                            ->required(),
                        TextInput::make('login_tagline')
                            ->label('Tagline')
                            ->helperText(BrandingService::EDITABLE['login_tagline']),
                        TextInput::make('login_button_label')
                            ->label('Button label')
                            ->helperText(BrandingService::EDITABLE['login_button_label'])
                            ->required(),
                        Textarea::make('login_body')
                            ->label('Intro paragraph')
                            ->helperText(BrandingService::EDITABLE['login_body'])
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Look')
                    ->description('Logo, accent colour and optional login background. Uploads land on the public disk.')
                    ->schema([
                        ColorPicker::make('primary_color')
                            ->label('Accent colour')
                            ->helperText(BrandingService::EDITABLE['primary_color']),
                        FileUpload::make('logo_path')
                            ->label('Logo')
                            ->helperText(BrandingService::EDITABLE['logo_path'])
                            ->disk('public')
                            ->directory('branding')
                            ->image()
                            ->imageEditor(),
                        FileUpload::make('login_background_image')
                            ->label('Login background image')
                            ->helperText(BrandingService::EDITABLE['login_background_image'])
                            ->disk('public')
                            ->directory('branding')
                            ->image(),
                        FileUpload::make('login_background_video')
                            ->label('Login background video')
                            ->helperText(BrandingService::EDITABLE['login_background_video'])
                            ->disk('public')
                            ->directory('branding')
                            ->acceptedFileTypes(['video/mp4', 'video/webm']),
                    ])
                    ->columns(2),

                Section::make('Footer links')
                    ->schema([
                        TextInput::make('support_url')->label('Support')->url(),
                        TextInput::make('imprint_url')->label('Legal Notice')->url(),
                        TextInput::make('privacy_url')->label('Privacy')->url(),
                    ])
                    ->columns(3),
            ]);
    }

    /**
     * @return array<int, Action>
     */
    public function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save changes')
                ->submit('save'),
            Action::make('reset')
                ->label('Reset to defaults')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Reset branding to defaults?')
                ->modalDescription('Every saved value is deleted and the defaults from config/branding.php apply again. Uploaded files are kept.')
                ->action('resetToDefaults'),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();

        foreach (array_keys(BrandingService::EDITABLE) as $key) {
            BrandingSetting::setValue(
                $key,
                $state[$key] ?? null,
                BrandingService::EDITABLE[$key],
            );
        }

        Notification::make()
            ->title('Branding saved')
            ->success()
            ->send();
    }

    public function resetToDefaults(): void
    {
        BrandingSetting::query()->get()->each->delete();

        $this->form->fill(app(BrandingService::class)->all());

        Notification::make()
            ->title('Branding reset to defaults')
            ->success()
            ->send();
    }
}
