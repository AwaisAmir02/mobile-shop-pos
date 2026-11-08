<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Pins the design overhaul's colour decisions for the shell and pickers
 * (docs/design-system.md): a white sidebar with a soft navy active item, and
 * orange used only for New Sale and Complete Sale.
 *
 * Rewritten in design overhaul D1. It used to pin the navy sidebar with a solid
 * orange active item and orange highlights in the select dropdowns, which the
 * owner replaced on 2026-11-08.
 */
class AccentHoverColorTest extends TestCase
{
    /** The sidebar plus the partial that renders each of its links. */
    private function sidebarSource(): string
    {
        return file_get_contents(resource_path('views/components/layout/sidebar.blade.php'))
            .file_get_contents(resource_path('views/components/layout/sidebar-link.blade.php'));
    }

    public function test_the_sidebar_is_a_light_surface_not_the_old_navy_panel(): void
    {
        $html = $this->sidebarSource();

        $this->assertStringContainsString('bg-surface', $html);
        $this->assertStringNotContainsString('bg-brand-950', $html);
        $this->assertStringNotContainsString('hover:bg-white/10', $html);
    }

    public function test_the_active_nav_item_is_soft_navy_not_solid_orange(): void
    {
        $html = $this->sidebarSource();

        $this->assertStringContainsString("'bg-primary-soft font-semibold text-on-primary-soft' => \$active", $html);
        $this->assertStringNotContainsString('bg-accent-600 text-white', $html);
    }

    public function test_the_only_orange_left_in_the_sidebar_is_the_wordmark(): void
    {
        preg_match_all('/(?<![\w-])(?:[a-z-]+:)*(?:bg|text|ring|border)-(?:accent-\d+|sale)\b/', $this->sidebarSource(), $matches);

        $this->assertSame(['text-accent-500'], $matches[0]);
    }

    public function test_the_select_dropdowns_highlight_options_in_soft_navy_not_orange(): void
    {
        foreach (['select', 'image-select'] as $component) {
            $html = file_get_contents(resource_path("views/components/ui/{$component}.blade.php"));

            $this->assertStringContainsString("'bg-primary-soft text-on-primary-soft': highlighted === index", $html, $component);
            $this->assertStringNotContainsString('bg-accent-500 text-white', $html, $component);
        }
    }

    public function test_the_header_new_sale_button_is_the_sale_orange(): void
    {
        $html = file_get_contents(resource_path('views/livewire/layout/navigation.blade.php'));

        $this->assertStringContainsString('bg-sale', $html);
        $this->assertStringContainsString('hover:bg-sale-hover', $html);
    }

    public function test_this_change_did_not_touch_stock_in_vendors_products_or_any_fifo_related_file(): void
    {
        $touched = [
            resource_path('views/components/layout/sidebar.blade.php'),
            resource_path('views/components/ui/select.blade.php'),
            resource_path('views/components/ui/image-select.blade.php'),
        ];

        foreach ($touched as $path) {
            $html = file_get_contents($path);
            $this->assertStringNotContainsString('ConsumeStockFifo', $html);
            $this->assertStringNotContainsString('quantity_remaining', $html);
        }
    }
}
