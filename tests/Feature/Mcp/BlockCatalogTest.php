<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use DbCms\Core\Blocks\BlockCatalogService;
use DbCms\Core\Blocks\BlockPackageManager;
use DbCms\Core\Content\Pages\Models\Page;
use DbCms\Core\Forms\Models\CustomForm;
use DbCms\Core\Models\CmsModule;
use DbCms\Core\Models\Market;
use DbCms\Core\Models\User;
use DbCms\Core\Modules\ModuleManager;
use DbCms\Core\Sections\SectionSyncService;
use DbCms\Core\Services\Capabilities\EntityMutationService;
use DbCms\Modules\Blog\Models\Post;
use DbCms\Modules\Gallery\Models\Gallery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\CmsFixtures;
use Tests\Support\McpFixtures;
use Tests\TestCase;

final class BlockCatalogTest extends TestCase
{
    use RefreshDatabase;

    public const TEST_MODULES = ['blog', 'gallery'];

    private User $writer;

    protected function setUp(): void
    {
        parent::setUp();
        McpFixtures::identities();
        CmsFixtures::localization(['es', 'en'], ['es', 'uk']);
        CmsFixtures::identities(['page_writer' => ['access:admin', 'manage:pages']]);
        $this->writer = User::where('email', 'page_writer@db-cms.io')->firstOrFail();
    }

    public function test_external_agent_discovers_blocks_without_ai_or_source_module_permissions(): void
    {
        self::assertFalse(app(ModuleManager::class)->isModuleEnabled('ai_assistant'));
        $form = CustomForm::create(['slug' => 'contact', 'is_active' => true]);
        $form->translations()->create(['locale' => 'en', 'name' => 'Contact']);
        $result = $this->callTool('cms_block_types', ['entity_type' => 'page', 'locale' => 'en', 'market' => 'uk', 'view' => 'detail', 'include_references' => true]);

        self::assertArrayNotHasKey('error', $result);
        self::assertSame('uk', $result['context']['market']);
        self::assertSame(['id' => $form->id, 'name' => 'Contact', 'slug' => 'contact'], $result['blocks']['form']['dynamic_data']['forms'][0]);
        self::assertTrue($result['blocks']['form']['schema']['form_slug']['required']);
        self::assertArrayHasKey('description', $result['blocks']['posts']['schema']['post_id']);
        self::assertTrue($result['blocks']['posts']['is_full_width']);
        self::assertArrayHasKey('translation_paths', $result['blocks']['heading']);

        $created = $this->callTool('cms_entity_create', ['type' => 'page', 'market_ids' => [Market::where('code', 'uk')->firstOrFail()->id], 'translations' => ['en' => ['title' => 'External agent']], 'sections_json' => ['en' => [['type' => 'form', 'form_slug' => 'contact']]]]);
        self::assertArrayHasKey('id', $created);
        self::assertSame('draft', $created['status']);
    }

