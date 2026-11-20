<?php

declare(strict_types=1);

use DbCms\Core\Captcha\Services\CaptchaService;
use DbCms\Core\Contracts\Services\GeneralSettingsServiceInterface;
use DbCms\Core\Contracts\Services\ModuleSettingsServiceInterface;
use DbCms\Core\Services\Cms\CookieConsentService;
use DbCms\Core\Services\Cms\CoreFeatureSettings;
use DbCms\Modules\Blog\Models\Comment;
use DbCms\Modules\Blog\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\BlogCaptchaComposition;
use Tests\Support\BlogFixtures;
use Tests\Support\CmsFixtures;
use Tests\Support\PrivacyFixtures;

uses(RefreshDatabase::class, BlogCaptchaComposition::class);

beforeEach(function (): void {
    CmsFixtures::localization();
    CmsFixtures::identities();
    CmsFixtures::home();
    BlogFixtures::post();

    $settings = app(ModuleSettingsServiceInterface::class);
    $settings->set('blog', 'comments_enabled', true);
    $settings->set('blog', 'comments_require_approval', false);
    $settings->set('blog', 'comments_captcha_enabled', true);
});

test('core CAPTCHA availability does not add a challenge to comments unless selected', function (): void {
    app(ModuleSettingsServiceInterface::class)->set('blog', 'comments_captcha_enabled', false);
    $post = Post::firstOrFail();
    $post->update(['allow_comments' => true]);
    $translation = $post->translations->where('locale', 'es')->firstOrFail();

    $this->get("/es/es/blog/{$translation->slug}")->assertOk()->assertDontSee('name="captcha_response"', false);
    $this->post("/es/es/blog/{$translation->slug}/comments", [
        'author_name' => 'Carlos', 'author_email' => 'without-challenge@example.test', 'content' => 'Comentario con protección básica del núcleo.',
    ])->assertSessionHasNoErrors();
    expect(Comment::query()->where('author_email', 'without-challenge@example.test')->count())->toBe(1);
});

test('a blog comment without a captcha is rejected when CAPTCHA is explicitly enabled', function (): void {
    $post = Post::firstOrFail();
    $post->update(['allow_comments' => true]);
    $translation = $post->translations->where('locale', 'es')->first() ?? $post->translations->firstOrFail();

    $response = $this->post("/es/es/blog/{$translation->slug}/comments", [
        'author_name' => 'Carlos',
        'author_email' => 'carlos@example.com',
        'content' => 'Comentario sin captcha, debe fallar la validación.',
    ]);

    $response->assertSessionHasErrors(['captcha_response']);

    expect(Comment::where('author_email', 'carlos@example.com')->count())->toBe(0);
});

test('a blog comment with a valid captcha is accepted', function (): void {
    $post = Post::firstOrFail();
    $post->update(['allow_comments' => true]);
    $translation = $post->translations->where('locale', 'es')->first() ?? $post->translations->firstOrFail();

    $challenge = app(CaptchaService::class)->generateChallenge();
    preg_match('/(\d+)\s*\+\s*(\d+)/', $challenge['svg'], $matches);
    $answer = (string) ((int) $matches[1] + (int) $matches[2]);

    $response = $this->post("/es/es/blog/{$translation->slug}/comments", [
        'author_name' => 'Carlos',
        'author_email' => 'carlos@example.com',
        'content' => 'Comentario con captcha válido.',
        '_captcha_token' => $challenge['token'],
        'captcha_response' => $answer,
    ]);

    $response->assertSessionHasNoErrors();

    expect(Comment::where('author_email', 'carlos@example.com')->count())->toBe(1);
});

test('the blog comment form is rendered through the shared field framework', function (): void {
    $post = Post::firstOrFail();
    $post->update(['allow_comments' => true]);

    $settings = app(ModuleSettingsServiceInterface::class);
    $settings->set('blog', 'comments_enabled', true);

    $translation = $post->translations->where('locale', 'es')->first() ?? $post->translations->firstOrFail();

    $response = $this->get("/es/es/blog/{$translation->slug}");

    $response->assertOk()
        ->assertSee('name="author_name"', false)
        ->assertSee('name="author_email"', false)
        ->assertSee('name="content"', false)
        ->assertSee('_hp_security_check', false)
        // The blog setting selects the shared core CAPTCHA field.
        ->assertSee('name="captcha_response"', false);
});

test('a blog comment is validated against the active external driver', function (): void {
    app(GeneralSettingsServiceInterface::class)->set('legal.processing', PrivacyFixtures::processingFor(['blog'], ['captcha']), 'legal', 'json');
    $settings = app(CoreFeatureSettings::class);
    $settings->set('captcha', 'driver', 'recaptcha');
    $settings->set('captcha', 'site_key', 'SITE');
    $settings->set('captcha', 'secret_key', 'SECRET');

    $post = Post::firstOrFail();
    $post->update(['allow_comments' => true]);
    $translation = $post->translations->where('locale', 'es')->first() ?? $post->translations->firstOrFail();

    $payload = [
        'author_name' => 'Carlos',
        'author_email' => 'carlos@example.com',
        'content' => 'Comentario validado con Google.',
    ];

    // Without the Google token the submission is rejected.
    $this->post("/es/es/blog/{$translation->slug}/comments", $payload)
        ->assertSessionHasErrors(['captcha_response']);

    Http::fake([
        'www.google.com/recaptcha/api/siteverify' => Http::response(['success' => true, 'score' => 0.9]),
    ]);

    $receipt = app(CookieConsentService::class)->record(['external' => true]);
    $this->withUnencryptedCookie('cms_cookie_consent', $receipt['id'])->post("/es/es/blog/{$translation->slug}/comments", $payload + ['g-recaptcha-response' => 'token'])
        ->assertSessionHasNoErrors();

    expect(Comment::where('author_email', 'carlos@example.com')->count())->toBe(1);
});
