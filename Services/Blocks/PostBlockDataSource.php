<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Services\Blocks;

use DbCms\Core\Blocks\Contracts\BlockDataSourceInterface;
use DbCms\Core\Contracts\Services\PublicContentDiscoveryInterface;
use DbCms\Core\Models\Market;
use DbCms\Core\Services\Content\PublicTranslation;
use DbCms\Modules\Blog\Models\Post;
use Illuminate\Database\Eloquent\Builder;

/** Only published, localized owner data crosses the reusable block boundary. */
final class PostBlockDataSource implements BlockDataSourceInterface
{
    public function fields(): array
    {
        return [
            'id' => ['type' => 'integer', 'label' => 'ID'],
            'title' => ['type' => 'text', 'label' => 'Título'],
            'summary' => ['type' => 'text', 'label' => 'Resumen'],
            'image_url' => ['type' => 'image', 'label' => 'Imagen'],
            'url' => ['type' => 'url', 'label' => 'Enlace'],
        ];
    }

    public function filters(): array
    {
        return [
            'id' => ['type' => 'integer', 'label' => 'ID', 'rules' => ['nullable', 'integer', 'min:1']],
            'order' => ['type' => 'select', 'label' => 'Orden', 'options' => ['newest' => 'Recientes', 'oldest' => 'Antiguos'], 'rules' => ['nullable', 'in:newest,oldest']],
            'featured' => ['type' => 'checkbox', 'label' => 'Destacados', 'rules' => ['nullable', 'boolean']],
        ];
    }

    public function items(Market $market, string $locale, array $filters, int $page, int $limit): array
    {
        $discovery = app(PublicContentDiscoveryInterface::class);
        /** @var Builder<Post> $query */
        $query = $discovery->query('post', $market, $locale);
        $query->without(['publishedSections', 'publishedSections.translations']);
        PublicTranslation::constrainResolvedField($query, $market, $locale, 'slug', 'like', '_%');
        if (isset($filters['id'])) {
            $query->whereKey((int) $filters['id']);
        }
        if (array_key_exists('featured', $filters) && $filters['featured'] !== null) {
            $query->where('is_featured', (bool) $filters['featured']);
        }
        $direction = ($filters['order'] ?? 'newest') === 'oldest' ? 'asc' : 'desc';
        $limit = max(1, min(50, $limit));
        $page = max(1, min(10000, $page));
        $records = $query->orderBy('published_at', $direction)->orderBy('id', $direction)
            ->offset(($page - 1) * $limit)->limit($limit + 1)->get();
        $items = [];
        foreach ($records->take($limit) as $record) {
            $translation = PublicTranslation::resolve($record, $locale, $market);
            $item = [
                'id' => $record->id,
                'title' => (string) $translation?->getAttribute('title'),
                'summary' => strip_tags((string) $translation?->getAttribute('summary')),
                'image_url' => $record->featured_image,
                'url' => $discovery->url('post', $market, $locale, (string) $translation?->getAttribute('slug')),
            ];
            $items[] = $item;
        }

        return ['items' => $items, 'has_more' => $records->count() > $limit];
    }
}
