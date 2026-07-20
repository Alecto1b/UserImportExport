<?php

namespace LeconfePlugins\UserImportExport;

use App\Classes\Plugin;
use App\Providers\PanelProvider;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use LeconfePlugins\UserImportExport\Pages\UserImportExportPage;

class UserImportExportPlugin extends Plugin
{
    public function boot(): void
    {
        $this->enablePublicAsset();
    }

    public function onPanel(Panel $panel): void
    {
        if ($panel->getId() !== PanelProvider::PANEL_SCHEDULED_CONFERENCE) {
            return;
        }

        $panel->pages([
            UserImportExportPage::class,
        ])->renderHook(
            PanelsRenderHook::HEAD_END,
            fn () => view('UserImportExport::hooks.styles', [
                'assetUrl' => $this->asset('css/user-import-export.css'),
            ]),
        );
    }

    public function getPluginPage(): ?string
    {
        if (! UserImportExportPage::canAccess() || ! app()->getCurrentScheduledConferenceId()) {
            return null;
        }

        try {
            return UserImportExportPage::getUrl();
        } catch (\Throwable) {
            return null;
        }
    }
}
