<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Settings;

use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository;

/**
 * An immutable snapshot of everything the doctor rules look at.
 *
 * Rules only ever see this object, so they stay free of configuration lookups, the container
 * and the filesystem, and can be tested with plain arrays.
 */
final readonly class ReverbSettings
{
    /**
     * @param  array<string, mixed>|null  $connection  broadcasting.connections.{name}
     * @param  array<string, mixed>|null  $server  reverb.servers.{server}
     * @param  list<array<string, mixed>>  $apps  reverb.apps.apps
     * @param  array<string, string>|null  $frontendEnv  VITE_REVERB_* values, null when not found
     */
    public function __construct(
        public string $environment,
        public bool $production,
        public ?string $defaultConnection,
        public string $connectionName,
        public ?array $connection,
        public bool $reverbConfigLoaded,
        public ?array $server,
        public array $apps,
        public int $replicas,
        public bool $sharedRedis,
        public ?array $frontendEnv,
        public ?string $frontendEnvSource,
        public ?string $reverbVersion,
    ) {}

    /**
     * @param  string|null  $frontendEnvPath  overrides reverb-kit.frontend_env; both null means base_path('.env')
     * @param  int|null  $replicas  overrides reverb-kit.topology.replicas
     */
    public static function fromConfig(Repository $config, ?string $frontendEnvPath = null, ?int $replicas = null): self
    {
        $connectionName = self::string($config->get('reverb-kit.connection')) ?? 'reverb';
        $serverName = self::string($config->get('reverb-kit.server')) ?? 'reverb';
        $environment = self::string($config->get('app.env')) ?? 'production';
        $productionEnvironments = $config->get('reverb-kit.production_environments');

        $reverb = $config->get('reverb');
        $reverbConfigLoaded = is_array($reverb);

        $frontendEnvSource = $frontendEnvPath
            ?? self::string($config->get('reverb-kit.frontend_env'))
            ?? base_path('.env');

        return new self(
            environment: $environment,
            production: is_array($productionEnvironments) && in_array($environment, $productionEnvironments, true),
            defaultConnection: self::string($config->get('broadcasting.default')),
            connectionName: $connectionName,
            connection: self::map($config->get("broadcasting.connections.{$connectionName}")),
            reverbConfigLoaded: $reverbConfigLoaded,
            server: $reverbConfigLoaded ? self::map($config->get("reverb.servers.{$serverName}")) : null,
            apps: $reverbConfigLoaded ? self::apps($config->get('reverb.apps.apps')) : [],
            replicas: $replicas ?? self::int($config->get('reverb-kit.topology.replicas')) ?? 1,
            sharedRedis: (bool) $config->get('reverb-kit.topology.shared_redis', false),
            frontendEnv: FrontendEnv::read($frontendEnvSource),
            frontendEnvSource: $frontendEnvSource,
            reverbVersion: self::reverbVersion(),
        );
    }

    /**
     * Whether broadcasts actually go through the connection under test: it is the default
     * connection, or the default connection uses the reverb driver.
     */
    public function inUse(): bool
    {
        if ($this->defaultConnection === null) {
            return false;
        }

        return $this->defaultConnection === $this->connectionName
            && $this->connection !== null
            && ($this->connection['driver'] ?? null) === 'reverb';
    }

    /**
     * A value from the connection's `options` array (host, port, scheme, useTLS, path).
     */
    public function connectionOption(string $key): mixed
    {
        $options = $this->connection['options'] ?? null;

        return is_array($options) ? ($options[$key] ?? null) : null;
    }

    private static function reverbVersion(): ?string
    {
        if (! class_exists(InstalledVersions::class) || ! InstalledVersions::isInstalled('laravel/reverb')) {
            return null;
        }

        return InstalledVersions::getPrettyVersion('laravel/reverb');
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function map(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $map = [];

        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function apps(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $apps = [];

        foreach ($value as $app) {
            $map = self::map($app);

            if ($map !== null) {
                $apps[] = $map;
            }
        }

        return $apps;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
