<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Support;

/**
 * @phpstan-type ServiceDefinition array{
 *     label:string,
 *     external:bool,
 *     extensions:list<string>,
 *     host:?string,
 *     port:?int,
 *     environment:list<string>,
 *     endpoint_template:string,
 *     probe:string,
 *     retry_attempts:int,
 *     topologies:array<string, ?string>
 * }
 * @phpstan-type ServiceMap array<string, ServiceDefinition>
 */
final class ServiceCatalog
{
    /** @return ServiceMap */
    public static function all(): array
    {
        return self::normalizeCatalog(require Paths::packageFile('resources/services/catalog.php'));
    }

    /**
     * @param list<string> $services
     * @return list<string>
     */
    public static function extensions(array $services): array
    {
        $catalog = self::all();
        $extensions = [];

        foreach ($services as $service) {
            foreach ($catalog[$service]['extensions'] ?? [] as $extension) {
                $extensions[$extension] = true;
            }
        }

        $resolved = array_keys($extensions);
        sort($resolved);

        return $resolved;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /**
     * @param list<string> $services
     * @param array<string, string> $topologies
     * @return list<string>
     */
    public static function profiles(array $services, array $topologies): array
    {
        $catalog = self::all();
        $profiles = [];

        foreach ($services as $service) {
            $topology = $topologies[$service] ?? 'standalone';
            $profile = $catalog[$service]['topologies'][$topology] ?? null;

            if (is_string($profile) && $profile !== '') {
                $profiles[$profile] = true;
            }
        }

        return array_keys($profiles);
    }

    /** @return list<string>|null */
    public static function servicesFromJson(string $json): ?array
    {
        $services = self::stringList(json_decode($json, true));

        if ($services === null) {
            return null;
        }

        $unique = [];

        foreach ($services as $service) {
            if (!in_array($service, $unique, true)) {
                $unique[] = $service;
            }
        }

        return $unique;
    }

    /** @return array<string, string>|null */
    public static function topologiesFromJson(string $json): ?array
    {
        $decoded = json_decode($json);

        if (!$decoded instanceof \stdClass) {
            return null;
        }

        $topologies = [];

        foreach (get_object_vars($decoded) as $service => $topology) {
            if (!is_string($service) || !is_string($topology)) {
                return null;
            }

            $topologies[$service] = $topology;
        }

        return $topologies;
    }

    /**
     * @param list<string> $services
     * @param array<string, string> $topologies
     * @return list<string>
     */
    public static function validate(array $services, array $topologies): array
    {
        $catalog = self::all();
        $errors = [];
        $selected = array_fill_keys($services, true);

        foreach ($services as $service) {
            if (!isset($catalog[$service])) {
                $errors[] = sprintf('Unknown integration service: %s', $service);
            }
        }

        foreach ($topologies as $service => $topology) {
            if (!isset($selected[$service])) {
                $errors[] = sprintf('Topology configured for unselected service: %s', $service);

                continue;
            }

            if (!array_key_exists($topology, $catalog[$service]['topologies'])) {
                $errors[] = sprintf('Unsupported topology for %s: %s', $service, $topology);
            }
        }

        return $errors;
    }

    /** @return list<string> */
    public static function validateDefinitions(): array
    {
        $errors = [];
        $profiles = [];
        $ports = [];
        $environment = [];

        foreach (self::all() as $name => $definition) {
            array_push($errors, ...self::validateDefinition($name, $definition));
            array_push($errors, ...self::registerPort($name, $definition, $ports));
            array_push($errors, ...self::registerEnvironment($name, $definition, $environment));
            array_push($errors, ...self::registerProfiles($name, $definition, $profiles));
        }

        return $errors;
    }

    /** @return ServiceDefinition|null */
    private static function definition(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $extensions = self::stringList($value['extensions'] ?? null);
        $environment = self::stringList($value['environment'] ?? null);
        $topologies = self::topologyMap($value['topologies'] ?? null);

        if (!is_string($value['label'] ?? null) || !is_bool($value['external'] ?? null)) {
            return null;
        }

        if (!is_string($value['endpoint_template'] ?? null) || !is_string($value['probe'] ?? null)) {
            return null;
        }

        if (!is_int($value['retry_attempts'] ?? null) || $extensions === null || $environment === null || $topologies === null) {
            return null;
        }

        $host = $value['host'] ?? null;
        $port = $value['port'] ?? null;

        if ((!is_string($host) && $host !== null) || (!is_int($port) && $port !== null)) {
            return null;
        }

        return [
            'label' => $value['label'],
            'external' => $value['external'],
            'extensions' => $extensions,
            'host' => $host,
            'port' => $port,
            'environment' => $environment,
            'endpoint_template' => $value['endpoint_template'],
            'probe' => $value['probe'],
            'retry_attempts' => $value['retry_attempts'],
            'topologies' => $topologies,
        ];
    }

    /** @return ServiceMap */
    private static function normalizeCatalog(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $catalog = [];

        foreach ($value as $name => $candidate) {
            $definition = self::definition($candidate);

            if (is_string($name) && $definition !== null) {
                $catalog[$name] = $definition;
            }
        }

        return $catalog;
    }

    /**
     * @param ServiceDefinition $definition
     * @param array<string, string> $environment
     * @return list<string>
     */
    private static function registerEnvironment(string $name, array $definition, array &$environment): array
    {
        $errors = [];

        foreach ($definition['environment'] as $variable) {
            if (preg_match('/^IC_[A-Z0-9_]+$/', $variable) !== 1) {
                $errors[] = sprintf('Invalid environment mapping for %s: %s', $name, $variable);
            } elseif (isset($environment[$variable])) {
                $errors[] = sprintf('Environment mapping %s is shared by %s and %s.', $variable, $environment[$variable], $name);
            } else {
                $environment[$variable] = $name;
            }
        }

        return $errors;
    }

    /**
     * @param ServiceDefinition $definition
     * @param array<int, string> $ports
     * @return list<string>
     */
    private static function registerPort(string $name, array $definition, array &$ports): array
    {
        if (!$definition['external']) {
            return [];
        }

        $port = $definition['port'];

        if ($port === null || $port < 1 || $port > 65535) {
            return [sprintf('Invalid port for %s.', $name)];
        }

        if (isset($ports[$port])) {
            return [sprintf('Port %d is shared by %s and %s.', $port, $ports[$port], $name)];
        }

        $ports[$port] = $name;

        return [];
    }

    /**
     * @param ServiceDefinition $definition
     * @param array<string, string> $profiles
     * @return list<string>
     */
    private static function registerProfiles(string $name, array $definition, array &$profiles): array
    {
        $errors = [];

        if (!array_key_exists('standalone', $definition['topologies'])) {
            $errors[] = sprintf('Service %s has no standalone topology.', $name);
        }

        foreach ($definition['topologies'] as $topology => $profile) {
            array_push($errors, ...self::validateProfile($name, $topology, $profile, $definition['external'], $profiles));
        }

        return $errors;
    }

    /** @return list<string>|null */
    private static function stringList(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }

        $strings = [];

        foreach ($value as $item) {
            if (!is_string($item)) {
                return null;
            }

            $strings[] = $item;
        }

        return $strings;
    }

