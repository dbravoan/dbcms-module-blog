<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Services;

use DbCms\Core\Blocks\BlockTree;
use DbCms\Core\Blocks\Contracts\BlockRegistryInterface;
use DbCms\Core\Content\Contracts\EntityMutationHandlerInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class PostMutationHandler implements EntityMutationHandlerInterface
{
    public function prepare(Model $entity, array $args, bool $creating): array
    {
        $translations = (array) ($args['translations'] ?? []);
        $attributes = (array) ($args['attributes'] ?? []);
        if ($creating) {
            $attributes += ['allow_comments' => true, 'is_featured' => false];
        }
        $rawSections = $args['sections_json'] ?? [];
        $decoded = is_array($rawSections)
            ? $rawSections
            : (is_string($rawSections) ? json_decode($rawSections, true) : null);
        $sections = is_array($decoded) ? $decoded : [];
        foreach ($translations as $locale => $values) {
            if (! is_array($values)) {
                continue;
            }
            if (empty($values['content']) && isset($sections[$locale])) {
                $chunks = [];
                foreach ((array) $sections[$locale] as $section) {
                    if (! is_array($section)) {
                        continue;
                    }
                    foreach (app(BlockTree::class)->nodes($section) as $block) {
                        if (! app(BlockRegistryInterface::class)->isAvailable((string) ($block['type'] ?? 'text'))) {
                            continue;
                        }
                        $schema = app(BlockRegistryInterface::class)->get((string) ($block['type'] ?? 'text'))?->getEditorSchema() ?? [];
                        foreach (['content', 'body', 'text'] as $key) {
                            $content = $block[$key] ?? null;
                            if (isset($schema[$key]) && is_string($content) && trim($content) !== '' && ! array_key_exists($key, (array) ($block['bindings'] ?? []))) {
                                $chunks[] = $content;
                                break;
                            }
                        }
                    }
                }
                $values['content'] = implode("\n\n", $chunks);
            }
            if (array_key_exists('content', $values)) {
                $values['summary'] ??= Str::limit(strip_tags((string) $values['content']), 160);
            }
            if ($creating || array_key_exists('title', $values)) {
                $values['meta_title'] ??= $values['title'] ?? '';
            }
            if ($creating || array_key_exists('summary', $values)) {
                $values['meta_description'] ??= $values['summary'] ?? '';
            }
            if ($creating) {
                $values['schema_type'] ??= 'BlogPosting';
            }
            $translations[$locale] = $values;
        }
        $first = reset($translations);
        if (is_array($first) && array_key_exists('content', $first)) {
            $attributes['reading_time_minutes'] = max(1, (int) ceil(str_word_count(strip_tags((string) $first['content'])) / 200));
        }
        if (($attributes['is_published'] ?? $attributes['publish'] ?? false) && ! isset($attributes['published_at'])) {
            $attributes['published_at'] = $entity->getAttribute('published_at') ?? now();
        }

        return array_replace($args, ['attributes' => $attributes, 'translations' => $translations]);
    }
}
