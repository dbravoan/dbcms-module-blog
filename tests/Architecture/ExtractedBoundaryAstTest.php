<?php

declare(strict_types=1);

use DbCms\Core\Cms;
use Tests\Support\ArchitectureBoundaryInspector;

/** @return list<string> */
function boundaryFiles(string $pattern): array
{
    $files = glob(Cms::path($pattern)) ?: [];
    expect($files, $pattern)->not->toBeEmpty();

    return array_values($files);
}

test('controller AST guard accepts delegation and reports model aliases loops and persistence', function (): void {
    $inspector = new ArchitectureBoundaryInspector;
    $valid = '<?php namespace Example; use DbCms\Core\Contracts\Services\ModuleSettingsAdministrationInterface; final class Controller { public function __construct(private ModuleSettingsAdministrationInterface $service) {} public function __invoke($request) { return $this->service->update($request); } }';
    expect($inspector->controllerViolations($valid))->toBe([]);
    expect($inspector->controllerViolations('<?php use DbCms\Core\Contracts\Services\ModuleSettingsAdministrationInterface; class Controller { function __invoke(ModuleSettingsAdministrationInterface $service) { $service->delete(1); } }'))->toBe([]);
    expect($inspector->controllerViolations('<?php use DbCms\Modules\Blog\Models\Post; class Controller { function __invoke(Post $post) { $post->delete(); } }'))->toContain('persistence_or_external_effect');
    $concrete = str_replace('DbCms\\Core\\Contracts\\Services\\ModuleSettingsAdministrationInterface', 'DbCms\\Core\\Services\\Seo\\PublicDocumentCache', $valid);
    $concrete = str_replace('ModuleSettingsAdministrationInterface', 'PublicDocumentCache', $concrete);
    expect($inspector->controllerViolations($concrete))->toBe([]);
    $invalid = '<?php namespace Example; use DbCms\Core\Models\User as Person; use Illuminate\Support\Facades\DB as Database; final class Controller { public function __invoke() { foreach ([1] as $value) { Person::query()->update([]); } Database::statement("bad"); } }';
    expect($inspector->controllerViolations($invalid))->not->toBeEmpty();
    expect($inspector->controllerViolations('<?php use DbCms\Core\Models\User; class Controller { public function __invoke($request) { $actor = $request->user(); $actor->save(); } }'))->toContain('persistence_or_external_effect');
});

test('AST guard detects interface based constructor cycles in the owned service graph', function (): void {
    $inspector = new ArchitectureBoundaryInspector;
    expect($inspector->constructorCycles(['<?php interface PortA {} class ServiceA implements PortA { public function __construct(PortB $other) {} }', '<?php interface PortB {} class ServiceB implements PortB { public function __construct(PortA $other) {} }']))->not->toBeEmpty();
    $paths = [...glob(Cms::path('src/Services/Install/Installation*Service.php')), Cms::path('src/Services/Cms/ModuleNotificationService.php'), Cms::path('src/Services/Cms/NotificationRecipientResolverService.php'), Cms::path('src/Services/Cms/ModuleSettingsService.php'), Cms::path('src/Services/Cms/ModuleSettingsAdministrationService.php'), Cms::path('src/Modules/ModuleManager.php'), Cms::path('src/Maintenance/Services/MaintenanceSettingsAdministrationService.php'), Cms::path('src/Maintenance/Services/MaintenanceService.php')];
    expect($inspector->constructorCycles(array_map(fn (string $path): string => (string) file_get_contents($path), $paths)))->toBe([]);
});

test('services AST guard resolves HTTP aliases without requiring forwarding interfaces', function (): void {
    $inspector = new ArchitectureBoundaryInspector;
    expect($inspector->serviceViolations('<?php use Illuminate\Http\Request as Input; class Service { public function run(Input $value) {} }'))->toContain('http_dependency');
    expect($inspector->serviceViolations('<?php class Service {}'))->toBe([]);
});

test('AST guard distinguishes external effects in transactions from deferred effects', function (): void {
    $inspector = new ArchitectureBoundaryInspector;
    $immediate = '<?php use Illuminate\Support\Facades\DB as Database; use Illuminate\Support\Facades\Http as Client; Database::transaction(function () { Client::post("remote"); });';
    $deferred = '<?php use Illuminate\Support\Facades\DB as Database; use Illuminate\Support\Facades\Mail; Database::transaction(function () { Database::afterCommit(function () { Mail::send("message"); }); });';
    expect($inspector->transactionViolations($immediate))->toContain('external_effect_inside_transaction')
        ->and($inspector->transactionViolations($deferred))->toBe([]);
});

test('all extracted HTTP adapters satisfy effective AST boundaries', function (): void {
    $inspector = new ArchitectureBoundaryInspector;
    $paths = [...boundaryFiles('src/Http/Controllers/Seo/*.php'), ...boundaryFiles('src/Http/Controllers/Api/V1/*.php'), ...boundaryFiles('src/Http/Controllers/Install/*.php'), ...boundaryFiles('src/Http/Controllers/Admin/ModuleSettings/*.php'), ...boundaryFiles('src/Maintenance/Http/Controllers/*.php'), ...boundaryFiles('src/Http/Controllers/Admin/Notifications/*.php'), Cms::path('src/Http/Controllers/Admin/Menus/MenusGetController.php'), Cms::path('src/Forms/Http/Controllers/Admin/FormCreateGetController.php'), Cms::path('src/Forms/Http/Controllers/Admin/FormEditGetController.php')];
    foreach ($paths as $path) {
        expect(is_file($path), $path)->toBeTrue();
        expect($inspector->controllerViolations((string) file_get_contents($path)), $path)->toBe([]);
    }
});

test('application services keep transport and remote effects out of transactions', function (): void {
    $inspector = new ArchitectureBoundaryInspector;
    $paths = [...boundaryFiles('src/Services/Install/Installation*Service.php'), Cms::path('src/Services/Cms/ModuleNotificationService.php'), Cms::path('src/Services/Cms/NotificationRecipientResolverService.php'), Cms::path('src/Services/Cms/ModuleSettingsAdministrationService.php'), Cms::path('src/Maintenance/Services/MaintenanceSettingsAdministrationService.php'), Cms::path('src/Services/Admin/MenuEditorService.php'), Cms::path('src/Services/Admin/DatatableQueryService.php'), Cms::path('src/Forms/Services/FormEditorService.php')];
    foreach ($paths as $path) {
        expect(is_file($path), $path)->toBeTrue();
        $source = (string) file_get_contents($path);
        expect($inspector->serviceViolations($source), $path)->toBe([])
            ->and($inspector->transactionViolations($source), $path)->toBe([]);
    }
});
