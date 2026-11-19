<?php

declare(strict_types=1);

namespace DimagSim\Recorder;

use phpseclib3\Net\SFTP;

/**
 * A DIMAG to talk to: the real one or the simulator. Calls the SOAP
 * service the way Inge does (non-WSDL SoapClient, positional parameters)
 * and reaches its SFTP drop.
 *
 * Credentials come from the environment only and never leave this object:
 * every request and response passes through redact() before it is kept.
 */
final class Target
{
    private SFTP|LocalDrop|null $sftp = null;

    /** @var array<string, \SoapClient> */
    private array $clients = [];

    public function __construct(
        public readonly string $soapUrl,
        private readonly array $accounts,   // name => [user, password]
        private readonly array $sftpConfig, // host, port, user, password, root
        public readonly int $timeout = 120,
    ) {
    }

    public static function fromEnv(): self
    {
        $env = fn (string $k, ?string $d = null) => getenv($k) !== false && getenv($k) !== '' ? getenv($k) : $d;

        $accounts = ['user' => [$env('DIMAG_USER'), $env('DIMAG_PASSWORD')]];
        if ($env('DIMAG_ADMIN_USER')) {
            $accounts['admin'] = [$env('DIMAG_ADMIN_USER'), $env('DIMAG_ADMIN_PASSWORD')];
        }

        return new self(
            soapUrl: (string) $env('DIMAG_SOAP_URL', 'http://localhost:8080/soap/webservice_3_5_0.php'),
            accounts: $accounts,
            sftpConfig: [
                'host' => $env('DIMAG_SFTP_HOST', 'localhost'),
                'port' => (int) $env('DIMAG_SFTP_PORT', '2222'),
                'user' => $env('DIMAG_SFTP_USER'),
                'password' => $env('DIMAG_SFTP_PASSWORD'),
                'root' => trim((string) $env('DIMAG_SFTP_ROOT', 'Import'), '/'),
            ],
            timeout: (int) $env('DIMAG_TIMEOUT', '120'),
        );
    }

    public function hasAccount(string $name): bool
    {
        return isset($this->accounts[$name]) && $this->accounts[$name][0];
    }

    /**
     * Call a SOAP operation with the account's credentials prepended, as
     * Inge does. Returns the decoded result, the raw exchange and timing.
     */
    public function soap(string $operation, array $args, string $account = 'user', ?string $location = null): array
    {
        [$user, $password] = $this->accounts[$account] ?? throw new \RuntimeException("No account '$account'");
        $client = $this->client($location ?? $this->soapUrl, $account);
        $params = $operation === 'getStatus' || $operation === 'getPrefixList' ? $args : [$user, $password, ...$args];

        $start = microtime(true);
        $result = null;
        $fault = null;
        try {
            $result = $client->__soapCall($operation, $params);
        } catch (\SoapFault $e) {
            $fault = ['faultcode' => $e->faultcode ?? null, 'faultstring' => $e->faultstring ?? $e->getMessage()];
        }

        return [
            'result' => $this->toPlain($result),
            'fault' => $fault,
            'request' => $this->redact((string) $client->__getLastRequest()),
            'response' => $this->redact((string) $client->__getLastResponse()),
            'http_status' => $this->httpStatus((string) $client->__getLastResponseHeaders()),
            'duration' => round(microtime(true) - $start, 3),
        ];
    }

    private function client(string $location, string $account): \SoapClient
    {
        $key = $location . '|' . $account;
        if (!isset($this->clients[$key])) {
            ini_set('default_socket_timeout', (string) $this->timeout);
            $ssl = [];
            if ($ca = getenv('DIMAG_CA_FILE')) {
                $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'cafile' => $ca];
            }
            $this->clients[$key] = new \SoapClient(null, [
                'location' => $location,
                'uri' => str_replace('.php', '.wsdl', $location),
                'connection_timeout' => 30,
                'keep_alive' => false,
                'trace' => true,
                'exceptions' => true,
                'stream_context' => stream_context_create(['http' => ['timeout' => $this->timeout], 'ssl' => $ssl]),
            ]);
        }
        return $this->clients[$key];
    }

    public function sftpRoot(): string
    {
        return $this->sftpConfig['root'];
    }

    /** Path below the account's SFTP home, as Inge builds it: '/' . sftp_path . '/' . $relative */
    public function sftpPath(string $relative): string
    {
        return '/' . ($this->sftpConfig['root'] !== '' ? $this->sftpConfig['root'] . '/' : '') . ltrim($relative, '/');
    }

    public function sftp(): SFTP|LocalDrop
    {
        if ($this->sftp === null && str_starts_with((string) $this->sftpConfig['host'], 'file://')) {
            $this->sftp = new LocalDrop(substr((string) $this->sftpConfig['host'], 7));
        }
        if ($this->sftp === null) {
            $sftp = new SFTP($this->sftpConfig['host'], $this->sftpConfig['port'], 30);
            if (!$sftp->login((string) $this->sftpConfig['user'], (string) $this->sftpConfig['password'])) {
                throw new \RuntimeException('SFTP login failed');
            }
            $this->sftp = $sftp;
        }
        return $this->sftp;
    }

    /** Mask every credential, raw and XML-escaped, longest first. */
    public function redact(string $text): string
    {
        $secrets = [];
        foreach ($this->accounts as [$u, $p]) {
            foreach ([$u, $p] as $s) {
                if (is_string($s) && $s !== '') {
                    $secrets[] = $s;
                    $secrets[] = htmlspecialchars($s, ENT_XML1 | ENT_QUOTES);
                }
            }
        }
        foreach (['user', 'password'] as $k) {
            if (!empty($this->sftpConfig[$k])) {
                $secrets[] = (string) $this->sftpConfig[$k];
            }
        }
        $secrets = array_unique($secrets);
        usort($secrets, fn ($a, $b) => strlen($b) <=> strlen($a));
        return str_replace($secrets, '***', $text);
    }

    private function httpStatus(string $headers): ?int
    {
        return preg_match('#^HTTP/\S+\s+(\d{3})#', $headers, $m) ? (int) $m[1] : null;
    }

    private function toPlain(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            return array_map(fn ($v) => $this->toPlain($v), $value);
        }
        return $value;
    }
}
