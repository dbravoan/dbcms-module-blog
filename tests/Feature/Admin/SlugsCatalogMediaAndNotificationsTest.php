<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use DbCms\Core\Contracts\Services\ModuleNotificationServiceInterface;
use DbCms\Core\Models\MediaFile;
use DbCms\Core\Models\User;
use DbCms\Modules\Gallery\Blocks\GalleryAlbumBlock;
use DbCms\Modules\Gallery\GalleryModuleServiceProvider;
use DbCms\Modules\Gallery\Models\Gallery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminCatalogComposition;
use Tests\Support\CmsFixtures;
use Tests\Support\GalleryFixtures;

uses(RefreshDatabase::class, AdminCatalogComposition::class);

beforeEach(function (): void {
    // Content is mutated and asserted immediately in these tests.
    config(['cms.page_cache.enabled' => false]);
    CmsFixtures::localization(['es', 'en'], ['es', 'mx']);
    CmsFixtures::identities();
    GalleryFixtures::album();
});

test('1. universal slug fallback auto-formats title to lowercase without accents and with hyphens', function (): void {
    $admin = User::where('email', 'admin@db-cms.io')->firstOrFail();

    // Create a page with accents and uppercase
    $response = $this->actingAs($admin, 'web')
        ->post(route('admin.pages.store'), [
            'translations' => [
                'es' => [
                    'title' => '¡Solución Óptima de Computación y Arquitectura!',
                    'slug' => '', // Left empty to test auto-generation
                ],
            ],
            'is_published' => 1,
        ]);

    $response->assertRedirect(route('admin.pages.index'));

    $this->assertDatabaseHas('page_translations', [
        'locale' => 'es',
        'title' => '¡Solución Óptima de Computación y Arquitectura!',
        'slug' => 'solucion-optima-de-computacion-y-arquitectura',
    ]);
});

test('2. forms are identified by block code identifier and do not generate public standalone pages', function (): void {
    $admin = User::where('email', 'admin@db-cms.io')->firstOrFail();

    $response = $this->actingAs($admin, 'web')
        ->post(route('admin.forms.store'), [
            'slug' => 'contacto-soporte-vip',
            'translations' => [
                'es' => [
                    'name' => 'Contacto Soporte VIP',
                    'submit_button_text' => 'Enviar Consulta',
                ],
            ],
            'fields' => [
                [
                    'field_key' => 'email',
                    'type' => 'email',
                    'is_required' => 1,
                    'translations' => [
                        'es' => ['label' => 'Email'],
                    ],
                ],
            ],
        ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('custom_forms', [
        'slug' => 'contacto-soporte-vip',
    ]);

    // Forms do not have standalone GET route
    $this->get('/es/es/forms/contacto-soporte-vip')->assertNotFound();
});

test('3. galleries are pageable localized collections and also embeddable via blocks', function (): void {
    $provider = new GalleryModuleServiceProvider(app());
    expect($provider->isPageable())->toBeTrue();

    // Standalone localized gallery URLs render.
    $this->get('/es/es/galeria')->assertOk();
    $this->get('/es/en/gallery')->assertOk();

    // And GalleryAlbumBlock renders cleanly inside modular pages
    $gallery = Gallery::first();
    expect($gallery)->not->toBeNull();

    $block = new GalleryAlbumBlock;
    $html = $block->render([
        'album_slug' => $gallery->translations()->where('locale', 'es')->sole()->slug,
        'title' => 'Galería de Ejemplo',
    ]);

    expect((string) $html)->toContain('Galería de Ejemplo');
});

test('6. media manager stores SEO title and alt_text and allows metadata editing', function (): void {
    $admin = User::where('email', 'admin@db-cms.io')->firstOrFail();

    // Create a media file record
    $media = MediaFile::create([
        'uuid' => 'test-uuid-seo',
        'filename' => 'test-seo-image.png',
        'original_name' => 'diagrama_infraestructura_cloud.png',
        'title' => 'Diagrama Infraestructura Cloud',
        'alt_text' => 'Esquema de servidores cloud de alta disponibilidad',
        'mime_type' => 'image/png',
        'size_bytes' => 10240,
        'width' => 1920,
        'height' => 1080,
        'url' => url('uploads/test-seo-image.png'),
    ]);

    // Update metadata via API
    $response = $this->actingAs($admin, 'web')
        ->putJson(route('admin.media.update', ['id' => $media->id]), [
            'title' => 'Nueva Topología Multi-Región',
            'alt_text' => 'Arquitectura de servidores en Frankfurt y Madrid',
            'caption' => 'Diagrama revisado en septiembre 2026',
        ]);

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'file' => [
                'title' => 'Nueva Topología Multi-Región',
                'alt_text' => 'Arquitectura de servidores en Frankfurt y Madrid',
            ],
        ]);

    $this->assertDatabaseHas('media_files', [
        'id' => $media->id,
        'title' => 'Nueva Topología Multi-Región',
        'alt_text' => 'Arquitectura de servidores en Frankfurt y Madrid',
    ]);
});

test('7. module notification service dispatches notifications and manages read status', function (): void {
    $admin = User::where('email', 'admin@db-cms.io')->firstOrFail();
    /** @var ModuleNotificationServiceInterface $notifier */
    $notifier = app(ModuleNotificationServiceInterface::class);

    expect($notifier->getUnreadCount())->toBe(0);

    // Dispatch notification
    $notification = $notifier->notify(
        module: 'forms',
        type: 'lead',
        title: 'Nuevo Lead: Presupuesto Cloud',
        message: 'El cliente Enterprise ha enviado una solicitud.',
        actionUrl: '/admin/forms/submissions?form_id=1',
        data: ['client' => 'Enterprise Corp'],
        sendEmail: false
    );

    expect($notifier->getUnreadCount())->toBe(1);
    expect($notification->is_read)->toBeFalse();

    // Fetch via API
    $apiResp = $this->actingAs($admin, 'web')->getJson(route('admin.notifications.index'));
    $apiResp->assertOk()
        ->assertJsonFragment([
            'unread_count' => 1,
            'title' => 'Nuevo Lead: Presupuesto Cloud',
        ]);

    // Mark as read via API
    $readResp = $this->actingAs($admin, 'web')->postJson(route('admin.notifications.read', ['id' => $notification->id]));
    $readResp->assertOk()
        ->assertJson([
            'success' => true,
            'unread_count' => 0,
        ]);

    expect($notifier->getUnreadCount())->toBe(0);
});
