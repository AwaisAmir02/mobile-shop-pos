<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * App screens should colour themselves with the design tokens (surface, ink,
 * primary, danger, ...) rather than palette colours (slate-500, white, red-600),
 * so that dark mode can be added later without revisiting every screen
 * (docs/design-system.md).
 *
 * Screens are converted stage by stage, so this is a ratchet: the count of raw
 * palette classes may only go down. A stage that lowers it must lower BUDGET to
 * the new count in the same commit, which this test enforces.
 *
 * Not counted: the marketing site, PDFs and emails, which stay light whatever the
 * app's theme.
 */
class DesignTokenRatchetTest extends TestCase
{
    private const BUDGET = 1514;

    /** The shared kit and shell must already be fully on tokens, apart from these. */
    private const KIT = [
        'components/ui',
        'components/layout',
        'livewire/layout',
        'layouts',
        'vendor/livewire',
    ];

    private const KIT_EXCEPTIONS = [
        // A swatch's outline has to stay dark against any user-picked colour.
        'components/ui/color-dot.blade.php' => ['ring-black/10'],
        'components/ui/image-select.blade.php' => ['ring-black/10', 'ring-black/10'],
        // The logo is drawn for a white tile, and the wordmark's orange O is the logo.
        'components/layout/sidebar.blade.php' => ['bg-white', 'text-accent-500'],
    ];

    private const NOT_COUNTED = ['/site/', '/pdf/', '/emails/', 'sitemap.blade.php'];

    private const PATTERN = '/(?<![\w-])(?:[a-z0-9-]+:)*(?:text|bg|border|ring|ring-offset|divide|placeholder|from|via|to|fill|stroke|outline|decoration|caret|shadow)-(?:slate|gray|zinc|neutral|stone|white|black|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|brand|accent)(?:-\d{2,3})?(?:\/\d+)?(?![\w-])/';

    public function test_raw_palette_colours_in_app_views_only_go_down(): void
    {
        $total = array_sum(array_map('count', $this->rawColoursByFile()));

        $this->assertLessThanOrEqual(self::BUDGET, $total, "App views now use $total raw palette colour classes, over the budget of ".self::BUDGET.'. Use the design tokens instead (docs/design-system.md).');
        $this->assertSame(self::BUDGET, $total, "Raw palette colours are down to $total. Lower DesignTokenRatchetTest::BUDGET to $total so it can't creep back up.");
    }

    public function test_the_shared_kit_and_shell_use_only_tokens(): void
    {
        foreach ($this->rawColoursByFile() as $file => $classes) {
            $inKit = collect(self::KIT)->contains(fn ($dir) => str_starts_with($file, $dir.'/'));

            if (! $inKit) {
                continue;
            }

            $this->assertSame(self::KIT_EXCEPTIONS[$file] ?? [], $classes, "$file uses raw palette colours; use the design tokens instead.");
        }
    }

    /**
     * @return array<string, list<string>> view path relative to resources/views => raw classes found
     */
    private function rawColoursByFile(): array
    {
        $root = str_replace('\\', '/', resource_path('views')).'/';
        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (! str_ends_with($path, '.blade.php') || collect(self::NOT_COUNTED)->contains(fn ($skip) => str_contains($path, $skip))) {
                continue;
            }

            if (preg_match_all(self::PATTERN, file_get_contents($path), $matches)) {
                $found[substr($path, strlen($root))] = array_map(fn ($class) => preg_replace('/^(?:[a-z0-9-]+:)+/', '', $class), $matches[0]);
            }
        }

        return $found;
    }
}
