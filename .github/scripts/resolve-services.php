#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$catalog = require $root . '/resources/services/catalog.php';
$servicesJson = getenv('INTEGRATION_SERVICES_INPUT') ?: '[]';
$topologiesJson = getenv('SERVICE_TOPOLOGIES_INPUT') ?: '{}';
$configuredExtensions = getenv('PHP_EXTENSIONS_INPUT') ?: '';
$services = json_decode($servicesJson, true);
$topologiesObject = json_decode($topologiesJson);
$containsNonString = static function (array $values): bool {
    foreach ($values as $value) {
        if (!is_string($value)) {
            return true;
        }
    }

    return false;
};

if (!is_array($services) || !array_is_list($services) || $containsNonString($services)) {
    fwrite(STDERR, "integration_services must be a JSON string array.\n");
    exit(1);
}

if (!$topologiesObject instanceof stdClass) {
    fwrite(STDERR, "service_topologies must be a JSON object of service-to-topology strings.\n");
    exit(1);
}

$topologies = get_object_vars($topologiesObject);

if ($containsNonString($topologies)) {
    fwrite(STDERR, "service_topologies must be a JSON object of service-to-topology strings.\n");
    exit(1);
}

$services = array_values(array_unique($services));
$selected = array_fill_keys($services, true);
$profiles = [];
$extensions = [];
$external = false;

foreach (preg_split('/\s*,\s*/', trim($configuredExtensions), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $extension) {
    $extension = str_starts_with($extension, 'ext-') ? substr($extension, 4) : $extension;
    $extensions[$extension] = true;
}

foreach ($services as $service) {
    if (!isset($catalog[$service])) {
        fwrite(STDERR, sprintf("Unknown integration service: %s\n", $service));
        exit(1);
    }

    $topology = $topologies[$service] ?? 'standalone';

    if (!array_key_exists($topology, $catalog[$service]['topologies'])) {
        fwrite(STDERR, sprintf("Unsupported topology for %s: %s\n", $service, $topology));
        exit(1);
    }

    $profile = $catalog[$service]['topologies'][$topology];

    if (is_string($profile) && $profile !== '') {
        $profiles[$profile] = true;
    }

    foreach ($catalog[$service]['extensions'] as $extension) {
        $extensions[$extension] = true;
    }

    $external = $external || $catalog[$service]['external'];
}

foreach ($topologies as $service => $_topology) {
    if (!isset($selected[$service])) {
        fwrite(STDERR, sprintf("Topology configured for unselected service: %s\n", $service));
        exit(1);
    }
}

$extensionNames = array_keys($extensions);
sort($extensionNames);
$profileNames = array_keys($profiles);
sort($profileNames);
$output = [
    'integration_services' => json_encode($services, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
    'service_topologies' => json_encode($topologies, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT),
    'compose_profiles' => json_encode($profileNames, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
    'php_extensions' => implode(', ', $extensionNames),
    'has_external_services' => $external ? 'true' : 'false',
];
$githubOutput = getenv('GITHUB_OUTPUT');

if (is_string($githubOutput) && $githubOutput !== '') {
    $handle = fopen($githubOutput, 'ab');

    if (!is_resource($handle)) {
        fwrite(STDERR, "Unable to open GITHUB_OUTPUT.\n");
        exit(1);
    }

    foreach ($output as $name => $value) {
        fwrite($handle, $name . '=' . $value . PHP_EOL);
    }

    fclose($handle);
} else {
    fwrite(STDOUT, json_encode($output, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}
