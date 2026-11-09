<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Pins the shell and picker colours (docs/design-system.md): the navy sidebar
 * with a solid orange active item, soft navy highlights in the select
 * dropdowns, and the sale orange for New Sale.
 *
 * Rewritten in design overhaul D1 for a white sidebar, then again in D12 when
 * the owner brought back the original navy sidebar (2026-11-09). The sidebar
 * now uses its own tokens (sidebar, sidebar-ink, sidebar-active) holding the
 * pre-D1 brand-950 / accent-600 values; the dropdowns keep the D1 colours.
 */
class AccentHoverColorTest extends TestCase
{
    /** The sidebar plus the partial that renders each of its links. */
    private function sidebarSource(): string
    {
        return file_get_contents(resource_path('views/components/layout/sidebar.blade.php'))
            .file_get_contents(resource_path('views/components/layout/sidebar-link.blade.php'));
    }

    public function test_the_sidebar_is_the_navy_panel_not_a_light_surface(): void
    {
        $html = $this->sidebarSource();

        $this->assertStringContainsString('bg-sidebar text-sidebar-ink', $html);
        $this->assertStringContainsString('hover:bg-sidebar-ink-strong/10 hover:text-sidebar-ink-strong', $html);
        $this->assertStringNotContainsString('bg-surface', $html);
        $this->assertStringNotContainsString('text-ink', $html);
    }

    public function test_the_sidebar_tokens_hold_the_original_navy_and_orange(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('--sidebar: 2 27 60;', $css); // brand-950 #021b3c
        $this->assertStringContainsString('--on-sidebar: 217 224 242;', $css); // brand-100 #d9e0f2
        $this->assertStringContainsString('--sidebar-active: 214 80 2;', $css); // accent-600 #d65002
    }

    public function test_the_active_nav_item_is_solid_orange_with_white_text(): void
    {
        $html = $this->sidebarSource();

        $this->assertStringContainsString("'bg-sidebar-active font-semibold text-sidebar-active-ink' => \$active", $html);
        $this->assertStringNotContainsString('bg-primary-soft', $html);
    }

    public function test_the_sidebar_never_uses_the_sale_orange(): void
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
