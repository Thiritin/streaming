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
        
        // Extract configurations from the install script
        $this->extractConfigurationsFromScript($this->installScript);
    }
    
    protected function extractConfigurationsFromScript(string $script): void
    {
        // Extract Docker Compose configuration
        if (preg_match("/cat > docker-compose\.yml <<'DOCKERCOMPOSE'(.*?)DOCKERCOMPOSE/s", $script, $matches)) {
            $this->dockerComposeConfig = trim($matches[1]);
        }
        
        // Extract SRS configuration
        if (preg_match("/cat > srs\.conf <<'SRSCONF'(.*?)SRSCONF/s", $script, $matches)) {
            $this->srsConfig = trim($matches[1]);
        }
        
        // Extract Nginx configuration
        if (preg_match("/cat > nginx\.conf <<'NGINXCONF'(.*?)NGINXCONF/s", $script, $matches)) {
            if ($this->record->type->value === 'origin') {
                $this->nginxOriginConfig = trim($matches[1]);
            } else {
                $this->nginxEdgeConfig = trim($matches[1]);
            }
        }
        
        // Extract Caddy configuration
        if (preg_match("/cat > Caddyfile <<'CADDYFILE'(.*?)CADDYFILE/s", $script, $matches)) {
            if ($this->record->type->value === 'origin') {
                $this->caddyOriginConfig = trim($matches[1]);
            } else {
                $this->caddyEdgeConfig = trim($matches[1]);
            }
        }
        
        // For origin servers, extract FFmpeg related configurations
        if ($this->record->type->value === 'origin') {
            // Note: FFmpeg Dockerfile and script would need to be added to the ServerProvisioningService
            // For now, we'll leave these empty as they're not in the current implementation
            $this->ffmpegDockerfile = "# FFmpeg Dockerfile not available in current implementation";
            $this->ffmpegScript = "# FFmpeg stream manager script not available in current implementation";
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