    public function test_external_agent_gets_a_compact_catalogue_before_selecting_details(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = $this->callTool('cms_block_types', ['entity_type' => 'page', 'locale' => 'en', 'market' => 'uk']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertArrayNotHasKey('error', $result);
        self::assertArrayHasKey('form', $result['blocks']);
        self::assertArrayNotHasKey('schema', $result['blocks']['form']);
        self::assertArrayNotHasKey('dynamic_data', $result['blocks']['form']);
        self::assertSame([], array_values(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'from `custom_forms`'))));
        $selected = $this->callTool('cms_block_types', ['entity_type' => 'page', 'locale' => 'en', 'market' => 'uk', 'block_type' => 'form']);
        self::assertArrayHasKey('schema', $selected['blocks']['form']);
        self::assertSame([], $selected['blocks']['form']['dynamic_data']);
    }

    public function test_external_agent_uploads_an_image_without_ai(): void
    {
        CmsFixtures::identities(['media_writer' => ['access:admin', 'manage:pages', 'manage:media']]);
        $this->writer = User::where('email', 'media_writer@db-cms.io')->firstOrFail();
        $file = UploadedFile::fake()->image('external.png', 12, 8);
        $result = $this->callTool('cms_media_upload', ['filename' => 'external.png', 'content_base64' => base64_encode($file->getContent())]);
        self::assertTrue($result['uploaded']);
        self::assertTrue($result['media']['is_image']);
        self::assertSame(12, $result['media']['width']);
        self::assertSame(8, $result['media']['height']);
        self::assertFalse(app(ModuleManager::class)->isModuleEnabled('ai_assistant'));
    }

    public function test_native_transport_advertises_union_and_nested_array_constraints(): void
    {
        $token = $this->writer->createToken('schema', ['mcp:access', 'mcp:read', 'mcp:write'])->accessToken;
        $response = $this->withToken($token)->postJson('/mcp/cms', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]);
        $response->assertOk();
        $tools = collect($response->json('result.tools'))->keyBy('name');

        self::assertSame(['object', 'string'], $tools['cms_entity_create']['inputSchema']['properties']['sections_json']['type']);
        self::assertSame(['type' => 'integer'], $tools['cms_entity_create']['inputSchema']['properties']['market_ids']['items']);
        self::assertSame(['array', 'object'], $tools['cms_content_sections_sync']['inputSchema']['properties']['sections']['type']);
        self::assertSame(['block_component', 'page'], $tools['cms_block_types']['inputSchema']['properties']['entity_type']['enum']);
        self::assertSame(1, $tools['cms_block_types']['inputSchema']['properties']['entity_id']['minimum']);
        self::assertArrayNotHasKey('cms_ai_generate_block', $tools->all());
    }

    public function test_reference_choices_and_validation_are_scoped_to_public_locale_and_market(): void
    {
        $uk = Market::where('code', 'uk')->firstOrFail();
        $es = Market::where('code', 'es')->firstOrFail();
        $public = $this->createPost('Public English', 'en', $uk);
        $this->createPost('Wrong market', 'en', $es);
        $this->createPost('Wrong language', 'es', $uk);
        $this->createPost('Private draft', 'en', $uk, false);
        $service = app(BlockCatalogService::class);
        $catalog = $service->catalog($uk, 'en', 'posts');

        self::assertSame([$public->id], array_column($catalog['posts']['dynamic_data']['blog_posts'], 'id'));
        $service->validateReferences('posts', ['post_id' => $public->id], $uk, 'en');
        $this->expectException(ValidationException::class);
        $service->validateReferences('posts', ['post_id' => $public->id], $es, 'en');
    }

    public function test_all_dynamic_owners_return_only_eligible_public_references(): void
    {
        $uk = Market::where('code', 'uk')->firstOrFail();
        $es = Market::where('code', 'es')->firstOrFail();
        foreach ([$uk, $es] as $market) {
            $form = CustomForm::create(['slug' => 'form-'.$market->code, 'is_active' => true]);
            $form->translations()->create(['locale' => 'en', 'name' => 'Form '.$market->code]);
            $form->markets()->attach($market);
            $gallery = Gallery::create(['title' => 'Gallery '.$market->code, 'slug' => 'gallery-'.$market->code, 'is_active' => true]);
            $gallery->translations()->create(['locale' => 'en', 'title' => 'Gallery '.$market->code, 'slug' => 'gallery-'.$market->code]);
            $gallery->markets()->attach($market);
        }
        $regionalOnly = CustomForm::create(['slug' => 'regional-only', 'is_active' => true]);
        $regionalOnly->translations()->create(['locale' => 'en', 'market_id' => $es->id, 'name' => 'Regional Spain']);
        $catalog = app(BlockCatalogService::class)->catalog($uk, 'en');

        self::assertSame(['form-uk'], array_column($catalog['form']['dynamic_data']['forms'], 'slug'));
        self::assertSame(['gallery-uk'], array_column($catalog['gallery']['dynamic_data']['gallery_albums'], 'slug'));
    }

    public function test_unavailable_blocks_and_invalid_context_are_not_described(): void
    {
        app(BlockPackageManager::class)->setEnabled('fixture_blocks', false);
        CmsModule::where('identifier', 'blog')->update(['is_active' => false]);
        $result = $this->callTool('cms_block_types', ['entity_type' => 'page', 'locale' => 'en', 'market' => 'uk']);
        self::assertArrayNotHasKey('fixture_chart', $result['blocks']);
        self::assertArrayHasKey('heading', $result['blocks']);
        self::assertArrayNotHasKey('posts', $result['blocks']);
        $denied = $this->callTool('cms_block_types', ['entity_type' => 'post', 'locale' => 'en', 'market' => 'uk']);
        self::assertArrayHasKey('error', $denied);
        $invalid = $this->callTool('cms_block_types', ['entity_type' => 'page', 'locale' => 'zz', 'market' => 'uk']);
        self::assertArrayHasKey('error', $invalid);
        $page = Page::create(['template' => 'blade:legal.privacy', 'is_published' => false]);
        $blade = $this->callTool('cms_block_types', ['entity_type' => 'page', 'entity_id' => $page->id, 'locale' => 'en', 'market' => 'uk']);
        self::assertArrayHasKey('error', $blade);
    }

    public function test_saving_rechecks_references_and_rolls_back_related_changes(): void
    {
        $uk = Market::where('code', 'uk')->firstOrFail();
        $post = $this->createPost('Reference', 'en', $uk);
        $page = Page::create(['template' => 'default', 'is_published' => false]);
        $page->translations()->create(['locale' => 'en', 'title' => 'Before', 'slug' => 'before']);
        $page->markets()->attach($uk);
        app(SectionSyncService::class)->sync($page, json_encode(['en' => [['type' => 'posts', 'post_id' => $post->id]]], JSON_THROW_ON_ERROR), 'draft');
        $before = $page->fresh()->sections->first()->translations->first()->content_json;
        $post->update(['is_published' => false]);
        try {
            app(EntityMutationService::class)->update('page', $page->id, ['translations' => ['en' => ['title' => 'After']], 'sections_json' => ['en' => [['type' => 'posts', 'post_id' => $post->id]]]]);
            self::fail('An unpublished reference must fail validation.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('post_id', $exception->errors());
        }
        self::assertSame('Before', $page->fresh()->translations->first()->title);
        self::assertSame($before, $page->fresh()->sections->first()->translations->first()->content_json);
    }

    public function test_missing_form_reference_requests_input_without_creating_content(): void
    {
        $before = Page::count();
        $result = $this->callTool('cms_entity_create', ['type' => 'page', 'translations' => ['en' => ['title' => 'Incomplete']], 'sections_json' => ['en' => [['type' => 'form']]]]);

        self::assertSame('needs_input', $result['status']);
        self::assertFalse($result['success']);
        self::assertStringContainsString('form_slug', $result['question']);
        self::assertArrayHasKey('error', $result);
        self::assertArrayHasKey('form_slug', $result['validation']);
        self::assertSame($before, Page::count());
    }

    public function test_disabled_stored_block_survives_sibling_edits_without_revalidating_inactive_reference(): void
    {
        $uk = Market::where('code', 'uk')->firstOrFail();
        $post = $this->createPost('Stored reference', 'en', $uk);
        $page = Page::create(['template' => 'default', 'is_published' => false]);
        $page->markets()->attach($uk);
        app(SectionSyncService::class)->sync($page, json_encode(['en' => [['type' => 'posts', 'post_id' => $post->id], ['type' => 'rich_text', 'content' => 'Before']]], JSON_THROW_ON_ERROR), 'draft');
        $sections = $page->fresh()->sections()->with('translations')->orderBy('order')->get();
        $stored = $sections[0]->translations->first()->content_json;
        CmsModule::where('identifier', 'blog')->update(['is_active' => false]);
        $post->update(['is_published' => false]);
        app(SectionSyncService::class)->sync($page, json_encode(['en' => [['id' => $sections[1]->id, 'type' => 'rich_text', 'content' => 'After']]], JSON_THROW_ON_ERROR), 'draft');

        self::assertSame(2, $page->fresh()->sections()->count());
        self::assertSame($stored, $page->fresh()->sections()->with('translations')->findOrFail($sections[0]->id)->translations->first()->content_json);
    }

    private function createPost(string $title, string $locale, Market $market, bool $published = true): Post
    {
        $post = Post::withoutEvents(fn () => Post::create(['user_id' => $this->writer->id, 'is_published' => $published, 'published_at' => $published ? now()->subMinute() : null]));
        $post->translations()->create(['locale' => $locale, 'title' => $title, 'slug' => str($title)->slug()->toString(), 'content' => 'Fixture']);
        $post->markets()->attach($market);

        return $post;
    }

    /** @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function callTool(string $name, array $args): array
    {
        $token = $this->writer->createToken('catalog', ['mcp:access', 'mcp:read', 'mcp:write'])->accessToken;
        $response = $this->withToken($token)->postJson('/mcp/cms', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args]]);
        $response->assertOk();
        if ($response->json('error') !== null) {
            return ['error' => $response->json('error')];
        }

        return json_decode((string) $response->json('result.content.0.text'), true, 512, JSON_THROW_ON_ERROR);
    }
}
