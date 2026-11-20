<?php

declare(strict_types=1);

define('DBCMS_TOOLING_LIBRARY', true);
require __DIR__.'/package.php';

function verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function executeQualityWorkflow(string $workflow, string $scenario, string $directory): array
{
    $script = $directory.'/'.$scenario.'.bash';
    $phaseLog = $directory.'/'.$scenario.'.phases';
    $fakeDocker = <<<'BASH'
docker() {
    if [[ "$*" == *" php tooling/package.php profiles" ]]; then
        if [[ "$DBCMS_WORKFLOW_SCENARIO" == enumeration_failure ]]; then
            printf 'default\n'
            return 31
        fi
        if [[ "$DBCMS_WORKFLOW_SCENARIO" != empty_enumeration ]]; then
            printf 'default\ncontent\npopups\n'
        fi
    elif [[ "$*" == *" composer "* ]]; then
        cat >/dev/null
        local phase="${@: -1}"
        printf '%s:%s\n' "$DBCMS_TEST_PROFILE" "$phase" >> "$DBCMS_WORKFLOW_PHASE_LOG"
        if [[ "$DBCMS_WORKFLOW_SCENARIO" == phase_failure && "$DBCMS_TEST_PROFILE:$phase" == content:test ]]; then
            return 42
        fi
    fi
    return 0
}
BASH;
    file_put_contents($script, $fakeDocker."\n".$workflow);
    $process = proc_open(['bash', '--noprofile', '--norc', $script], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, null, array_merge(getenv(), [
        'DBCMS_WORKFLOW_SCENARIO' => $scenario,
        'DBCMS_WORKFLOW_PHASE_LOG' => $phaseLog,
    ]));
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot execute the quality workflow regression.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit' => proc_close($process),
        'stdout' => $stdout,
        'stderr' => $stderr,
        'phases' => is_file($phaseLog) ? file($phaseLog, FILE_IGNORE_NEW_LINES) : [],
    ];
}

