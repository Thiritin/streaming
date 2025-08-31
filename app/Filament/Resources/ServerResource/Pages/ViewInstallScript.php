<?php

namespace App\Filament\Resources\ServerResource\Pages;

use App\Filament\Resources\ServerResource;
use App\Models\Server;
use App\Services\ServerProvisioningService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Blade;

class ViewInstallScript extends Page
{
    protected static string $resource = ServerResource::class;
    
    protected static string $view = 'filament.resources.server-resource.pages.view-install-script';
    
    public Server $record;
    
    public string $installScript = '';
    public string $cloudInitScript = '';
    public string $dockerComposeConfig = '';
    public string $nginxOriginConfig = '';
    public string $nginxEdgeConfig = '';
    public string $caddyOriginConfig = '';
    public string $caddyEdgeConfig = '';
    public string $srsConfig = '';
    public string $ffmpegDockerfile = '';
    public string $ffmpegScript = '';
    public string $activeTab = 'install';
    
    public function mount(Server $record): void
    {
        $this->record = $record;
        $this->generateScripts();
    }
    
    protected function generateScripts(): void
    {
        $provisioningService = app(ServerProvisioningService::class);
        
        $this->installScript = $provisioningService->generateInstallScript($this->record);
        $this->cloudInitScript = $provisioningService->generateCloudInit($this->record);
        $this->dockerComposeConfig = $provisioningService->generateDockerCompose($this->record);
        $this->srsConfig = $provisioningService->generateSrsConfig($this->record);
        
        // Generate config files based on server type
        if ($this->record->type->value === 'origin') {
            $this->nginxOriginConfig = $provisioningService->generateNginxOriginConfig($this->record);
            $this->caddyOriginConfig = $provisioningService->generateCaddyOriginConfig($this->record);
            $this->ffmpegDockerfile = $provisioningService->generateFFmpegDockerfile();
            $this->ffmpegScript = $provisioningService->generateFFmpegStreamManager();
        } else {
            $this->nginxEdgeConfig = $provisioningService->generateNginxEdgeConfig($this->record);
            $this->caddyEdgeConfig = $provisioningService->generateCaddyEdgeConfig($this->record);
        }
    }
    
    protected function getHeaderActions(): array
    {
        return [
            Action::make('copyInstallScript')
                ->label('Copy Install Script')
                ->icon('heroicon-o-clipboard-document')
                ->action(function () {
                    // JavaScript will handle the actual copy
                    Notification::make()
                        ->title('Copied!')
                        ->body('Install script copied to clipboard')
                        ->success()
                        ->send();
                }),
            
            Action::make('downloadInstallScript')
                ->label('Download Script')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function () {
                    return response()->streamDownload(function () {
                        echo $this->installScript;
                    }, "ef-streaming-install-{$this->record->id}.sh");
                }),
            
            Action::make('regenerate')
                ->label('Regenerate Scripts')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->action(function () {
                    // Regenerate shared secret if needed
                    if (!$this->record->shared_secret) {
                        $this->record->update([
                            'shared_secret' => \Illuminate\Support\Str::random(32)
                        ]);
                    }
                    
                    $this->generateScripts();
                    
                    Notification::make()
                        ->title('Scripts Regenerated')
                        ->success()
                        ->send();
                }),
        ];
    }
    
    public function getTitle(): string
    {
        return "Install Script - Server #{$this->record->id} ({$this->record->type->value})";
    }
    
    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }
}