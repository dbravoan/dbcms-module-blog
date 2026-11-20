<?php

declare(strict_types=1);

namespace Tests\Smoke;

use DbCms\Core\Contracts\Services\ModuleSettingsServiceInterface;
use DbCms\Core\Extensions\ComposerPackages;
use DbCms\Core\Models\CmsModule;
use DbCms\Core\Models\Role;
use DbCms\Core\Models\User;
use DbCms\Core\Modules\ModuleManager;
use DbCms\Modules\Blog\BlogModuleServiceProvider;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ComposerPackageInventory;
use Tests\Support\FreshMigrationDatabase;
use Tests\TestCase;

final class ModuleLifecycleSmokeTest extends TestCase
{
    use FreshMigrationDatabase;

    private ComposerPackageInventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inventory = new ComposerPackageInventory;
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $this->inventory->restore();
        }
    }

    private function manager(): ModuleManager
    {
        app()->instance(ComposerPackages::class, new ComposerPackages);
        $manager = app(ModuleManager::class);
        $manager->discoverModules();

        return $manager;
    }

    public function test_catalog_is_read_only_and_an_explicit_composer_extension_can_be_installed_and_disabled(): void
    {
        $path = $this->inventory->directory.'/probe';
        File::copyDirectory(PACKAGE_ROOT.'/tests/Fixtures/Modules/LifecycleProbe', $path);
        File::put($path.'/module.json', '{}');
        $providerFile = $path.'/LifecycleProbeModuleServiceProvider.php';
        File::put($providerFile, str_replace('DbCms\\Modules\\LifecycleProbe', 'Acme\\LifecycleProbe', File::get($providerFile)));
        require_once $providerFile;
        $this->inventory->add('acme/lifecycle-probe', 'dbcms-module', [
            'identifier' => 'lifecycle-probe', 'name' => 'Lifecycle Probe',
            'provider' => 'Acme\\LifecycleProbe\\LifecycleProbeModuleServiceProvider',
        ], ['dbravoan/dbcms-core' => '~0.0.1'], directory: $path);
        $manager = $this->manager();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::create(['slug' => 'admin', 'name' => 'Admin']));

        self::assertSame(0, CmsModule::query()->count());
        DB::enableQueryLog();
        self::assertArrayHasKey('lifecycle-probe', $manager->discoverModules());
        $this->actingAs($admin)->get('/admin/modules')->assertOk()->assertSee('Lifecycle Probe');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertSame([], array_values(array_filter($queries, static fn (array $query): bool => preg_match('/^\s*(insert|update|delete)\b.*cms_modules/i', $query['query']) === 1)));
        self::assertSame(0, CmsModule::query()->count());
        self::assertFalse(Schema::hasTable('lifecycle_probe_entries'));

        $this->from('/admin/modules')->post('/admin/modules/lifecycle-probe/install')->assertRedirect('/admin/modules')->assertSessionHasNoErrors();
        self::assertTrue($manager->isModuleEnabled('lifecycle-probe'));
        self::assertTrue(Schema::hasTable('lifecycle_probe_entries'));
        DB::table('lifecycle_probe_entries')->insert(['value' => 'Preserved data']);
        $this->get('/admin/module-lifecycle-probe')->assertOk()->assertSee('Extension available');
        self::assertSame(['lifecycle-probe'], CmsModule::query()->pluck('identifier')->all());
        $this->post('/admin/modules/lifecycle-probe/toggle')->assertRedirect();
        self::assertFalse($manager->isModuleEnabled('lifecycle-probe'));
        $this->get('/admin/module-lifecycle-probe')->assertNotFound();
        $this->post('/admin/modules/lifecycle-probe/toggle')->assertRedirect();
        self::assertTrue($manager->isModuleEnabled('lifecycle-probe'));
        $this->post('/admin/modules/lifecycle-probe/uninstall')->assertRedirect();
        self::assertFalse($manager->isModuleEnabled('lifecycle-probe'));
        self::assertFalse($manager->activate('lifecycle-probe'));
        self::assertSame('Preserved data', DB::table('lifecycle_probe_entries')->value('value'));
    }

    public function test_unmanaged_source_and_orphaned_database_records_cannot_enable_a_module(): void
    {
        File::put($this->inventory->directory.'/module.json', json_encode([
            'identifier' => 'unmanaged', 'provider' => 'Acme\\Unmanaged\\Provider',
        ], JSON_THROW_ON_ERROR));
        CmsModule::create(['identifier' => 'unmanaged', 'name' => 'Unmanaged', 'version' => '1.0.0', 'provider' => 'Acme\\Unmanaged\\Provider', 'is_installed' => true, 'is_active' => true]);
        $manager = $this->manager();
        $manager->registerConfiguredModules();
        self::assertArrayNotHasKey('unmanaged', $manager->discoverModules());
        self::assertFalse($manager->isModuleEnabled('unmanaged'));
    }

    public function test_invalid_provider_cannot_create_or_activate_installation_state(): void
    {
        $this->inventory->add('acme/invalid', 'dbcms-module', [
            'identifier' => 'invalid-provider', 'name' => 'Invalid provider', 'provider' => 'Acme\\Invalid\\MissingProvider',
        ]);
        try {
            $this->manager()->install('invalid-provider');
            self::fail('Invalid providers must be rejected before changing installation state.');
        } catch (DomainException $error) {
            self::assertStringContainsString('no valid service provider', $error->getMessage());
            self::assertFalse(CmsModule::where('identifier', 'invalid-provider')->exists());
        }
    }

    public function test_a_provider_from_another_package_cannot_supply_migrations(): void
    {
        $this->inventory->add('acme/foreign-provider', 'dbcms-module', [
            'identifier' => 'foreign-provider', 'name' => 'Foreign provider',
            'provider' => BlogModuleServiceProvider::class,
        ]);
        try {
            $this->manager()->install('foreign-provider');
            self::fail('A Composer package may not claim another package provider.');
        } catch (\RuntimeException $error) {
            self::assertSame('Module provider is outside its Composer package.', $error->getMessage());
            self::assertFalse(CmsModule::where('identifier', 'foreign-provider')->exists());
        }
    }

    public function test_composer_dependencies_prevent_deactivation_uninstallation_and_settings_bypass(): void
    {
        foreach (['foundation' => [], 'consumer' => ['acme/foundation' => '^1.0']] as $identifier => $requires) {
            $manifest = ['identifier' => $identifier, 'name' => ucfirst($identifier), 'provider' => 'Acme\\'.ucfirst($identifier).'\\Provider', 'availability_setting' => 'enabled', 'settings' => ['enabled' => ['type' => 'boolean', 'default' => true]]];
            $this->inventory->add('acme/'.$identifier, 'dbcms-module', $manifest, $requires);
            CmsModule::create(array_intersect_key($manifest, array_flip(['identifier', 'name', 'provider'])) + ['version' => '1.0.0', 'is_active' => true, 'is_installed' => true]);
        }
        $manager = $this->manager();
        foreach (['deactivate', 'uninstall'] as $operation) {
            try {
                $manager->{$operation}('foundation');
                self::fail('Active dependents must prevent availability changes.');
            } catch (DomainException $error) {
                self::assertStringContainsString('consumer', $error->getMessage());
            }
            self::assertTrue($manager->isModuleEnabled('foundation'));
        }
        try {
            app(ModuleSettingsServiceInterface::class)->setMany('foundation', ['enabled' => false]);
            self::fail('Availability settings must not bypass active dependents.');
        } catch (DomainException $error) {
            self::assertStringContainsString('consumer', $error->getMessage());
        }
        $manager->deactivate('consumer');
        $manager->deactivate('foundation');
        self::assertFalse($manager->isModuleEnabled('foundation'));
        try {
            $manager->activate('consumer');
            self::fail('Inactive requirements must prevent activation.');
        } catch (DomainException $error) {
            self::assertStringContainsString('requires selected module [foundation]', $error->getMessage());
        }
        self::assertFalse($manager->isModuleEnabled('consumer'));
        try {
            $manager->uninstall('foundation');
            self::fail('Installed dependents must prevent uninstall even when inactive.');
        } catch (DomainException $error) {
            self::assertStringContainsString('consumer', $error->getMessage());
        }
        self::assertTrue($manager->uninstall('consumer'));
        self::assertTrue($manager->uninstall('foundation'));
    }

    #[DataProvider('failingProviderPhases')]
    public function test_provider_failure_never_commits_installed_state(string $phase): void
    {
        $name = ucfirst($phase).'Failure';
        $identifier = strtolower($name);
        $class = 'Acme\\'.$name.'\\Provider';
        $path = $this->inventory->add('acme/'.$identifier, 'dbcms-module', ['identifier' => $identifier, 'name' => $name, 'provider' => $class]);
        $provider = $path.'/Provider.php';
        File::put($provider, '<?php namespace Acme\\'.$name.'; final class Provider extends \\DbCms\\Core\\Modules\\BaseModuleServiceProvider { public function '.$phase.'(): void { throw new \\RuntimeException("Provider '.$phase.' failed"); } }');
        require_once $provider;
        try {
            $this->manager()->install($identifier);
            self::fail('A failing provider must abort installation.');
        } catch (\RuntimeException $error) {
            self::assertSame('Provider '.$phase.' failed', $error->getMessage());
        }
        self::assertFalse((bool) CmsModule::where('identifier', $identifier)->value('is_installed'));
        self::assertFalse((bool) CmsModule::where('identifier', $identifier)->value('is_active'));
        $this->refreshApplication();
        self::assertSame([], app(ModuleManager::class)->getActiveModules());
    }

    /** @return array<string, array{string}> */
    public static function failingProviderPhases(): array
    {
        return ['registration' => ['register'], 'boot' => ['boot']];
    }
}
