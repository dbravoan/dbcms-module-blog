<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use DbCms\Core\Blocks\Contracts\BlockRegistryInterface;
use DbCms\Core\Blocks\Types\RichTextBlock;
use DbCms\Core\Capabilities\ActorContext;
use DbCms\Core\Capabilities\ArgumentValidator;
use DbCms\Core\Capabilities\Contracts\CapabilityInterface;
use DbCms\Core\Capabilities\ExecutionContext;
use DbCms\Core\Capabilities\Tools\Entity\EntityCreateTool;
use DbCms\Core\Capabilities\Tools\Entity\EntityUpdateTool;
use DbCms\Core\Contracts\Services\LocalizationServiceInterface;
use DbCms\Core\Services\Capabilities\EntityMutationService;
use DbCms\Core\Services\Capabilities\EntityReadService;
use DbCms\Modules\Blog\Models\Post;
use DbCms\Modules\Blog\Services\PostMutationHandler;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class LocalizedSectionPayloadTest extends TestCase
{
    private Container $previousContainer;

    private ?ApplicationContract $previousFacadeApplication;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Application(dirname(__DIR__, 4));
        $container->instance('validator', new Factory(new Translator(new ArrayLoader, 'en')));
        $container->instance('config', new Repository);
        $text = new RichTextBlock;
        $blocks = $this->createStub(BlockRegistryInterface::class);
        $blocks->method('getActiveBlocks')->willReturn([$text->getType() => $text]);
        $blocks->method('get')->willReturnCallback(static fn (string $type) => $type === $text->getType() ? $text : null);
        $blocks->method('isAvailable')->willReturnCallback(static fn (string $type): bool => $type === $text->getType());
        $container->instance(BlockRegistryInterface::class, $blocks);
        $localization = $this->createStub(LocalizationServiceInterface::class);
        $localization->method('defaultLocale')->willReturn('es');
        $container->instance(LocalizationServiceInterface::class, $localization);
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
    }

    public function test_create_accepts_localized_objects_and_json_in_planning_and_execution(): void
    {
        $this->assertSupportedSections(new EntityCreateTool($this->createStub(EntityMutationService::class), $this->createStub(EntityReadService::class)));
    }

    public function test_update_accepts_localized_objects_and_json_in_planning_and_execution(): void
    {
        $this->assertSupportedSections(new EntityUpdateTool($this->createStub(EntityMutationService::class), $this->createStub(EntityReadService::class)));
    }

    public function test_market_ids_still_rejects_objects_for_create_and_update(): void
    {
        $entities = $this->createStub(EntityMutationService::class);
        foreach ([new EntityCreateTool($entities, $this->createStub(EntityReadService::class)), new EntityUpdateTool($entities, $this->createStub(EntityReadService::class))] as $capability) {
            foreach ([false, true] as $planning) {
                try {
                    $arguments = ['type' => 'post', 'market_ids' => ['es' => 1]];
                    if ($capability instanceof EntityUpdateTool) {
                        $arguments['id'] = 1;
                    }
                    (new ArgumentValidator)->validate($capability, $arguments, $planning);
                    self::fail('Market IDs must remain a list.');
                } catch (ValidationException $exception) {
                    self::assertArrayHasKey('market_ids', $exception->errors());
                }
            }
        }
    }

    public function test_localized_arrays_derive_the_same_text_and_metadata_as_json(): void
    {
        $sections = $this->localizedSections();
        $translations = ['es' => ['title' => 'Artículo español'], 'en' => ['title' => 'English article']];
        $handler = new PostMutationHandler;
        $encoded = $handler->prepare(new Post, ['translations' => $translations, 'sections_json' => json_encode($sections, JSON_THROW_ON_ERROR)], true);
        $structured = $handler->prepare(new Post, ['translations' => $translations, 'sections_json' => $sections], true);

        self::assertSame($encoded['translations'], $structured['translations']);
        self::assertSame($encoded['attributes'], $structured['attributes']);
        self::assertSame($sections, $structured['sections_json']);
        self::assertSame("<p>Texto español.</p>\n\n<p>Segundo párrafo.</p>", $structured['translations']['es']['content']);
        self::assertSame("<p>English text.</p>\n\n<p>Second paragraph.</p>", $structured['translations']['en']['content']);
        self::assertSame("Texto español.\n\nSegundo párrafo.", $structured['translations']['es']['meta_description']);
        self::assertSame("English text.\n\nSecond paragraph.", $structured['translations']['en']['meta_description']);
        self::assertSame('BlogPosting', $structured['translations']['en']['schema_type']);
    }

    public function test_mcp_legacy_bodies_become_text_sections_with_the_original_source(): void
    {
        $context = new ExecutionContext;
        $context->actor = new ActorContext(null, origin: 'mcp');
        app()->instance(ExecutionContext::class, $context);
        $bodies = ['es' => '<p>Texto <strong>HTML</strong></p>', 'en' => 'English **Markdown**'];
        $translations = array_map(static fn (string $body): array => ['title' => 'Fixture', 'content' => $body], $bodies);
        $handler = new PostMutationHandler;

        $result = $handler->prepare(new Post, ['translations' => $translations], true);
        foreach ($bodies as $locale => $body) {
            $block = $result['append_sections'][$locale][0]['columns'][0]['blocks'][0];
            self::assertSame(['type' => 'rich_text', 'format' => 'markdown', 'content' => $body], $block);
        }

        try {
            $handler->prepare(new Post, ['translations' => $translations, 'sections_json' => $this->localizedSections()], true);
            self::fail('Ambiguous inputs must be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('translations.es.content', $exception->errors());
        }
    }

    private function assertSupportedSections(CapabilityInterface $capability): void
    {
        $localized = $this->localizedSections();
        foreach ([$localized, json_encode($localized, JSON_THROW_ON_ERROR)] as $sections) {
            foreach ([false, true] as $planning) {
                $arguments = ['type' => 'post', 'sections_json' => $sections];
                if ($capability instanceof EntityUpdateTool) {
                    $arguments['id'] = 1;
                }
                (new ArgumentValidator)->validate($capability, $arguments, $planning);
                self::assertContains(is_string($sections) ? 'string' : (array_is_list($sections) ? 'array' : 'object'), $capability->properties()['sections_json']['type']);
            }
        }
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function localizedSections(): array
    {
        return [
            'es' => [
                ['type' => 'rich_text', 'content' => '<p>Texto español.</p>'],
                ['columns' => [['id' => 'column-1', 'blocks' => [['type' => 'rich_text', 'content' => '<p>Segundo párrafo.</p>']]]]],
            ],
            'en' => [
                ['type' => 'rich_text', 'content' => '<p>English text.</p>'],
                ['columns' => [['id' => 'column-1', 'blocks' => [['type' => 'rich_text', 'content' => '<p>Second paragraph.</p>']]]]],
            ],
        ];
    }
}
