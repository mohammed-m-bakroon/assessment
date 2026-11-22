<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Mirrors Pixelforge's own validation, so bad input fails here, before it costs a request. */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:'.config('pixelforge.max_prompt_length')],
            'size' => ['required', Rule::in(config('pixelforge.sizes'))],
            'quality' => ['required', Rule::in(config('pixelforge.qualities'))],
            'reference_images' => ['array', 'max:'.config('pixelforge.max_reference_images')],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'reference_images.*.url' => 'Each reference image must be an http(s) URL.',
            'reference_images.max' => 'You can add up to :max reference images.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Drop empty URL rows from the form.
        $urls = array_map(fn ($url) => is_string($url) ? trim($url) : $url, (array) $this->input('reference_images', []));

        $this->merge([
            'reference_images' => array_values(array_filter($urls, fn ($url) => $url !== '' && $url !== null)),
        ]);
    }
}
