<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Blocks;

use DbCms\Core\Blocks\AbstractBlockType;
use DbCms\Core\Blocks\Contracts\SearchableBlockReferencesInterface;
use DbCms\Core\Content\Contracts\PublicWidgetSemanticContributorInterface;
use DbCms\Core\Contracts\Services\LocalizationServiceInterface;
use DbCms\Core\Contracts\Services\PublicContentDiscoveryInterface;
use DbCms\Core\Models\Market;
use DbCms\Core\Sections\SectionLayout;
use DbCms\Core\Services\Cms\FrontViewResolver;
use DbCms\Core\Services\Content\PublicTranslation;
use DbCms\Modules\Blog\Models\Category;
use DbCms\Modules\Blog\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class BlogPostsBlock extends AbstractBlockType implements PublicWidgetSemanticContributorInterface, SearchableBlockReferencesInterface
{
    public function getType(): string
    {
        return 'widget_posts';
    }

    public function getName(): string
    {
        return 'Artículos del Blog';
    }

    public function getIcon(): string
    {
        return 'fa-solid fa-newspaper';
    }

    public function getCategory(): string
    {
        return 'dynamic';
    }

    public function getDescription(): string
    {
        return 'Listado dinámico de artículos recientes, filtrado por categoría o destacado individual.';
    }

    public function getRequiredModule(): ?string
    {
        return 'blog';
    }

    /**
     * The block manages its own grid: it is added as a section, never nested
     * inside a column (a grid inside a column renders as a grid inside a grid).
     */
    public function isFullWidth(): bool
    {
        return true;
    }

    /** @return list<string> */
    public function getTranslationPaths(): array
    {
        return ['title'];
    }

    public function getEditorData(): array
    {
        return [
            'blog_categories' => Category::with('translations')->get()->map(fn ($category): array => ['id' => $category->id, 'name' => $category->translations->first()->name ?? (string) $category->id, 'slug' => $category->translations->first()->slug ?? ''])->all(),
            'blog_posts' => Post::published()->with('translations')->latest()->limit(20)->get()->map(fn ($post): array => ['id' => $post->id, 'title' => $post->translations->first()->title ?? (string) $post->id, 'slug' => $post->translations->first()->slug ?? ''])->all(),
        ];
    }

    public function getContextualEditorData(Market $market, string $locale): array
    {
        $discovery = app(PublicContentDiscoveryInterface::class);
        /** @var Builder<Category> $categories */
        $categories = $discovery->query('blog_category', $market, $locale);
        /** @var Builder<Post> $items */
        $items = $discovery->query('post', $market, $locale);

        return [
            'blog_categories' => array_values($categories->orderBy('id')->get()->map(static function (Category $category) use ($market, $locale): array {
                $translation = PublicTranslation::resolve($category, $locale, $market);

                return ['id' => $category->id, 'name' => (string) $translation?->getAttribute('name'), 'slug' => (string) $translation?->getAttribute('slug')];
            })->all()),
            'blog_posts' => array_values($items->orderBy('id')->get()->map(static function (Post $item) use ($market, $locale): array {
                $translation = PublicTranslation::resolve($item, $locale, $market);

                return ['id' => $item->id, 'title' => (string) $translation?->getAttribute('title'), 'slug' => (string) $translation?->getAttribute('slug')];
            })->all()),
        ];
    }

    /** @return array{items:list<array<string,mixed>>,has_more:bool} */
    public function searchReferences(Market $market, string $locale, string $source, string $search, int $limit, int $offset): array
    {
        [$type, $label] = $this->referenceSource($source);
        $query = app(PublicContentDiscoveryInterface::class)->query($type, $market, $locale);
        PublicTranslation::constrainResolvedField($query, $market, $locale, 'slug', 'like', '_%');
        if (trim($search) !== '') {
            PublicTranslation::constrainResolvedField($query, $market, $locale, $label, 'like', '%'.trim($search).'%');
        }
        $limit = max(1, min(50, $limit));
        $records = $query->orderBy($query->getModel()->getQualifiedKeyName())->offset(max(0, $offset))->limit($limit + 1)->get();
        $hasMore = $records->count() > $limit;
        $items = [];
        foreach ($records->take($limit) as $record) {
            $translation = PublicTranslation::resolve($record, $locale, $market);
            $items[] = ['id' => $record->getKey(), $label => (string) $translation?->getAttribute($label), 'slug' => (string) $translation?->getAttribute('slug')];
        }

        return ['items' => $items, 'has_more' => $hasMore];
    }

    public function referenceExists(Market $market, string $locale, string $source, string $key, string|int $value): bool
    {
        [$type, , $valueKey] = $this->referenceSource($source);
        if ($key !== $valueKey) {
            throw ValidationException::withMessages(['references' => 'Campo de referencia no admitido.']);
        }
        $query = app(PublicContentDiscoveryInterface::class)->query($type, $market, $locale);
        PublicTranslation::constrainResolvedField($query, $market, $locale, 'slug', 'like', '_%');
        if ($key === 'id') {
            return $query->whereKey($value)->exists();
        }
        PublicTranslation::constrainResolvedField($query, $market, $locale, 'slug', '=', $value);

        return $query->exists();
    }

    /** @return array{string,string,string} */
    private function referenceSource(string $source): array
    {
        $sources = ['blog_categories' => ['blog_category', 'name', 'id'], 'blog_posts' => ['post', 'title', 'id']];

        return $sources[$source] ?? throw ValidationException::withMessages(['references' => 'Origen de referencias no admitido.']);
    }

    public function getEditorSchema(): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Título', 'description' => 'Título opcional sobre la lista de artículos.'],

            'selection' => ['type' => 'select', 'label' => 'Selección', 'options' => ['latest' => 'Recientes', 'featured' => 'Destacados'], 'default' => 'latest', 'description' => 'Orden o filtro de los artículos cuando no eliges uno concreto.', 'show_when' => ['field' => 'post_id', 'falsy' => true]],
            'category' => ['type' => 'select', 'label' => 'Categoría', 'options' => ['' => 'Todas'], 'options_source' => 'blog_categories', 'option_label' => 'name', 'option_value' => 'id', 'rules' => ['nullable', 'integer', 'min:1'], 'description' => 'Limita la lista a una categoría.', 'show_when' => ['field' => 'post_id', 'falsy' => true]],
            'post_id' => ['type' => 'select', 'label' => 'Artículo', 'options' => ['' => 'Selección automática'], 'options_source' => 'blog_posts', 'option_label' => 'title', 'option_value' => 'id', 'rules' => ['nullable', 'integer', 'min:1'], 'description' => 'Si eliges un artículo concreto se ignoran selección, categoría y límite.'],
            'limit' => ['type' => 'integer', 'label' => 'Límite', 'default' => 3, 'rules' => ['nullable', 'integer', 'between:1,50'], 'description' => 'Número máximo de artículos.', 'show_when' => ['field' => 'post_id', 'falsy' => true]],
            'grid_columns' => ['type' => 'select', 'label' => 'Columnas', 'options' => ['2', '3', '4'], 'default' => '3', 'description' => 'Columnas de la rejilla en pantallas grandes.'],
        ];
    }

    public function getDefaultPayload(): array
    {
        return [
            'title' => 'Últimos Artículos del Blog',
            'selection' => 'latest',
            'category' => '',
            'post_id' => '',
            'limit' => 3,
            'grid_columns' => '3',
        ];
    }

    public function keys(): array
    {
        return [$this->getType()];
    }

    public function ownerModule(): string
    {
        return 'blog';
    }

    public function text(array $payload, Market $market, string $locale): string
    {
        $text = '';
        foreach ($this->items($payload, $market, $locale) as $item) {
            $text .= '- ['.strip_tags($item['title']).']('.$item['url'].")\n";
        }

        return $text;
    }

    /** @param array<string,mixed> $payload
     * @return array<int,array<string,string>>
     */
    private function items(array $payload, Market $market, string $locale): array
    {
        $discovery = app(PublicContentDiscoveryInterface::class);
        /** @var Builder<Post> $query */
        $query = $discovery->query('post', $market, $locale, ['category.translations']);
        $specific = $payload['post_id'] ?? '';
        $category = (string) ($payload['category'] ?? '');
        if ($specific !== '') {
            $query->whereKey($specific);
        } elseif ($category !== '') {
            $query->whereHas('category', fn ($categories) => $categories->where('is_active', true)->whereKey($category));
        }
        if (($payload['selection'] ?? 'latest') === 'featured') {
            $query->where('is_featured', true);
        }
        $limit = min(50, max(1, (int) ($payload['limit'] ?? 3)));

        return $query->latest('published_at')->orderByDesc('id')->limit($limit)->get()->map(function (Post $entity) use ($discovery, $market, $locale): ?array {
            $translation = PublicTranslation::resolve($entity, $locale, $market);
            $slug = (string) ($translation?->getAttribute('slug') ?? '');
            if ($slug === '') {
                return null;
            }
            $categoryTranslation = $entity->category ? PublicTranslation::resolve($entity->category, $locale, $market) : null;

            return [
                'title' => (string) $translation?->getAttribute('title'),
                'summary' => (string) $translation?->getAttribute('summary'),
                'image' => (string) $entity->featured_image,
                'url' => $discovery->url('post', $market, $locale, $slug),
                'badge' => (string) ($categoryTranslation?->getAttribute('name') ?? ''),
                'cta_label' => 'Leer artículo',
            ];
        })->filter()->values()->all();
    }

    public function render(array $payload, ?Market $market = null, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $market ??= app()->bound('current.market') ? app('current.market') : app(PublicContentDiscoveryInterface::class)->markets()->firstWhere('is_default', true);
        if (! $market instanceof Market) {
            return '';
        }
        $view = app(FrontViewResolver::class)->resolve('blog.blocks.posts', 'blog::blocks.posts');

        return view($view, [
            'items' => $this->items($payload, $market, $locale),
            'title' => (string) ($payload['title'] ?? ''),

            'columns' => SectionLayout::gridColumns($payload, '3'),
            'indexUrl' => app(LocalizationServiceInterface::class)->frontUrl('blog', '', $locale, $market),
        ])->render();
    }
}
