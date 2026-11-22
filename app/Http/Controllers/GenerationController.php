<?php

namespace App\Http\Controllers;

use App\Billing\PricingCalculator;
use App\Enums\GenerationStatus;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Jobs\SubmitGeneration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function __construct(private readonly PricingCalculator $pricing) {}

    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()->latest('id')->paginate(12);

        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection($generations),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('generations/Create', [
            'options' => [
                'sizes' => config('pixelforge.sizes'),
                'qualities' => config('pixelforge.qualities'),
                'max_prompt_length' => config('pixelforge.max_prompt_length'),
                'max_reference_images' => config('pixelforge.max_reference_images'),
                'markup_percent' => config('pixelforge.markup_percent'),
            ],
        ]);
    }

    /**
     * Live price range for the form. Only the prompt's *length* affects the price,
     * so the page sends a character count rather than the whole prompt.
     */
    public function estimate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prompt_length' => ['required', 'integer', 'min:0', 'max:'.config('pixelforge.max_prompt_length')],
            'size' => ['required', Rule::in(config('pixelforge.sizes'))],
            'quality' => ['required', Rule::in(config('pixelforge.qualities'))],
            'reference_images' => ['required', 'integer', 'min:0', 'max:'.config('pixelforge.max_reference_images')],
        ]);

        $estimate = $this->pricing->estimate(
            str_repeat('x', (int) $data['prompt_length']),
            $data['size'],
            $data['quality'],
            (int) $data['reference_images'],
        );

        return response()->json($estimate->toArray());
    }

    public function store(StoreGenerationRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $references = $data['reference_images'] ?? [];
        $estimate = $this->pricing->estimate($data['prompt'], $data['size'], $data['quality'], count($references));

        $generation = $request->user()->generations()->create([
            'idempotency_key' => (string) Str::uuid(),
            'status' => GenerationStatus::Pending,
            'prompt' => $data['prompt'],
            'size' => $data['size'],
            'quality' => $data['quality'],
            'reference_images' => $references,
            'estimated_charge_min' => $estimate->chargeMin,
            'estimated_charge_max' => $estimate->chargeMax,
        ]);

        SubmitGeneration::dispatch($generation);

        return to_route('generations.index')->with('success', 'Generation submitted. Its status updates below as it progresses.');
    }

    /** Lightweight JSON for live status polling on the history page. */
    public function status(Request $request): JsonResponse
    {
        $request->validate(['ids' => ['required', 'array', 'max:50'], 'ids.*' => ['string']]);

        $generations = $request->user()->generations()->whereIn('public_id', $request->input('ids'))->get();

        return response()->json(['data' => GenerationResource::collection($generations)->resolve()]);
    }
}
