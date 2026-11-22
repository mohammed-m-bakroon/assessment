<?php

namespace App\Providers;

use App\Billing\PricingCalculator;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\WebhookSignature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PricingCalculator::class, fn () => PricingCalculator::fromConfig());

        $this->app->bind(PixelforgeClient::class, fn () => new PixelforgeClient(
            config('pixelforge.base_url'),
            config('pixelforge.api_key'),
        ));

        $this->app->bind(WebhookSignature::class, fn () => new WebhookSignature(
            config('pixelforge.webhook_secret'),
            (int) config('pixelforge.webhook_tolerance'),
        ));
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        Gate::define('view-admin', fn (User $user) => $user->is_admin);
    }
}
