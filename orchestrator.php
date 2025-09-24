<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require __DIR__ . '/vendor/autoload.php';

$options = getopt('', ['component::', 'version::']);
$componentFilter = $options['component'] ?? 'all';
$versionFilter = $options['version'] ?? '';

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

$buildDir = __DIR__ . '/build/' . (new DateTime('now')->format('Ymd-His')) . '/' ;
if (!is_dir($buildDir)) {
    mkdir($buildDir, 0777, true);
}

$results = [];

$exitStatus = 0;
foreach ($components as $component) {
    $name = $component['name'];
    $repo = $component['repo'];
    $defaultVersion = $component['default_version'] ?? '';

    $versionPrefix = $versionFilter ?: $defaultVersion;
    if (!$versionPrefix) {
        $results[$name] = [
            'status' => '⚠️ No version specified',
            'log' => '',
        ];
        continue;
    }

    $dir = "$buildDir/$name";
    if (is_dir($dir)) {
        shell_exec("rm -rf $dir");
    }

    echo "Cloning $name from $repo ...\n";
    shell_exec("git clone --quiet --no-checkout $repo $dir");
    $tag = trim(shell_exec("cd $dir && git tag --list \"$versionPrefix.*\" --sort=-v:refname | head -n 1"));
    if (!$tag) {
        $results[$name] = [
            'status' => "⚠️ No matching tag for $versionPrefix.*",
            'log' => '',
        ];
        continue;
    }

    echo "Checking out tag $tag ...\n";
    shell_exec("cd $dir && git checkout --quiet tags/$tag");

    echo "Installing dependencies ...\n";
    shell_exec("cd $dir && composer install --no-interaction --prefer-dist");

    echo "Running tests ...\n";
    $output = [];
    $status = 0;
    exec("cd $dir && php vendor/bin/micro-testing-tool test:all", $output, $status);
    if($status !== 0) {
        $exitStatus = 1;
    }

    $results[$name] = [
        'status' => $status === 0 ? "✅ Passed ($tag)" : "❌ Failed ($tag)",
        'log' => implode("\n", $output),
    ];
}

$md = "# Test Results\n\n| Component | Status |\n|-----------|--------|\n";
foreach ($results as $name => $r) {
    $md .= "| $name | {$r['status']} |\n";
}
file_put_contents($buildDir . 'report.md', $md);

file_put_contents($buildDir . 'report.json', json_encode($results, JSON_PRETTY_PRINT));

echo "✅ Reports generated: report.md / report.json\n";

exit($exitStatus);
