<?php

namespace App\Services\Marketing;

use App\Models\CustomerReview;
use App\Models\Product;
use App\Models\Shop;
use GdImage;
use Illuminate\Support\Facades\Storage;

class CardRenderer
{
    public const SQUARE = [1080, 1080];

    public const STORY = [1080, 1920];

    protected const BRAND = [15, 118, 110];

    protected const INK = [15, 23, 42];

    protected const MUTED = [100, 116, 139];

    protected const PAPER = [248, 250, 252];

    protected function font(bool $bold = false): string
    {
        return base_path('vendor/dompdf/dompdf/lib/fonts/'.($bold ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf'));
    }

    public function productCards(Shop $shop, Product $product, string $footer, string $directory): array
    {
        return [
            'square' => $this->store($this->renderProduct($shop, $product, $footer, self::SQUARE), $directory, 'square'),
            'story' => $this->store($this->renderProduct($shop, $product, $footer, self::STORY), $directory, 'story'),
        ];
    }

    public function reviewCards(Shop $shop, CustomerReview $review, string $footer, string $directory): array
    {
        return [
            'square' => $this->store($this->renderReview($shop, $review, $footer, self::SQUARE), $directory, 'square'),
            'story' => $this->store($this->renderReview($shop, $review, $footer, self::STORY), $directory, 'story'),
        ];
    }

    protected function store(GdImage $image, string $directory, string $variant): string
    {
        ob_start();
        imagejpeg($image, null, 88);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        $path = trim($directory, '/').'/'.$variant.'-'.substr(sha1($jpeg), 0, 12).'.jpg';
        Storage::disk('public')->put($path, $jpeg);

        return $path;
    }

    protected function canvas(array $size): array
    {
        [$width, $height] = $size;
        $image = imagecreatetruecolor($width, $height);
        imageantialias($image, true);
        imagefill($image, 0, 0, $this->color($image, self::PAPER));

        return [$image, $width, $height];
    }

    protected function renderProduct(Shop $shop, Product $product, string $footer, array $size): GdImage
    {
        [$image, $width, $height] = $this->canvas($size);
        $isStory = $height > $width;
        $brand = $this->color($image, self::BRAND);
        $white = $this->color($image, [255, 255, 255]);

        $bandHeight = $isStory ? 200 : 130;
        imagefilledrectangle($image, 0, 0, $width, $bandHeight, $brand);
        $this->centeredText($image, $shop->invoiceDisplayName(), $isStory ? 52 : 44, $width, (int) ($bandHeight / 2) + 18, $white, true);

        $boxTop = $bandHeight + 40;
        $boxHeight = $isStory ? 980 : 520;
        $this->placeProductImage($image, $product, 60, $boxTop, $width - 120, $boxHeight);

        $y = $boxTop + $boxHeight + ($isStory ? 110 : 70);
        foreach ($this->wrap($product->name, $isStory ? 60 : 46, $width - 140, true, 2) as $line) {
            $this->centeredText($image, $line, $isStory ? 60 : 46, $width, $y, $this->color($image, self::INK), true);
            $y += $isStory ? 80 : 60;
        }

        $price = 'Rs '.number_format($product->currentSellingPrice());
        $this->pill($image, $price, $isStory ? 84 : 64, $width, $y + ($isStory ? 70 : 40), $brand, $white);

        $this->footer($image, $footer, $width, $height, $isStory);

        return $image;
    }

    protected function renderReview(Shop $shop, CustomerReview $review, string $footer, array $size): GdImage
    {
        [$image, $width, $height] = $this->canvas($size);
        $isStory = $height > $width;
        $brand = $this->color($image, self::BRAND);
        $ink = $this->color($image, self::INK);
        $muted = $this->color($image, self::MUTED);
        $gold = $this->color($image, [245, 158, 11]);

        $y = $isStory ? 420 : 180;
        $this->centeredText($image, 'What our customers say', $isStory ? 46 : 38, $width, $y, $muted, true);
        $y += $isStory ? 150 : 120;
        $this->centeredText($image, $review->stars(), $isStory ? 90 : 72, $width, $y, $gold, false);
        $y += $isStory ? 150 : 110;

        $comment = $review->comment ? '“'.$review->comment.'”' : '“Great service!”';
        foreach ($this->wrap($comment, $isStory ? 52 : 42, $width - 180, false, $isStory ? 9 : 6) as $line) {
            $this->centeredText($image, $line, $isStory ? 52 : 42, $width, $y, $ink, false);
            $y += $isStory ? 76 : 60;
        }

        $y += $isStory ? 60 : 40;
        $this->centeredText($image, '— '.$review->publicName(), $isStory ? 46 : 38, $width, $y, $brand, true);

        $this->centeredText($image, $shop->invoiceDisplayName(), $isStory ? 48 : 40, $width, $height - ($isStory ? 300 : 200), $ink, true);
        $this->footer($image, $footer, $width, $height, $isStory);

        return $image;
    }

    protected function placeProductImage(GdImage $image, Product $product, int $x, int $y, int $boxWidth, int $boxHeight): void
    {
        $source = null;

        if ($product->image_path && Storage::disk('public')->exists($product->image_path)) {
            $source = @imagecreatefromstring(Storage::disk('public')->get($product->image_path)) ?: null;
        }

        if ($source === null) {
            imagefilledrectangle($image, $x, $y, $x + $boxWidth, $y + $boxHeight, $this->color($image, [226, 232, 240]));
            $this->centeredText($image, mb_strtoupper(mb_substr($product->name, 0, 1)), 220, $x * 2 + $boxWidth, $y + (int) ($boxHeight / 2) + 80, $this->color($image, self::MUTED), true);

            return;
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = min($boxWidth / $sourceWidth, $boxHeight / $sourceHeight);
        $targetWidth = (int) ($sourceWidth * $scale);
        $targetHeight = (int) ($sourceHeight * $scale);

        imagefilledrectangle($image, $x, $y, $x + $boxWidth, $y + $boxHeight, $this->color($image, [255, 255, 255]));
        imagecopyresampled($image, $source, $x + (int) (($boxWidth - $targetWidth) / 2), $y + (int) (($boxHeight - $targetHeight) / 2), 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
        imagedestroy($source);
    }

    protected function footer(GdImage $image, string $text, int $width, int $height, bool $isStory): void
    {
        $bandHeight = $isStory ? 170 : 110;
        imagefilledrectangle($image, 0, $height - $bandHeight, $width, $height, $this->color($image, self::INK));
        $this->centeredText($image, $text, $isStory ? 40 : 32, $width, $height - (int) ($bandHeight / 2) + 14, $this->color($image, [255, 255, 255]), true);
    }

    protected function pill(GdImage $image, string $text, int $size, int $width, int $baseline, int $background, int $foreground): void
    {
        $box = imagettfbbox($size, 0, $this->font(true), $text);
        $textWidth = $box[2] - $box[0];
        $padding = (int) ($size * 0.6);
        $left = (int) (($width - $textWidth) / 2) - $padding;
        $right = (int) (($width + $textWidth) / 2) + $padding;
        imagefilledrectangle($image, $left, (int) ($baseline - $size - $padding / 2), $right, $baseline + (int) ($padding * 0.8), $background);
        $this->centeredText($image, $text, $size, $width, $baseline, $foreground, true);
    }

    protected function centeredText(GdImage $image, string $text, int $size, int $width, int $baseline, int $color, bool $bold): void
    {
        $box = imagettfbbox($size, 0, $this->font($bold), $text);

        while ($size > 14 && ($box[2] - $box[0]) > $width - 80) {
            $size -= 2;
            $box = imagettfbbox($size, 0, $this->font($bold), $text);
        }

        $textWidth = $box[2] - $box[0];
        imagettftext($image, $size, 0, (int) (($width - $textWidth) / 2), $baseline, $color, $this->font($bold), $text);
    }

    protected function wrap(string $text, int $size, int $maxWidth, bool $bold, int $maxLines): array
    {
        $words = preg_split('/\s+/u', trim($text));
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            $box = imagettfbbox($size, 0, $this->font($bold), $candidate);

            if ($box[2] - $box[0] > $maxWidth && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = rtrim(mb_substr($lines[$maxLines - 1], 0, max(1, mb_strlen($lines[$maxLines - 1]) - 1))).'…';
        }

        return $lines;
    }

    protected function color(GdImage $image, array $rgb): int
    {
        return imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
    }
}
