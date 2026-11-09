<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Models;

use DbCms\Core\Content\Contracts\TranslatableEntity;
use DbCms\Core\Contracts\Services\LocalizationServiceInterface;
use DbCms\Core\Models\Market;
use DbCms\Core\Services\Content\PublicTranslation;
use DbCms\Core\Traits\MarketScoped;
use DbCms\Core\Traits\Translatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $parent_id
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Category|null $parent
 * @property-read Collection<int, Category> $children
 * @property-read Collection<int, Post> $posts
 * @property-read Collection<int, CategoryTranslation> $translations
 */
class Category extends Model implements TranslatableEntity
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory, Translatable;

    use MarketScoped;

    protected $fillable = [
        'parent_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CategoryTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(CategoryTranslation::class);
    }

    /**
     * @return BelongsToMany<Market, $this>
     */
    public function markets(): BelongsToMany
    {
        return $this->belongsToMany(Market::class, 'market_category');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * Build recursive slug path for this category (e.g. 'tech/ai/llms').
     */
    public function getSlugPath(string $locale, ?Market $market = null): string
    {
        $market ??= app()->bound('current.market') && app('current.market') instanceof Market ? app('current.market') : null;
        $slugs = [];
        $current = $this;

        while ($current !== null) {
            /** @var CategoryTranslation|null $trans */
            $trans = PublicTranslation::resolve($current, $locale, $market);

            // A category without a translation is not public in this language.
            if ($trans === null || empty($trans->slug)) {
                return '';
            }

            array_unshift($slugs, $trans->slug);

            if ($current->parent_id === null) {
                break;
            }

            $current = $current->relationLoaded('parent')
                ? $current->parent
                : self::with('translations')->find($current->parent_id);
        }

        return implode('/', $slugs);
    }

    /**
     * Build full breadcrumb trail array for this category.
     *
     * @return array<int, array{title: string, url: string}>
     */
    public function getBreadcrumbTrail(string $locale, ?Market $market = null): array
    {
        /** @var LocalizationServiceInterface $localization */
        $localization = app(LocalizationServiceInterface::class);
        $blogSlug = $localization->getModulePrefix('blog', $locale);
        $trail = [];

        // 1. Home
        $trail[] = [
            'title' => $locale === 'es' ? 'Inicio' : ($locale === 'ca' ? 'Inici' : 'Home'),
            'url' => $localization->url('/', $market),
        ];

        // 2. Blog Hub
        $trail[] = [
            'title' => 'Blog',
            'url' => $localization->url("/{$blogSlug}", $market),
        ];

        // 3. Ancestor Categories
        $ancestors = [];
        $current = $this;
        while ($current !== null) {
            $ancestors[] = $current;
            $current = $current->parent;
        }
        $ancestors = array_reverse($ancestors);

        foreach ($ancestors as $ancestor) {
            /** @var CategoryTranslation|null $trans */
            $trans = PublicTranslation::resolve($ancestor, $locale, $market);
            if ($trans === null) {
                continue;
            }

            $ancestorPath = $ancestor->getSlugPath($locale, $market);
            if ($ancestorPath === '') {
                continue;
            }

            $trail[] = [
                'title' => $trans->name ?? 'Categoría',
                'url' => $localization->url("/{$blogSlug}/{$ancestorPath}", $market),
            ];
        }

        return $trail;
    }

    /**
     * Resolve a Category model from a nested slug path (e.g. 'tecnologia/ia/llms').
     */
    public static function resolveBySlugPath(string $slugPath, string $locale, ?Market $market = null): ?self
    {
        $segments = array_filter(explode('/', trim($slugPath, '/')));
        if (empty($segments)) {
            return null;
        }

        $leafSlug = end($segments);

        // Find categories having translation with leaf slug
        $candidates = self::where('is_active', true)->forMarket($market)->whereHas('translations', function ($q) use ($leafSlug, $locale): void {
            $q->where('slug', $leafSlug)->where('locale', $locale);
        })->with(['translations', 'parent.translations'])->get();

        foreach ($candidates as $candidate) {
            if ($candidate->getSlugPath($locale, $market) === trim($slugPath, '/')) {
                return $candidate;
            }
        }

        // Fallback check on leaf slug directly
        return $candidates->first();
    }
}