    /** @return array<string, string|null>|null */
    private static function topologyMap(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $topologies = [];

        foreach ($value as $topology => $profile) {
            if (!is_string($topology) || (!is_string($profile) && $profile !== null)) {
                return null;
            }

            $topologies[$topology] = $profile;
        }

        return $topologies;
    }

    /**
     * @param ServiceDefinition $definition
     * @return list<string>
     */
    private static function validateDefinition(string $name, array $definition): array
    {
        $errors = [];

        if (preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1) {
            $errors[] = sprintf('Invalid service name: %s', $name);
        }

        foreach ($definition['extensions'] as $extension) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $extension) !== 1) {
                $errors[] = sprintf('Invalid extension mapping for %s: %s', $name, $extension);
            }
        }

        if ($definition['endpoint_template'] === '' || $definition['retry_attempts'] < 1) {
            $errors[] = sprintf('Service %s has incomplete endpoint/readiness metadata.', $name);
        }

        return $errors;
    }

    /**
     * @param array<string, string> $profiles
     * @return list<string>
     */
    private static function validateProfile(string $name, string $topology, ?string $profile, bool $external, array &$profiles): array
    {
        if (preg_match('/^[a-z][a-z0-9-]*$/', $topology) !== 1) {
            return [sprintf('Invalid topology for %s: %s', $name, $topology)];
        }

        if ($profile === null) {
            return $external ? [sprintf('External service %s topology %s has no Compose profile.', $name, $topology)] : [];
        }

        if (preg_match('/^[a-z][a-z0-9-]*$/', $profile) !== 1) {
            return [sprintf('Invalid Compose profile for %s: %s', $name, $profile)];
        }

        if (isset($profiles[$profile])) {
            return [sprintf('Compose profile %s is shared by %s and %s.', $profile, $profiles[$profile], $name)];
        }

        $profiles[$profile] = $name;

        return [];
    }
}
