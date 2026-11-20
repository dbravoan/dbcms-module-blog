<?php

declare(strict_types=1);

use DbCms\Core\Models\Language;
use DbCms\Core\Models\Market;
use DbCms\Core\Models\User;
use DbCms\Modules\Blog\Models\Post;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$root = getenv('DBCMS_BROWSER_APP') ?: dirname(__DIR__, 3).'/.build/app';
if (getenv('DBCMS_BROWSER_FIXTURE') !== '1'
    || ! is_file($root.'/.dbcms-browser-disposable')
    || trim((string) file_get_contents($root.'/.dbcms-browser-disposable')) !== 'dbcms-module-browser'
    || preg_match('/^dbcms_browser_[a-z0-9_]+$/D', (string) getenv('DB_DATABASE')) !== 1) {
    throw new RuntimeException('Requires a marked disposable application and a dedicated browser database.');
}
putenv('CMS_MODULES=blog');
$_ENV['CMS_MODULES'] = $_SERVER['CMS_MODULES'] = 'blog';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== getenv('DB_DATABASE') || DB::transactionLevel() !== 0) {
    throw new RuntimeException('The browser fixture must use its committed disposable database.');
}
$processing = array_map(static fn (string $owner): array => [
    'owner' => $owner, 'purpose' => 'Disposable browser acceptance fixture', 'legal_basis' => 'Requested test service',
    'data' => 'Synthetic test data', 'retention' => 'Deleted after acceptance', 'retention_days' => 1,
    'recipients' => 'Disposable local application', 'transfers' => 'External browser requests are intercepted',
], ['core', 'forms', 'executions', 'blog_comments']);
$companyFile = storage_path('browser-company.json');
file_put_contents($companyFile, json_encode([
    'company_name' => 'Browser fixture SL', 'tax_id' => 'TEST-BROWSER', 'activity' => 'Synthetic acceptance',
    'address' => 'Test street 1, Madrid', 'country' => 'ES', 'contact_email' => 'contact@example.test',
    'privacy_email' => 'privacy@example.test', 'hosting_provider' => 'Disposable Docker fixture', 'hosting_country' => 'ES',
    'processing' => $processing, 'reviewed' => true,
], JSON_THROW_ON_ERROR));
$exit = Artisan::call('cms:install', [
    '--db-host' => getenv('DB_HOST'), '--db-port' => getenv('DB_PORT') ?: '3306', '--db-database' => getenv('DB_DATABASE'),
    '--db-username' => getenv('DB_USERNAME'), '--db-password' => getenv('DB_PASSWORD'), '--without-demo' => true,
    '--admin-name' => 'Browser administrator', '--admin-email' => 'browser@example.test', '--admin-password' => 'browser-only-password',
    '--company-file' => $companyFile, '--site-name' => 'Module browser acceptance', '--brand' => 'dbcms', '--theme' => 'default-clean', '--force' => true,
]);
echo Artisan::output();
if ($exit !== 0) {
    throw new RuntimeException('The disposable browser installation failed.');
}
$user = User::where('email', 'browser@example.test')->sole();
$spanish = Language::firstOrCreate(['code' => 'es'], ['name' => 'Español', 'native_name' => 'Español', 'is_default' => true, 'is_active' => true]);
$english = Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'native_name' => 'English', 'is_default' => false, 'is_active' => true]);
$market = Market::where('is_default', true)->firstOrFail();
$market->update(['code' => 'es', 'default_locale' => 'es', 'is_active' => true]);
$market->languages()->sync([$spanish->id => ['is_default' => true], $english->id => ['is_default' => false]]);
$fixtures = ['modules' => ['blog']];
foreach (['dbcms', 'termocms'] as $brand) {
    $post = Post::create(['user_id' => $user->id, 'is_published' => false, 'reading_time_minutes' => 1]);
    $post->translations()->createMany([
        ['locale' => 'es', 'title' => 'Browser blog '.$brand, 'slug' => 'browser-blog-'.$brand, 'content' => 'Synthetic draft body', 'summary' => 'Browser draft summary'],
        ['locale' => 'en', 'title' => 'Browser blog English '.$brand, 'slug' => 'browser-blog-en-'.$brand, 'content' => 'English draft body'],
    ]);
    $post->markets()->sync([$market->id]);
    $fixtures[$brand] = ['post' => $post->id];
}
file_put_contents(storage_path('browser-fixtures.json'), json_encode($fixtures, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Browser fixture initialized.\n";
