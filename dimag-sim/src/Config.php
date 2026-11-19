<?php

// #ddev-generated (DDEV add-on: "ddev add-on get" may overwrite this copy)

declare(strict_types=1);

namespace DimagSim;

/**
 * Simulator settings, from environment variables (see README).
 */
final class Config
{
    /**
     * @param array<string, array{password:string, name:string, roots:string[], admin:bool}> $users
     */
    public function __construct(
        public readonly string $version,
        public readonly string $prefix,
        public readonly string $archiveName,
        public readonly string $publicUrl,
        public readonly string $sftpDir,
        public readonly string $stateFile,
        public readonly array $users,
        public readonly ?string $seedFile,
        public readonly string $xsdVersion,
        public readonly string $metadataVersion,
    ) {
    }

    public static function fromEnv(): self
    {
        $env = fn (string $k, string $d) => ($v = getenv($k)) !== false && $v !== '' ? $v : $d;

        return new self(
            version: $env('DIMAG_SIM_VERSION', '3.5.0'),
            prefix: $env('DIMAG_SIM_PREFIX', 'sim'),
            archiveName: $env('DIMAG_SIM_ARCHIVE_NAME', 'Simulated Archive'),
            publicUrl: $env('DIMAG_SIM_URL', 'http://dimag'),
            sftpDir: rtrim($env('DIMAG_SIM_SFTP_DIR', '/data/sftp/Import'), '/'),
            stateFile: $env('DIMAG_SIM_STATE', '/data/state/state.json'),
            users: self::parseUsers($env('DIMAG_SIM_USERS', 'inge:inge:Test User:sim-2;admin:admin:Test Admin:*:admin')),
            seedFile: getenv('DIMAG_SIM_SEED') ?: null,
            xsdVersion: $env('DIMAG_SIM_XSD', 'LaBW_IngExt_2023-08-21.xsd'),
            metadataVersion: $env('DIMAG_SIM_METADATA_VERSION', '2023-08-21'),
        );
    }

    /**
     * "user:password:Display Name:root1|root2[:admin];..." — roots are the
     * AIDs below which the account may read and write, * for all.
     */
    public static function parseUsers(string $spec): array
    {
        $users = [];
        foreach (array_filter(array_map('trim', explode(';', $spec))) as $entry) {
            $parts = explode(':', $entry);
            if (count($parts) < 4) {
                throw new \InvalidArgumentException('DIMAG_SIM_USERS entry needs user:password:name:roots');
            }
            $users[$parts[0]] = [
                'password' => $parts[1],
                'name' => $parts[2],
                'roots' => array_values(array_filter(explode('|', $parts[3]))),
                'admin' => ($parts[4] ?? '') === 'admin',
            ];
        }
        return $users;
    }

    /** "3.5.0" => 5 */
    public function minor(): int
    {
        return (int) (explode('.', $this->version)[1] ?? 0);
    }

    /** DIMAG serves the endpoint of its own version and those of earlier 3.x versions. */
    public function servesEndpoint(string $endpoint): bool
    {
        if (!preg_match('/^webservice_3_(\d+)_0\.php$/', $endpoint, $m)) {
            return false;
        }
        return (int) $m[1] <= $this->minor();
    }
}
