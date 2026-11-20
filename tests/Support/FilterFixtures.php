<?php

declare(strict_types=1);

namespace Tests\Support;

use DbCms\Core\Models\Market;
use DbCms\Core\Models\User;
use DbCms\Modules\Blog\Models\Category;
use DbCms\Modules\Blog\Models\Post;

final class FilterFixtures
{
    public static function blog(): void
    {
        $market = Market::query()->where('code', 'es')->sole();
        $category = Category::create(['is_active' => true]);
        $category->translations()->create(['locale' => 'es', 'name' => 'Arquitectura', 'slug' => 'arquitectura']);
        $category->markets()->attach($market);
        // Three is the real minimum-results boundary for indexable filters.
        foreach (range(1, 3) as $number) {
            $post = Post::create(['user_id' => User::query()->where('email', 'admin@db-cms.io')->sole()->id, 'category_id' => $category->id, 'is_published' => true]);
            $post->translations()->create(['locale' => 'es', 'title' => 'Artículo '.$number, 'slug' => 'articulo-'.$number, 'content' => '<p>Contenido del filtro.</p>']);
            $post->markets()->attach($market);
        }
    }
}
