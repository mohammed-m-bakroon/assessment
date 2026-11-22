# Image Studio

A Laravel 12 + Vue 3 + Inertia app where users generate images through the
[Pixelforge](https://pixelforge.promptedit.dev/docs) API and pay Pixelforge's cost plus 25%.

## Running locally

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate
composer run dev          # web server, queue worker and Vite
php artisan schedule:work # in a second terminal: polling fallback for missed webhooks
```

Set `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` in `.env`. Pixelforge needs a public
webhook URL, so point a tunnel (ngrok, Expose, Cloudflare Tunnel) at the app and set
`https://<tunnel>/webhooks/pixelforge` as the key's webhook URL in the Pixelforge dashboard.
Use a separate API key per environment.

```bash
php artisan test
```

## How it works

```
Generate page ──POST──▶ GenerationController@store ── saves "pending" + estimate
                                    │
                                    ▼
                          SubmitGeneration job ──POST /v1/generations (Idempotency-Key)──▶ Pixelforge
                                    │                                                        │
                                    ▼                                                        │ webhooks
                          GenerationSynchronizer::apply() ◀── PixelforgeWebhookController ◀──┘
                                    ▲
                                    └──── pixelforge:sync-stale (every minute, polls GET /v1/generations/{id})
```

Every status or cost change goes through **`GenerationSynchronizer::apply()`**, whether it comes
from a webhook, the submit response or polling.

## Decisions

### Money
- **All money is integer nano-dollars** (1 USD = 10⁹). Pixelforge reports exact costs with 9
  decimals, so every provider cost fits exactly. No floats anywhere; the UI receives decimal
  strings and only formats them.
- **Provider cost** = Σ tokens × rate / 1M, using the `rates` returned on *that generation*
  (they're frozen at creation), never our config. **Charge** = cost × 1.25. With Pixelforge's
  published rates both are always whole nano-dollars. If a future rate weren't, we would round up
  so we never charge less than we pay.
- **We store the usage and rates** on each generation, so every charge can be audited and recomputed.
- **Failed generations cost 0.** Pixelforge doesn't bill them either.
- **Display:** full precision with trailing zeros trimmed (e.g. `$0.3818875`). Rounding to cents
  would hide real differences between generations that cost fractions of a cent.

### Estimates
Output tokens and reference-image tokens are known exactly up front. Text tokens are ±15% of
`ceil(chars / 4)`, and reasoning tokens are only known afterwards (a range per quality). The
generate page shows the **minimum–maximum range**, updated as you type, with a token breakdown.
The range is saved with the generation, so history shows the estimate next to the actual charge.

### Never charged twice
1. The webhook `event_id` has a unique index. A processed duplicate is acknowledged and skipped.
2. Independently of that, `apply()` locks the generation row and **only moves status forward**
   (`pending → queued → processing → succeeded|failed`). Cost is written only on the single
   transition into a final state. A duplicate or late event (for example `processing` after
   `succeeded`) changes nothing, even if it arrives under a different event id.

### Robustness
- **Submissions are queued, not done in the request.** On `429` (rate or concurrency limit),
  `503 overloaded`, 5xx or a network error, the job releases itself, honouring `Retry-After`
  plus jitter. The UI shows "Waiting for capacity" with the reason.
- **Every retry sends the same `Idempotency-Key`**, so a retry after a timeout can't create or
  bill a second generation.
- **Non-retryable rejections** (`content_policy_violation`, `validation_failed`,
  `daily_limit_exceeded`) fail the generation immediately, free. After 30 minutes without being
  accepted we give up, also free.
- **Our own id is sent as `metadata.generation_id`**, so a webhook that arrives *before* the
  create request returns is still matched.
- **If we gave up on a submit that Pixelforge actually received** (e.g. every attempt timed
  out) and Pixelforge later reports it, Pixelforge's record wins. Our costs then match what we're billed.
- **Webhook delivery isn't guaranteed**, so `pixelforge:sync-stale` polls generations that have
  been in flight for over 60 seconds without news.
- **Webhook signatures** are an HMAC-SHA256 of `t.raw_body`, checked with `hash_equals` and a
  5-minute timestamp tolerance. The endpoint sits outside the `web` middleware group (no
  session/CSRF) and replies within Pixelforge's 10-second timeout.
- **Expired image links** (after 24h) show a clear "link expired" state instead of a broken image.

## Layout

| Path | What |
|---|---|
| `app/Billing/` | `Money`, `PricingCalculator`, `TokenUsage`, `Estimate` |
| `app/Pixelforge/` | API client, errors, webhook signature, `GenerationSynchronizer` |
| `app/Jobs/SubmitGeneration.php` | Submission with retries |
| `app/Http/Controllers/Webhooks/` | Webhook endpoint |
| `app/Console/Commands/SyncStaleGenerations.php` | Polling fallback |
| `config/pixelforge.php` | Connection settings and published pricing tables |
| `resources/js/pages/generations/` | Generate and History pages |
