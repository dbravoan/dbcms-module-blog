<?php

declare(strict_types=1);

use DbCms\Core\Contracts\Services\ModuleSettingsServiceInterface;
use DbCms\Core\Models\User;
use DbCms\Modules\Blog\Models\Comment;
use DbCms\Modules\Blog\Models\Post;
use Tests\Support\BlogComposition;
use Tests\Support\BlogFixtures;
use Tests\Support\CmsFixtures;

uses(BlogComposition::class);

beforeEach(function (): void {
    CmsFixtures::localization();
    CmsFixtures::identities();
    CmsFixtures::home();
    BlogFixtures::post();
    app(ModuleSettingsServiceInterface::class)->set('blog', 'comments_require_approval', true);
    $this->admin = User::where('email', 'admin@db-cms.io')->sole();
    $this->post = Post::query()->firstOrFail();
});

test('a pending comment is visible in moderation and can be approved', function (): void {
    $comment = Comment::query()->create([
        'post_id' => $this->post->getKey(),
        'author_name' => 'Pending author',
        'author_email' => 'pending@example.test',
        'content' => 'This comment needs moderation.',
        'is_approved' => false,
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.blog.comments.index'))
        ->assertOk()
        ->assertSee('This comment needs moderation.');

    $this->actingAs($this->admin)
        ->post(route('admin.blog.comments.approve', $comment))
        ->assertRedirect();

    expect($comment->fresh()->is_approved)->toBeTrue();
});

test('public article data excludes pending comments and replies', function (): void {
    $this->withoutVite();
    $approved = Comment::query()->create(['post_id' => $this->post->id, 'author_name' => 'Public author', 'content' => 'Approved comment.', 'is_approved' => true]);
    Comment::query()->create(['post_id' => $this->post->id, 'parent_id' => $approved->id, 'author_name' => 'Pending author', 'content' => 'Private pending reply.', 'is_approved' => false]);

    $this->get('/es/es/blog/arquitectura-modular')->assertOk()
        ->assertViewHas('post', fn (Post $post): bool => $post->comments->pluck('id')->all() === [$approved->id] && $post->comments->first()->replies->isEmpty())
        ->assertViewHas('commentSubmissionUrl', url('/es/blog/arquitectura-modular/comments'))
        ->assertDontSee('Private pending reply.');
});
