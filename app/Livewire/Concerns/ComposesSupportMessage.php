<?php

namespace App\Livewire\Concerns;

/**
 * The message box shared by "Report a problem" and both sides of a thread:
 * text plus up to config('support.max_images') photos. Photos picked in
 * several goes add up instead of replacing each other.
 *
 * The using component must also use Livewire\WithFileUploads.
 */
trait ComposesSupportMessage
{
    public string $body = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $images = [];

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $newImages = [];

    protected function imageRules(): array
    {
        return ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('support.image_max_kb')];
    }

    public function updatedNewImages(): void
    {
        $this->validate(['newImages.*' => $this->imageRules()], [
            'newImages.*.image' => 'Only photos can be attached (JPG, PNG or WebP).',
            'newImages.*.mimes' => 'Only photos can be attached (JPG, PNG or WebP).',
            'newImages.*.max' => 'Each photo can be up to '.(int) (config('support.image_max_kb') / 1024).' MB.',
        ]);

        $max = config('support.max_images');
        $merged = array_merge($this->images, array_values($this->newImages));

        if (count($merged) > $max) {
            $this->addError('images', 'Up to '.$max.' photos per message. The extra ones were left out.');
        }

        $this->images = array_slice($merged, 0, $max);
        $this->newImages = [];
    }

    public function removeImage(int $index): void
    {
        unset($this->images[$index]);
        $this->images = array_values($this->images);
        $this->resetErrorBag('images');
    }

    /**
     * @param  bool  $bodyRequired  Opening a ticket needs words; a reply may be photos only.
     */
    protected function validateComposer(bool $bodyRequired): void
    {
        $this->validate([
            'body' => [$bodyRequired ? 'required' : 'required_without:images', 'nullable', 'string', 'max:'.config('support.message_max_chars')],
            'images' => ['array', 'max:'.config('support.max_images')],
            'images.*' => $this->imageRules(),
        ], [
            'body.required' => 'Please describe the problem.',
            'body.required_without' => 'Write a message or add a photo.',
            'images.max' => 'Up to '.config('support.max_images').' photos per message.',
        ]);
    }

    protected function resetComposer(): void
    {
        $this->reset(['body', 'images', 'newImages']);
        $this->resetValidation();
    }
}
