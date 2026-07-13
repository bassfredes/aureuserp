<?php

namespace Webkul\Support;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\HtmlString;

class SupportPlugin implements Plugin
{
    public function getId(): string
    {
        return 'support';
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public function register(Panel $panel): void
    {
        $panel
            ->when($panel->getId() == 'admin', function (Panel $panel) {
                $panel->passwordReset()
                    ->discoverResources(
                        in: __DIR__.'/Filament/Resources',
                        for: 'Webkul\\Support\\Filament\\Resources'
                    )
                    ->discoverPages(
                        in: __DIR__.'/Filament/Pages',
                        for: 'Webkul\\Support\\Filament\\Pages'
                    )
                    ->discoverClusters(
                        in: __DIR__.'/Filament/Clusters',
                        for: 'Webkul\\Support\\Filament\\Clusters'
                    )
                    ->discoverClusters(
                        in: __DIR__.'/Filament/Widgets',
                        for: 'Webkul\\Support\\Filament\\Widgets'
                    );
            });
    }

    public function boot(Panel $panel): void
    {
        FilamentView::registerRenderHook(
            name: 'panels::scripts.before',
            hook: fn () => new HtmlString(html: "
            <script>
                document.addEventListener('livewire:navigated', function() {
                    setTimeout(() => {
                        const activeSidebarItem = document.querySelector('nav .fi-sidebar-item-active');

                        const sidebarWrapper = document.querySelector('nav.fi-sidebar-nav');

                        sidebarWrapper.scrollTo(0, activeSidebarItem.offsetTop - 250);
                    }, 0);
                });
            </script>
        "));

        // RichEditor's floating node toolbars (table, paragraph, ...) are
        // TipTap BubbleMenu instances shown/hidden imperatively via JS, not
        // Alpine x-show. Livewire's wire:navigate morph can carry a stale
        // "shown" toolbar element across a page transition (matching
        // x-ref="floatingToolbar::<node>" on the new page), leaving it
        // positioned over unrelated content and intercepting clicks
        // (bassfredes/Intelligent-Integration-Suite#144). Force every
        // floating toolbar hidden and inert on both sides of a navigation;
        // TipTap re-shows it correctly once the user actually focuses a
        // matching node again.
        FilamentView::registerRenderHook(
            name: 'panels::scripts.before',
            hook: fn () => new HtmlString(html: "
            <script>
                function resetRichEditorFloatingToolbars() {
                    document.querySelectorAll('.fi-fo-rich-editor-floating-toolbar').forEach(function(toolbar) {
                        toolbar.classList.add('invisible');
                        toolbar.style.pointerEvents = 'none';
                    });
                }

                document.addEventListener('livewire:navigating', resetRichEditorFloatingToolbars);
                document.addEventListener('livewire:navigated', resetRichEditorFloatingToolbars);
            </script>
        "));
    }
}
