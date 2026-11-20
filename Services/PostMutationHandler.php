<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Services;

use DbCms\Core\Blocks\BlockTree;
use DbCms\Core\Blocks\Contracts\BlockRegistryInterface;
use DbCms\Core\Capabilities\ExecutionContext;
use DbCms\Core\Content\Contracts\EntityMutationHandlerInterface;
use DbCms\Modules\Blog\Models\Post;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PostMutationHandler implements EntityMutationHandlerInterface
{
    public function prepare(Model $entity, array $args, bool $creating): array
    {
        $translations = (array) ($args['translations'] ?? []);
        $mcp = app()->bound(ExecutionContext::class) && app(ExecutionContext::class)->actor?->origin === 'mcp';
        if ($entity instanceof Post && $mcp) {
            $append = $this->legacySections($entity, $translations, array_key_exists('sections_json', $args));
            if ($append !== []) {
                $args['append_sections'] = $append;
            }
        }
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
                        if (! app(BlockRegistryInterface::class)->isAvailable((string) ($block['type'] ?? 'rich_text'))) {
                            continue;
                        }
                        $schema = app(BlockRegistryInterface::class)->get((string) ($block['type'] ?? 'rich_text'))?->getEditorSchema() ?? [];
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
        if ($entity instanceof Post && $entity->exists && $mcp && is_array($decoded) && array_key_exists('sections_json', $args)) {
            // Once a locale uses sections, deliberately removing its whole composition
            // must not reveal the old compatibility copy in the public article.
            $composedLocales = $entity->sections()->with('translations')->get()
                ->flatMap(static fn ($section) => $section->translations->whereNull('market_id')->pluck('locale'))
                ->unique();
            foreach ($entity->translations()->whereNull('market_id')->whereIn('locale', $composedLocales)->pluck('locale') as $locale) {
                if (($sections[$locale] ?? []) === []) {
                    $translations[$locale]['content'] = '';
                }
            }
        }

        return array_replace($args, ['attributes' => $attributes, 'translations' => $translations]);
    }

    /** @param array<array-key,mixed> $translations
     * @return array<string,list<array<string,mixed>>>
     */
    private function legacySections(Post $post, array $translations, bool $hasSectionsInput): array
    {
        $sections = [];
        foreach ($translations as $locale => $translation) {
            if (! is_string($locale) || ! is_array($translation) || ! array_key_exists('content', $translation)) {
                continue;
            }
            $field = "translations.{$locale}.content";
            if ($hasSectionsInput) {
                throw ValidationException::withMessages([$field => 'Usa sections_json para el cuerpo cuando envíes secciones explícitas.']);
            }
            $body = $translation['content'];
            if (! is_string($body)) {
                throw ValidationException::withMessages([$field => 'El cuerpo heredado debe ser texto.']);
            }
            if ($post->exists && $post->sections()->whereHas('translations', static fn ($query) => $query->where('locale', $locale))->exists()) {
                throw ValidationException::withMessages([$field => 'Este idioma ya tiene secciones. Consulta cms_content_sections_get y modifica los bloques con cms_content_sections_patch.']);
            }
            if (trim($body) !== '') {
                $sections[$locale] = [[
                    'type' => 'text', 'columns_layout' => '1',
                    'columns' => [['blocks' => [['type' => 'rich_text', 'format' => 'markdown', 'content' => $body]]]],
                ]];
            }
        }

        return $sections;
    }
}
