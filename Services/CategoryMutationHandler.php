<?php

declare(strict_types=1);

namespace DbCms\Modules\Blog\Services;

use DbCms\Core\Content\Contracts\EntityMutationHandlerInterface;
use DbCms\Core\Contracts\Services\LocalizationServiceInterface;
use DbCms\Modules\Blog\Models\Category;
use DbCms\Modules\Blog\Models\CategoryTranslation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CategoryMutationHandler implements EntityMutationHandlerInterface
{
    public function prepare(Model $entity, array $args, bool $creating): array
    {
        $attributes = (array) ($args['attributes'] ?? []);
        $input = array_replace($attributes, array_intersect_key($args, array_flip(['translations', 'market_ids'])));
        Validator::make($input, CategoryMutationRules::all())->validate();
        if (isset($attributes['parent_id'])) {
            $parent = Category::find((int) $attributes['parent_id']);
            $visited = [];
            while ($parent !== null) {
                if ($parent->getKey() === $entity->getKey() || isset($visited[$parent->getKey()])) {
                    throw ValidationException::withMessages(['parent_id' => 'A category cannot contain itself or its descendants.']);
                }
                $visited[$parent->getKey()] = true;
                $parent = $parent->parent;
            }
        }
        if ($creating) {
            $attributes += ['is_active' => false];
        }
        $translations = (array) ($args['translations'] ?? []);
        $inputTranslations = $translations;
        if (! $creating && $entity instanceof Category && array_key_exists('parent_id', $attributes) && $translations === []) {
            $translations = $entity->translations()->get()->mapWithKeys(static fn (CategoryTranslation $row): array => [$row->locale => $row->only(['name', 'slug', 'description'])])->all();
        }
        if (! $creating && $entity instanceof Category) {
            foreach ($translations as $locale => $values) {
                $existing = $entity->translations()->where('locale', $locale)->first();
                if (is_array($values) && $existing !== null) {
                    $translations[$locale] = $values + $existing->only(['name', 'slug', 'description']);
                } elseif (is_array($values) && empty($values['name'])) {
                    throw ValidationException::withMessages(["translations.{$locale}.name" => 'A new translation needs a category name.']);
                }
            }
        }
        if ($creating || $translations !== []) {
            $translations = $this->normalizeCategoryTranslations(['translations' => $translations]);
        }
        foreach ($translations as $locale => &$values) {
            $base = $values['slug'];
            $parentId = array_key_exists('parent_id', $attributes) ? $attributes['parent_id'] : $entity->getAttribute('parent_id');
            $siblings = Category::query()->where('parent_id', $parentId);
            if ($entity->exists) {
                $siblings->whereKeyNot($entity->getKey());
            }
            $suffix = 2;
            while (CategoryTranslation::query()->whereIn('category_id', $siblings->select('id'))->where('locale', $locale)->where('slug', $values['slug'])->exists()) {
                if (! $creating || ! empty($inputTranslations[$locale]['slug'])) {
                    throw ValidationException::withMessages(["translations.{$locale}.slug" => 'A sibling category already uses this slug.']);
                }
                $values['slug'] = $base.'-'.$suffix++;
            }
        }
        unset($values);

        return array_replace($args, ['attributes' => $attributes, 'translations' => $translations]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, array{name: string, slug: string, description: string|null}>
     */
    private function normalizeCategoryTranslations(array $validated): array
    {
        $translations = [];
        $input = (array) ($validated['translations'] ?? []);

        foreach ($input as $locale => $data) {
            if (! is_array($data) || empty($data['name'])) {
                continue;
            }

            $name = (string) $data['name'];
            $translations[(string) $locale] = [
                'name' => $name,
                'slug' => ! empty($data['slug']) ? Str::slug((string) $data['slug']) : Str::slug($name),
                'description' => ! empty($data['description']) ? (string) $data['description'] : null,
            ];
        }

        if ($translations === []) {
            $defaultLocale = app(LocalizationServiceInterface::class)->defaultLocale();

            $translations[$defaultLocale] = [
                'name' => 'Nueva categoría',
                'slug' => 'nueva-categoria',
                'description' => null,
            ];
        }

        return $translations;
    }
}
