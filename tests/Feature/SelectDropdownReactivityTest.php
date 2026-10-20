<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * PHPUnit has no JS engine, so this cannot prove the Alpine reactivity bug
 * is fixed in a real browser — it only guards against the exact regression
 * that caused it: selectedLabel (and anything else deciding what the button
 * shows) reading a plain DOM property through $refs instead of a tracked
 * Alpine reactive property. Reading $refs.x.value is never reactive in
 * Alpine — Alpine's effects only re-run when a dependency read through its
 * own reactive proxy changes, and a raw DOM element accessed via $refs is
 * not wrapped by that proxy. See select.blade.php's value/syncValue().
 */
class SelectDropdownReactivityTest extends TestCase
{
    public function test_selected_label_is_derived_from_the_reactive_value_property_not_a_raw_dom_ref_read(): void
    {
        $html = file_get_contents(resource_path('views/components/ui/select.blade.php'));

        $this->assertStringContainsString("this.options.find((o) => o.value === this.value)", $html);
        $this->assertStringNotContainsString('o.value === this.$refs.native.value', $html);
    }

    public function test_selecting_an_option_writes_the_reactive_value_property_directly(): void
    {
        $html = file_get_contents(resource_path('views/components/ui/select.blade.php'));

        $selectMethod = substr($html, strpos($html, 'select(option) {'));
        $selectMethod = substr($selectMethod, 0, strpos($selectMethod, "\n        },"));

        $this->assertStringContainsString('this.value = option.value', $selectMethod);
    }

    public function test_opening_the_dropdown_resyncs_the_reactive_value_from_the_native_select(): void
    {
        $html = file_get_contents(resource_path('views/components/ui/select.blade.php'));

        $openList = substr($html, strpos($html, 'openList() {'));
        $openList = substr($openList, 0, strpos($openList, "\n        },"));

        $this->assertStringContainsString('this.syncValue()', $openList);
    }

    public function test_the_native_selects_own_change_event_also_keeps_the_reactive_value_in_sync(): void
    {
        $html = file_get_contents(resource_path('views/components/ui/select.blade.php'));

        $this->assertStringContainsString("addEventListener('change', () => syncValue())", $html);
    }

    public function test_image_select_already_reads_the_live_wire_property_directly_and_is_unaffected(): void
    {
        $html = file_get_contents(resource_path('views/components/ui/image-select.blade.php'));

        $this->assertStringContainsString('o.value === $wire.', $html);
        $this->assertStringNotContainsString('refs.native', $html);
    }
}
