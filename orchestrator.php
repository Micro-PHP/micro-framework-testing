<?php

declare(strict_types=1);

ini_set('display_errors', 0);
//error_reporting(0);
set_time_limit(0);

use SensioLabs\AnsiConverter\AnsiToHtmlConverter;
use SensioLabs\AnsiConverter\Theme\SolarizedTheme;
use Symfony\Component\Yaml\Yaml;

require __DIR__ . '/vendor/autoload.php';

$theme = new SolarizedTheme();
$ansiHtmlConverter = new AnsiToHtmlConverter($theme);

$options = getopt('', ['component::', 'version::']);
$componentFilter = $options['component'] ?? 'all';
$versionFilter = $options['version'] ?? '';
$results = [];
$exitStatus = 0;

$yamlFile = __DIR__ . '/components.yaml';
if (!file_exists($yamlFile)) {
    echo "components.yaml not found!\n";
    exit(1);
}

$data = Yaml::parseFile($yamlFile);
$components = $data['components'] ?? [];
if (!$components) {
    echo "No components found in YAML!\n";
    exit(1);
}

if ($componentFilter !== 'all') {
    $components = array_filter($components, fn($c) => $c['name'] === $componentFilter);
    if (!$components) {
        echo "Component '{$componentFilter}' not found in components.yaml\n";
        exit(1);
    }
}

$timestamp = (new DateTime())->format('Y-m-d_H-i-s');
$baseDir = __DIR__ . '/build/' . ($versionFilter ?: 'unknown_version') . '/' . $timestamp;
$latestDir = __DIR__ . '/build/latest/' . $versionFilter;

if (!is_dir($baseDir)) mkdir($baseDir, 0777, true);
if (is_dir($latestDir)) shell_exec("rm -rf $latestDir");
mkdir($latestDir, 0777, true);

function runCommand(string $cmd, string &$log): int {
    echo "\nRUN {$cmd}\n";

    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $process = proc_open($cmd, $descriptorspec, $pipes);
    if (!is_resource($process)) return 1;
    $log = '';
    while (!feof($pipes[1])) {
        $line = fgets($pipes[1]);
        if ($line !== false) {
            echo $line;
            $log .= $line;
        }
    }
    while (!feof($pipes[2])) {
        $line = fgets($pipes[2]);
        if ($line !== false) {
            echo $line;
            $log .= $line;
        }
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($process);
}

function results_push(string $name, string $version, string $status, string $log, string $repo): void {
    global $results;

    $results[] = ['name' => $name, 'version' => $version, 'status' => $status, 'log' => $log, 'repo' => $repo];
}

foreach ($components as $component) {
    $dummy = '';
    $name = $component['name'];
    $repo = $component['repo'];
    $versionPrefix = $versionFilter;

    $tmpDir = sys_get_temp_dir() . "/micro_test_{$name}_" . uniqid();
    mkdir($tmpDir, 0777, true);
    if (is_dir($tmpDir)) shell_exec("rm -rf $tmpDir");
    mkdir($tmpDir, 0777, true);

    echo "Cloning $name from $repo ...\n";
    runCommand("git clone --quiet --no-checkout $repo $tmpDir", $dummy);
    $tagsRaw = shell_exec("cd $tmpDir && git tag --list \"$versionPrefix*\" --sort=-v:refname");
    if($tagsRaw === null) {
        results_push($name, ' - ', "⚠️ No matching tag for $versionPrefix*", '', $repo);

        continue;
    }

    $tags = explode("\n", trim(shell_exec("cd $tmpDir && git tag --list \"$versionPrefix*\" --sort=-v:refname")));
    $tags = array_filter($tags, fn($t) => str_starts_with($t, $versionPrefix));
    if (!$tags) {
        results_push($name, ' - ', "⚠️ No matching tag for $versionPrefix*", '', $repo);
        shell_exec("rm -rf $tmpDir");
        continue;
    }

    foreach (array_values($tags) as $tag) {
        echo "Checking out tag $tag ...\n";
        runCommand("cd $tmpDir && git checkout --quiet tags/$tag", $dummy);

        echo "Installing dependencies ...\n";
        runCommand("cd $tmpDir && composer install --no-interaction --prefer-dist --no-ansi", $dummy);

        echo "Running tests ...\n";
        $log = '';
        $status = runCommand("cd $tmpDir && php vendor/bin/micro-testing-tool test:all", $log);
        if ($status !== 0) $exitStatus = 1;

        results_push($name, $tag, $status === 0 ? "✅ Passed" : "❌ Failed", $log, $repo);
        $logfile = 'log' . $tag;
        file_put_contents("$baseDir/$logfile.md", $log);
        file_put_contents("$baseDir/$logfile.html", str_replace("\n", "<br>\n", $ansiHtmlConverter->convert($log)));
    }

    shell_exec("rm -rf $tmpDir");
}

$md = "# Test Results\n\n| Component | Version | Status | Log |\n|-----------|--------|--------|--------|\n";
foreach ($results as $r) {
    $logfile = 'log' . $r['version'];
    $md .= "| [{$r['name']}]({$r['repo']}) | [{$r['version']}]({$r['repo']}/releases/tag/{$r['version']}) | {$r['status']} | [Log]($logfile.html) |\n";
}

file_put_contents("$baseDir/report.md", $md);
file_put_contents("$baseDir/report.html", $md);
file_put_contents("$baseDir/report.json", json_encode($results, JSON_PRETTY_PRINT));

foreach (glob("$baseDir/*") as $file) {
    $dest = $latestDir . '/' . basename($file);
    copy($file, $dest);
}

echo "✅ Reports generated: $baseDir/report.* and latest\n";
exit($exitStatus);
