<?php

declare(strict_types=1);

namespace Infocyph\PHPForge\Composer;

use Composer\Command\BaseCommand as Command;
use Infocyph\PHPForge\Support\ArrayShape;
use Infocyph\PHPForge\Support\Paths;
use Infocyph\PHPForge\Support\ServiceCatalog;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

final class ServiceCommand extends Command
{
    /** @var 'up'|'down'|'status' */
    private readonly string $action;

    public function __construct(string $action, string $prefix = 'ic:')
    {
        if (!in_array($action, ['up', 'down', 'status'], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown service action: %s', $action));
        }

        $this->action = $action;
        parent::__construct($prefix . 'services:' . $action);
    }

    protected function configure(): void
    {
        $this
            ->setDescription(sprintf('%s selected PHPForge integration services.', ucfirst($this->action)))
            ->addOption('services', null, InputOption::VALUE_REQUIRED, 'JSON string array of services.')
            ->addOption('topologies', null, InputOption::VALUE_REQUIRED, 'JSON service-to-topology object.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configuration = $this->configuration($input, $output);

        if (!is_array($configuration)) {
            return 2;
        }

        $services = $configuration['services'];
        $topologies = $configuration['topologies'];

        if ($services === [] && $this->action === 'up') {
            $output->writeln('<comment>No integration services selected.</comment>');

            return 0;
        }

        $command = ['docker', 'compose', '-f', Paths::packageFile('resources/services/compose.yml')];

        foreach (ServiceCatalog::profiles($services, $topologies) as $profile) {
            $command = [...$command, '--profile', $profile];
        }

        $command = match ($this->action) {
            'up' => [...$command, 'up', '-d'],
            'down' => [...$command, 'down', '--remove-orphans'],
            'status' => [...$command, 'ps'],
        };
        $environment = $this->serviceEnvironment($services, $topologies);
        $process = new Process($command, Paths::projectRootPath(), $environment);
        $process->setTimeout(null);
        $process->run(static function (string $type, string $buffer) use ($output): void {
            $output->write($buffer, false, $type === Process::ERR ? OutputInterface::OUTPUT_RAW : OutputInterface::OUTPUT_NORMAL);
        });

        if (!$process->isSuccessful() || $this->action !== 'up') {
            return $process->getExitCode() ?? 1;
        }

        $readiness = new Process(['bash', Paths::packageFile('.github/scripts/verify-and-wait-services.sh')], Paths::projectRootPath(), $environment);
        $readiness->setTimeout(null);
        $readiness->run(static function (string $type, string $buffer) use ($output): void {
            $output->write($buffer, false, $type === Process::ERR ? OutputInterface::OUTPUT_RAW : OutputInterface::OUTPUT_NORMAL);
        });

        if ($readiness->isSuccessful()) {
            $output->writeln('<info>Selected integration services are ready.</info>');
        }

        return $readiness->getExitCode() ?? 1;
    }

    /** @return array{services:list<string>,topologies:array<string, string>}|null */
    private function configuration(InputInterface $input, OutputInterface $output): ?array
    {
        $stored = $this->storedConfiguration();
        $servicesOption = $input->getOption('services');
        $topologiesOption = $input->getOption('topologies');
        $storedServices = $stored['integration_services'] ?? null;
        $storedTopologies = $stored['service_topologies'] ?? null;
        $servicesJson = $this->resolvedJson($servicesOption, $storedServices, 'IC_INTEGRATION_SERVICES', '[]');
        $topologiesJson = $this->resolvedJson($topologiesOption, $storedTopologies, 'IC_SERVICE_TOPOLOGIES', '{}', true);
        $services = $this->decodeServices($servicesJson, $output);
        $topologies = $this->decodeTopologies($topologiesJson, $output);

        if ($services === null || $topologies === null) {
            return null;
        }

        $errors = ServiceCatalog::validate($services, $topologies);

        if ($errors !== []) {
            foreach ($errors as $error) {
                $output->writeln('<error>' . $error . '</error>');
            }

            return null;
        }

        return ['services' => $services, 'topologies' => $topologies];
    }

    /** @return list<string>|null */
    private function decodeServices(string $json, OutputInterface $output): ?array
    {
        $services = ServiceCatalog::servicesFromJson($json);

        if ($services === null) {
            $output->writeln('<error>Services must be a JSON string array.</error>');

            return null;
        }

        return $services;
    }

    /** @return array<string, string>|null */
    private function decodeTopologies(string $json, OutputInterface $output): ?array
    {
        $topologies = ServiceCatalog::topologiesFromJson($json);

        if ($topologies === null) {
            $output->writeln('<error>Topologies must be a JSON object of strings.</error>');

            return null;
        }

        return $topologies;
    }

    private function resolvedJson(mixed $option, mixed $stored, string $environment, string $default, bool $forceObject = false): string
    {
        if (is_string($option)) {
            return $option;
        }

        if (is_array($stored)) {
            $flags = JSON_THROW_ON_ERROR | ($forceObject ? JSON_FORCE_OBJECT : 0);

            return json_encode($stored, $flags);
        }

        $configured = getenv($environment);

        return is_string($configured) && $configured !== '' ? $configured : $default;
    }

    /**
     * @param list<string> $services
     * @param array<string, string> $topologies
     * @return array<string, string>
     */
    private function serviceEnvironment(array $services, array $topologies): array
    {
        $database = getenv('IC_SERVICE_DATABASE') ?: 'phpforge';
        $username = getenv('IC_SERVICE_USERNAME') ?: 'phpforge';
        $password = getenv('IC_SERVICE_PASSWORD') ?: 'Phpforge_123!';
        $mongodbReplica = ($topologies['mongodb'] ?? 'standalone') === 'replica-set';

        return [
            'PHPFORGE_SERVICE_DATABASE' => $database,
            'PHPFORGE_SERVICE_USERNAME' => $username,
            'PHPFORGE_SERVICE_PASSWORD' => $password,
            'INTEGRATION_SERVICES' => json_encode($services, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'SERVICE_TOPOLOGIES' => json_encode($topologies, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT),
            'IC_SERVICE_DATABASE' => $database,
            'IC_SERVICE_USERNAME' => $username,
            'IC_SERVICE_PASSWORD' => $password,
            'IC_MYSQL_DSN' => "mysql:host=127.0.0.1;port=3306;dbname={$database};charset=utf8mb4",
            'IC_MYSQL_PRIMARY_DSN' => "mysql:host=127.0.0.1;port=3306;dbname={$database};charset=utf8mb4",
            'IC_MYSQL_REPLICA_DSN' => "mysql:host=127.0.0.1;port=3307;dbname={$database};charset=utf8mb4",
            'IC_MYSQL_USER' => $username,
            'IC_MYSQL_PASSWORD' => $password,
            'IC_MARIADB_DSN' => "mysql:host=127.0.0.1;port=3308;dbname={$database};charset=utf8mb4",
            'IC_MARIADB_PRIMARY_DSN' => "mysql:host=127.0.0.1;port=3308;dbname={$database};charset=utf8mb4",
            'IC_MARIADB_REPLICA_DSN' => "mysql:host=127.0.0.1;port=3309;dbname={$database};charset=utf8mb4",
            'IC_MARIADB_USER' => $username,
            'IC_MARIADB_PASSWORD' => $password,
            'IC_POSTGRES_DSN' => "pgsql:host=127.0.0.1;port=5432;dbname={$database}",
            'IC_POSTGRES_PRIMARY_DSN' => "pgsql:host=127.0.0.1;port=5432;dbname={$database}",
            'IC_POSTGRES_REPLICA_DSN' => "pgsql:host=127.0.0.1;port=5433;dbname={$database}",
            'IC_POSTGRES_USER' => $username,
            'IC_POSTGRES_PASSWORD' => $password,
            'IC_MSSQL_DSN' => 'sqlsrv:Server=127.0.0.1,1433;TrustServerCertificate=1',
            'IC_MSSQL_USER' => 'sa',
            'IC_MSSQL_PASSWORD' => $password,
            'IC_SQLITE_MEMORY_DSN' => 'sqlite::memory:',
            'IC_SQLITE_FILE_DSN' => 'sqlite:' . sys_get_temp_dir() . '/phpforge.sqlite',
            'IC_MONGODB_DSN' => $mongodbReplica
                ? 'mongodb://127.0.0.1:27017/?replicaSet=phpforge-rs&directConnection=true'
                : sprintf('mongodb://%s:%s@127.0.0.1:27017/%s?authSource=admin', rawurlencode($username), rawurlencode($password), rawurlencode($database)),
            'IC_MONGODB_REPLICA_SET' => $mongodbReplica ? 'phpforge-rs' : '',
            'IC_REDIS_HOST' => '127.0.0.1',
            'IC_REDIS_PORT' => '6379',
            'IC_REDIS_PASSWORD' => $password,
            'IC_VALKEY_HOST' => '127.0.0.1',
            'IC_VALKEY_PORT' => '6380',
            'IC_VALKEY_PASSWORD' => $password,
            'IC_MEMCACHED_HOST' => '127.0.0.1',
            'IC_MEMCACHED_PORT' => '11211',
            'IC_RABBITMQ_HOST' => '127.0.0.1',
            'IC_RABBITMQ_PORT' => '5672',
            'IC_RABBITMQ_DSN' => sprintf('amqp://%s:%s@127.0.0.1:5672', rawurlencode($username), rawurlencode($password)),
            'IC_RABBITMQ_MANAGEMENT_URL' => 'http://127.0.0.1:15672',
            'IC_NATS_URL' => 'nats://127.0.0.1:4222',
            'IC_NATS_MONITOR_URL' => 'http://127.0.0.1:8222',
            'IC_SMTP_HOST' => '127.0.0.1',
            'IC_SMTP_PORT' => '1025',
            'IC_SMTP_DSN' => 'smtp://127.0.0.1:1025',
            'IC_MAILPIT_URL' => 'http://127.0.0.1:8025',
            'IC_MAILPIT_API_URL' => 'http://127.0.0.1:8025/api',
            'IC_ELASTICSEARCH_HOST' => '127.0.0.1',
            'IC_ELASTICSEARCH_PORT' => '9200',
            'IC_ELASTICSEARCH_URL' => 'http://127.0.0.1:9200',
            'IC_SCYLLADB_HOST' => '127.0.0.1',
            'IC_SCYLLADB_PORT' => '8000',
            'IC_SCYLLADB_ENDPOINT' => 'http://127.0.0.1:8000',
            'IC_SCYLLADB_REGION' => 'us-east-1',
            'IC_SCYLLADB_ACCESS_KEY_ID' => $username,
            'IC_SCYLLADB_SECRET_ACCESS_KEY' => $password,
        ];
    }

    /** @return array<string, mixed> */
    private function storedConfiguration(): array
    {
        $path = Paths::projectRootPath() . DIRECTORY_SEPARATOR . '.phpforge-services.json';

        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return ArrayShape::stringKeyed($decoded);
    }
}
