<?php

namespace App\Livewire\Concerns;

use App\Actions\Ai\ScanIngredientLabel;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * For forms with an `ingredients_text` box: a label photo is read by the AI into
 * the box, for the user to review before saving (D-020, D-051). The photo is
 * deleted right after, whatever happens. Pair with <x-ingredient-label-scan />.
 */
trait ScansIngredientLabels
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $labelPhoto = null;

    public function updatedLabelPhoto(ScanIngredientLabel $scan): void
    {
        $this->resetValidation('labelPhoto');
        $photo = $this->labelPhoto;

        if ($photo === null) {
            return;
        }

        try {
            $this->validate(['labelPhoto' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
            $this->ingredients_text = $scan->handle(Auth::user(), (string) $photo->get(), (string) $photo->getMimeType());
        } finally {
            $photo->delete();
            $this->labelPhoto = null;
        }
    }
}