$config = ['core' => '0.0.1', 'theme' => 'default-clean', 'require' => []];
$manifest = readJson(dirname(__DIR__).'/composer.json');
putenv('DBCMS_TRANSITION_REPOSITORY');
$fixture = fixtureManifest(['require' => ['laravel/framework' => '^13.0']], $manifest, $config, '0.0.1');
verify(count($fixture['repositories']) === 1, 'Normal fixtures must have exactly one local repository.');
verify($fixture['repositories'][0]['type'] === 'path', 'The package under test must be the only path repository.');
verify($fixture['require'][$manifest['name']] === '0.0.1', 'The fixture must require its exact candidate.');
verify($fixture['require']['dbravoan/dbcms-core'] === '0.0.1', 'Core must remain publicly pinned.');
verify(isset($fixture['require']['dbravoan/dbcms-theme-default-clean']), 'A public theme must be explicitly installed.');
foreach (['dbcms-starter', 'dbcms-blocks-basic', 'dbcms-blocks-charts', 'dbcms-admin-theme-dbcms'] as $retired) {
    verify(! isset($fixture['require']['dbravoan/'.$retired]), 'Absorbed or retired package leaked into fixture.');
}
foreach (['module' => 'dbcms-module', 'theme' => 'dbcms-theme', 'admin-theme' => 'dbcms-admin-theme', 'block-package' => 'dbcms-block-package'] as $kind => $type) {
    verify(packageKind(['name' => 'vendor/example', 'type' => $type], []) === $kind, 'Wrong distribution probe for '.$type);
}
verify(packageKind(['name' => 'dbravoan/dbcms-core'], []) === 'core', 'Core is a standalone distribution.');
foreach (['src/Example.php', 'Resources/views/index.blade.php', 'composer.json'] as $entry) {
    assertDistributionEntry($entry);
}
foreach (['tests/Fixture.php', '.build/app/composer.json', '.github/workflows/quality.yml', '.env', 'Resources/.env.production', '../secret', '/absolute'] as $entry) {
    $rejected = false;
    try {
        assertDistributionEntry($entry);
    } catch (RuntimeException) {
        $rejected = true;
    }
    verify($rejected, 'Unsafe archive entry accepted: '.$entry);
}
putenv('DBCMS_TRANSITION_REPOSITORY='.__DIR__);
verify(transitionRepositories() === [['type' => 'artifact', 'url' => __DIR__]], 'Transition archives must be explicitly selected.');
putenv('DBCMS_TRANSITION_REPOSITORY=relative');
$rejected = false;
try {
    transitionRepositories();
} catch (RuntimeException) {
    $rejected = true;
}
verify($rejected, 'A relative transition directory could resolve outside its intended checkout.');
putenv('DBCMS_TRANSITION_REPOSITORY');
$temporary = sys_get_temp_dir().'/dbcms-tooling-test-'.bin2hex(random_bytes(8));
try {
    $testPackages = ['fixturePackages' => ['tests/Fixtures/blocks', 'tests/Fixtures/theme']];
    foreach (['blocks' => 'dbcms-block-package', 'theme' => 'dbcms-theme'] as $name => $type) {
        ensureDirectory($temporary.'/tests/Fixtures/'.$name);
        writeJson($temporary.'/tests/Fixtures/'.$name.'/composer.json', [
            'name' => 'fixture/'.$name, 'version' => '0.0.1', 'type' => $type,
        ]);
        file_put_contents($temporary.'/tests/Fixtures/'.$name.'/README.md', 'Synthetic fixture');
    }
    $fixtures = fixtureRepositories($temporary, $testPackages);
    $again = fixtureRepositories($temporary, $testPackages);
    verify($fixtures === $again, 'Test extension archives must be deterministic for committed locks.');
    verify(count($fixtures) === 2, 'Both generic extension fixtures must be packaged.');
    foreach ($fixtures as $repository) {
        verify($repository['type'] === 'package' && $repository['package']['dist']['type'] === 'zip', 'Test extensions must not introduce another path dependency.');
    }
    symlink($temporary.'/tests', $temporary.'/generated-link');
    removeTree($temporary.'/generated-link');
    verify(! is_link($temporary.'/generated-link') && is_file($temporary.'/tests/Fixtures/theme/README.md'), 'Generated directory cleanup must never follow a root symlink.');

    $workflow = file_get_contents(dirname(__DIR__).'/.github/workflows/quality.yml');
    verify(preg_match('/^        run: \|\R((?:          .*\R|\R)+)/m', $workflow, $matches) === 1, 'The package quality Bash step must exist.');
    $bash = preg_replace('/^          /m', '', $matches[1]);
    $expectedPhases = [];
    foreach (['default', 'content', 'popups'] as $profile) {
        foreach (['check', 'test', 'test:dist'] as $phase) {
            $expectedPhases[] = $profile.':'.$phase;
        }
    }
    $success = executeQualityWorkflow($bash, 'success', $temporary);
    verify($success['exit'] === 0, 'The quality workflow rejected valid profiles: '.$success['stderr']);
    verify($success['phases'] === $expectedPhases, 'The quality workflow must run all three phases of every declared profile even when a container consumes stdin. Observed: '.implode(', ', $success['phases']));
    fwrite(STDOUT, "Quality workflow: three profiles and nine phases passed.\n");
    $enumerationFailure = executeQualityWorkflow($bash, 'enumeration_failure', $temporary);
    verify($enumerationFailure['exit'] === 31 && $enumerationFailure['phases'] === [], 'Profile enumeration failure must abort before any phase.');
    fwrite(STDOUT, "Quality workflow: failed enumeration aborted before phases.\n");
    $emptyEnumeration = executeQualityWorkflow($bash, 'empty_enumeration', $temporary);
    verify($emptyEnumeration['exit'] !== 0 && $emptyEnumeration['phases'] === [], 'An empty profile list must never pass quality.');
    $phaseFailure = executeQualityWorkflow($bash, 'phase_failure', $temporary);
    verify($phaseFailure['exit'] === 42 && $phaseFailure['phases'] === array_slice($expectedPhases, 0, 5), 'A failed phase must abort before later phases or profiles.');
    fwrite(STDOUT, "Quality workflow: failed phase aborted without later effects.\n");
} finally {
    removeTree($temporary);
}
fwrite(STDOUT, "Tooling boundary checks passed.\n");
