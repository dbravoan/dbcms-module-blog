<?php

declare(strict_types=1);

use DbCms\Core\Contracts\Services\GeneralSettingsServiceInterface;
use DbCms\Core\Models\CmsModule;
use DbCms\Core\Models\User;
use DbCms\Modules\Blog\Models\Comment;
use DbCms\Modules\Blog\Services\CommentPrivacyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\BlogComposition;
use Tests\Support\BlogFixtures;
use Tests\Support\CmsFixtures;
use Tests\Support\PrivacyFixtures;

uses(BlogComposition::class);

beforeEach(function (): void {
    CmsFixtures::localization();
    CmsFixtures::identities();
    $this->post = BlogFixtures::post();
});

it('blocks undeclared comment collection and shows contextual privacy before accepting a comment', function (): void {
    $this->withoutVite();
    $payload = ['author_name' => 'Private Author', 'author_email' => 'private@example.test', 'content' => 'A comment'];
    $url = '/es/es/blog/arquitectura-modular';
    $this->get($url)->assertOk()->assertSee('name="author_email"', false)->assertSee('privacidad');
    app(GeneralSettingsServiceInterface::class)->set('legal.processing', PrivacyFixtures::processingFor([]), 'legal', 'json');
    $this->post($url.'/comments', $payload)->assertSessionHasErrors('privacy');
    expect(Comment::query()->exists())->toBeFalse();
    $this->get($url)->assertOk()->assertDontSee('name="author_email"', false);
});

it('preserves replies by other people while erasing and expiring inactive Blog comments', function (): void {
    $parent = Comment::query()->forceCreate(['post_id' => $this->post->id, 'author_email' => 'expired@example.test', 'content' => 'Expired comment', 'created_at' => now()->subDays(31)]);
    $reply = Comment::query()->create(['post_id' => $this->post->id, 'parent_id' => $parent->id, 'author_email' => 'reply@example.test', 'content' => 'Current reply']);
    CmsModule::query()->where('identifier', 'blog')->update(['is_active' => false]);
    $privacy = app(CommentPrivacyService::class);
    expect($privacy->prune())->toBe(1)->and($reply->fresh()->parent_id)->toBeNull()->and($reply->fresh()->content)->toBe('Current reply');
    expect($privacy->export([$reply->id])[0]['author_email'])->toBe('reply@example.test');
    expect($privacy->erase([$reply->id]))->toBe(1);
});

it('requires post author reassignment before account erasure without partial core cleanup', function (): void {
    $author = User::query()->findOrFail($this->post->user_id);
    // Another administrator avoids conflating the account safeguard with the Blog safeguard.
    $another = User::factory()->create();
    $another->roles()->attach($author->roles()->firstOrFail());
    DB::table('sessions')->insert(['id' => 'author-session', 'user_id' => $author->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    CmsModule::query()->where('identifier', 'blog')->update(['is_active' => false]);
    expect(fn () => $author->delete())->toThrow(ValidationException::class, 'Reassign authored Blog posts');
    expect($this->post->fresh())->not->toBeNull();
    $this->assertDatabaseHas('sessions', ['id' => 'author-session']);
});
