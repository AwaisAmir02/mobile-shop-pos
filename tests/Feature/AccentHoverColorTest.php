<?php

namespace Tests\Feature;

use Tests\TestCase;

class AccentHoverColorTest extends TestCase
{
    /** The sidebar plus the partial that renders each of its links. */
    private function sidebarSource(): string
    {
        return file_get_contents(resource_path('views/components/layout/sidebar.blade.php'))
            .file_get_contents(resource_path('views/components/layout/sidebar-link.blade.php'));
    }

    public function test_the_sidebar_no_longer_uses_the_old_blue_active_state_or_the_plain_gray_hover_state(): void
    {
        $html = $this->sidebarSource();

        $this->assertStringNotContainsString('bg-brand-50 text-brand-700', $html);
        $this->assertStringNotContainsString('hover:bg-slate-50 hover:text-slate-900', $html);
    }

    public function test_the_sidebar_uses_a_solid_accent_background_with_white_text_for_the_active_nav_item(): void
    {
        $html = $this->sidebarSource();

        $this->assertStringContainsString('bg-accent-600 text-white', $html);
        $this->assertStringNotContainsString('bg-accent-500 text-slate-900', $html);
    }

    public function test_the_navy_sidebar_uses_a_white_tint_hover_state_for_inactive_nav_items(): void
    {
        $html = $this->sidebarSource();

        $this->assertStringContainsString('bg-brand-950', $html);
        $this->assertStringContainsString('hover:bg-white/10 hover:text-white', $html);
    }

    public function test_the_select_dropdown_uses_a_solid_accent_background_for_the_highlighted_option(): void
    {
        $html = file_get_contents(resource_path('views/components/ui/select.blade.php'));

        $this->assertStringContainsString('bg-accent-500 text-white', $html);
        $this->assertStringNotContainsString("'bg-accent-50':", $html);
    }

    public function test_the_image_select_dropdown_uses_a_solid_accent_background_for_the_highlighted_option(): void
    {
        $html = file_get_contents(resource_path('views/components/ui/image-select.blade.php'));

        $this->assertStringContainsString('bg-accent-500 text-white', $html);
        $this->assertStringNotContainsString("'bg-accent-50':", $html);
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
