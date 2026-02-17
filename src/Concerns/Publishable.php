<?php

namespace Artworksit\Starter\Concerns;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Adds publish/draft/scheduled lifecycle to any Eloquent model.
 *
 * ## Setup checklist for a new model
 *
 * 1. Migration — add the column:
 *    ```php
 *    $table->publishable();          // adds published_at TIMESTAMP NULL INDEX
 *    // To remove it later:
 *    $table->dropPublishable();
 *    ```
 *
 * 2. Model — use the trait:
 *    ```php
 *    use Artworksit\Starter\Concerns\Publishable;
 *
 *    class MyModel extends Model
 *    {
 *        use Publishable;
 *
 *        protected $fillable = [...]; // published_at is merged in automatically
 *    }
 *    ```
 *    The trait's initializePublishable() merges published_at into $fillable and
 *    registers the datetime cast — no manual setup needed.
 *
 * 3. Controller — filter public listings with the scope, use publishedOrFail() for detail pages:
 *    ```php
 *    // Listing (public-only):
 *    MyModel::query()->published()->get();
 *
 *    // Detail (public + admin preview):
 *    MyModel::query()->where('slug', $slug)->publishedOrFail();
 *    ```
 *    publishedOrFail() returns the record normally if published, lets admins preview
 *    drafts/scheduled records, and aborts with 403 for everyone else.
 *
 * 4. Filament resource — add TogglePublishAction to the edit page actions:
 *    Copy the stubs from stubs/publishable/ into your app, then:
 *    ```php
 *    // In your EditResource page:
 *    protected function getHeaderActions(): array
 *    {
 *        return [
 *            TogglePublishAction::make(),
 *            Actions\DeleteAction::make(),
 *        ];
 *    }
 *    ```
 *    The action label, icon, and confirmation text update automatically based on current state.
 *
 * ## Status states
 *
 * | published_at        | Result of publishStatus() |
 * |---------------------|---------------------------|
 * | NULL                | draft                     |
 * | future timestamp    | scheduled                 |
 * | past timestamp      | published                 |
 * | past + until passed | expired                   |
 *
 * ## Optional: expiry support
 *
 * Add a `published_until` column and include it in $fillable. The trait checks it automatically.
 * ```php
 * $table->timestamp('published_until')->nullable();
 * protected $fillable = [..., 'published_at', 'published_until'];
 * ```
 */
trait Publishable
{
    public function initializePublishable(): void
    {
        $this->fillable = array_merge($this->fillable, [
            'published_at',
        ]);

        $this->mergeCasts([
            'published_at' => 'datetime',
            'published_until' => 'datetime',
        ]);
    }

    // -------------------- Scopes --------------------

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at');
    }

    public function scopeUnpublished(Builder $query): Builder
    {
        return $query->where(fn ($q) =>
            $q->whereNull('published_at')
                ->orWhere('published_at', '>', now())
        );
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->whereNull('published_at');
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query
            ->whereNotNull('published_at')
            ->where('published_at', '>', now());
    }

    // -------------------- Accessors --------------------

    public function isPublished(): bool
    {
        if (! $this->published_at) {
            return false;
        }

        if ($this->published_at->isFuture()) {
            return false;
        }

        if ($this->published_until && $this->published_until->isPast()) {
            return false;
        }

        return true;
    }

    public function isDraft(): bool
    {
        return is_null($this->published_at);
    }

    public function isScheduled(): bool
    {
        return $this->published_at && $this->published_at->isFuture();
    }

    // -------------------- Actions --------------------

    public function publish(?Carbon $when = null): static
    {
        $this->published_at = $when ?? now();
        $this->save();

        return $this;
    }

    public function unpublish(): static
    {
        $this->published_at = null;
        $this->save();

        return $this;
    }

    public function schedule(Carbon $publishAt, ?Carbon $publishUntil = null): static
    {
        $this->published_at = $publishAt;

        if ($publishUntil && in_array('published_until', $this->getFillable())) {
            $this->published_until = $publishUntil;
        }

        $this->save();

        return $this;
    }

    // -------------------- Status --------------------

    public function publishStatus(): string
    {
        if ($this->isDraft()) {
            return 'draft';
        }

        if ($this->isScheduled()) {
            return 'scheduled';
        }

        if ($this->isPublished()) {
            return 'published';
        }

        return 'expired';
    }

    // -------------------- Authorization --------------------

    public function isAccessibleByUser(): bool
    {
        if ($this->isPublished()) {
            return true;
        }

        return $this->userCanPreview();
    }

    protected function userCanPreview(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'canAccessPanel')) {
            try {
                $panel = \Filament\Facades\Filament::getPanel('admin');

                return $user->canAccessPanel($panel);
            } catch (\Exception) {
                return false;
            }
        }

        return false;
    }

    public function abortIfNotAccessible(int $code = 403): void
    {
        if (! $this->isAccessibleByUser()) {
            abort($code);
        }
    }
}
