<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3, A: one education post. Plain text. Who sees it is decided in ONE
 * place, scopeVisibleTo(): a patient sees a PUBLISHED post of THEIR
 * nutritionist whose audience includes them. `audienceSubscribers()` is the
 * same rule seen from the other side (who the post is for), used for the push
 * and the stats.
 */
#[Fillable(['author_id', 'title', 'body', 'audience', 'condition_codes', 'status', 'pinned', 'published_at'])]
class NutritionistPost extends Model
{
    public const AUDIENCES = ['all', 'conditions', 'patients'];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const TITLE_MAX = 120;

    public const BODY_MAX = 2000;

    protected function casts(): array
    {
        return [
            'condition_codes' => 'array',
            'pinned' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function targets(): BelongsToMany
    {
        return $this->belongsToMany(Subscriber::class, 'nutritionist_post_targets', 'post_id', 'subscriber_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(NutritionistPostRead::class, 'post_id');
    }

    /** What a patient may see: published, by their nutritionist, addressed to them. */
    public function scopeVisibleTo(Builder $query, Subscriber $subscriber): void
    {
        $codes = PatientCondition::query()->where('subscriber_id', $subscriber->id)->pluck('code')->unique()->all();

        $query->where('status', self::STATUS_PUBLISHED)
            ->where('author_id', $subscriber->nutritionist_id)
            ->where(function (Builder $audience) use ($subscriber, $codes): void {
                $audience->where('audience', 'all')
                    ->orWhere(function (Builder $q) use ($codes): void {
                        $q->where('audience', 'conditions')->where(function (Builder $any) use ($codes): void {
                            // No recorded condition: nothing matches (an empty OR group is false).
                            $any->whereRaw('1 = 0');
                            foreach ($codes as $code) {
                                $any->orWhereJsonContains('condition_codes', $code);
                            }
                        });
                    })
                    ->orWhere(function (Builder $q) use ($subscriber): void {
                        // The pivot table directly: Subscriber's own global scope depends on who is calling.
                        $q->where('audience', 'patients')->whereIn('id', DB::table('nutritionist_post_targets')->select('post_id')->where('subscriber_id', $subscriber->id));
                    });
            });
    }

    /** Pinned first, then newest. */
    public function scopeForFeed(Builder $query): void
    {
        $query->orderByDesc('pinned')->orderByDesc('published_at')->orderByDesc('id');
    }

    /**
     * The active patients of the author this post is addressed to. Same
     * three-way rule as scopeVisibleTo(), from the post's side.
     *
     * @return Builder<Subscriber>
     */
    public function audienceSubscribers(): Builder
    {
        $query = Subscriber::withoutGlobalScopes()->where('nutritionist_id', $this->author_id)->active();

        return match ($this->audience) {
            'conditions' => $query->whereIn('id', PatientCondition::query()
                ->select('subscriber_id')
                ->where(function (Builder $q): void {
                    $q->whereRaw('1 = 0');
                    foreach ((array) $this->condition_codes as $code) {
                        $q->orWhere('code', $code);
                    }
                })),
            'patients' => $query->whereIn('id', DB::table('nutritionist_post_targets')->select('subscriber_id')->where('post_id', $this->id)),
            default => $query,
        };
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
