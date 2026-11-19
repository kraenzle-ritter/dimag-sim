<?php

// #ddev-generated (DDEV add-on: "ddev add-on get" may overwrite this copy)

declare(strict_types=1);

namespace DimagSim;

/**
 * HTTP front: the KM-SOAP endpoints and the simulator's own control API.
 *
 *   POST /soap/webservice_3_X_0.php   KM-SOAP (3_0_0 … the configured version)
 *   GET  /_sim/state                   dump of the object tree
 *   POST /_sim/reset                   empty tree, then seed again
 *   POST /_sim/seed                    add objects (JSON list, see README)
 *   POST /_sim/faults                  error switches (JSON, see README)
 *   GET  /_sim/health                  liveness
 */
final class Server
{
    private Store $store;
    private Files $files;

    public function __construct(private readonly Config $config)
    {
        $this->store = new Store($config->stateFile);
        $this->files = new Files($config->sftpDir, dirname($config->stateFile) . '/primary');
    }

    /** @return array{0:int, 1:array<string,string>, 2:string} status, headers, body */
    public function handle(string $method, string $path, string $body): array
    {
        if (str_starts_with($path, '/_sim/')) {
            return $this->admin($method, substr($path, 6), $body);
        }
        if (preg_match('#^/soap/(webservice_\d+_\d+_\d+)\.(php|wsdl)$#', $path, $m)) {
            if (!$this->config->servesEndpoint($m[1] . '.php')) {
                return [404, ['Content-Type' => 'text/html'], "<html><body><h1>Not Found</h1></body></html>\n"]; // [recorded]
            }
            if ($m[2] === 'wsdl' || $method !== 'POST') {
                return [404, ['Content-Type' => 'text/plain'], "dimag-sim serves no WSDL; call the endpoint without one (SoapClient(null, …)), as Inge does.\n"];
            }
            return $this->soap($body);
        }
        if ($path === '/' || $path === '') {
            return [200, ['Content-Type' => 'text/plain'], "dimag-sim {$this->config->version}: POST /soap/webservice_" . str_replace('.', '_', $this->config->version) . ".php\n"];
        }
        return [404, ['Content-Type' => 'text/plain'], "not found\n"];
    }

    private function soap(string $body): array
    {
        $xml = ['Content-Type' => 'text/xml; charset=utf-8'];
        try {
            [$operation, $params] = self::parseRequest($body);
        } catch (\Throwable $e) {
            return [500, $xml, Xml::fault('SOAP-ENV:Client', 'Bad Request')];
        }

        $this->store->begin();
        try {
            $this->bootstrap();
            $faults = $this->store->state['faults'];
            if (!empty($faults['delayMs'])) {
                usleep((int) $faults['delayMs'] * 1000);
            }
            if (!empty($faults['down'])) {
                $this->store->release();
                return [503, ['Content-Type' => 'text/html'], "<html><body><h1>Service Unavailable</h1></body></html>\n"];
            }
            $dimag = new Dimag($this->config, $this->store, $this->files);
            $result = $this->dispatch($dimag, $operation, $params);
            $this->store->commit();
            return [200, $xml, Xml::response($operation, $result)];
        } catch (SimFault $e) {
            $this->store->commit();
            return [500, $xml, Xml::fault('SOAP-ENV:Server', $e->getMessage())];
        } catch (\Throwable $e) {
            $this->store->release();
            return [500, $xml, Xml::fault('SOAP-ENV:Server', 'dimag-sim: ' . $e->getMessage())];
        }
    }

    private function dispatch(Dimag $dimag, string $operation, array $p): array
    {
        $op = strtolower($operation);
        if ($op === 'getstatus') {
            return $dimag->getStatus();
        }
        if ($op === 'getprefixlist') {
            return $dimag->getPrefixList();
        }

        $user = $this->authenticate((string) ($p[0] ?? ''), (string) ($p[1] ?? ''));
        $a = array_slice($p, 2);
        $failed = fn (array $extra) => ['status' => 401, 'msg' => 'Error: Login failed'] + $extra; // [modelled]

        return match ($op) {
            'importdoc' => $user ? $dimag->importDoc($user, (string) ($a[0] ?? '')) : $failed([]),
            'exportdoc' => $user
                ? $dimag->exportDoc($user, (string) ($a[0] ?? ''), (string) ($a[1] ?? 'all'), $a[2] ?? false, $a[3] ?? false, $a[4] ?? false)
                : $failed(['ControlFile' => '']),
            'changestatuscode' => $user ? $dimag->changeStatusCode($user, (string) ($a[0] ?? ''), $a[1] ?? '') : $failed([]),
            'moveobject' => $user ? $dimag->moveObject($user, (string) ($a[0] ?? ''), (string) ($a[1] ?? '')) : $failed([]),
            'getaidforkeyvalue' => $user
                ? $dimag->getAIDforKeyValue($user, (string) ($a[0] ?? ''), (string) ($a[1] ?? ''), (string) ($a[2] ?? 'Any'))
                : $failed(['hits' => '', 'aIDList' => '']),
            'getobjectschangedsince' => $user
                ? $dimag->getObjectsChangedSince($user, (string) ($a[0] ?? ''))
                : $failed(['hits' => '', 'aIDList' => '']),
            default => throw new SimFault("Procedure '$operation' not present"),
        };
    }

    private function authenticate(string $name, string $password): ?array
    {
        $user = $this->config->users[$name] ?? null;
        if (!$user || !hash_equals($user['password'], $password)) {
            return null;
        }
        return $user + ['login' => $name];
    }

    /**
     * Operation name and positional parameters of an RPC request. Parameter
     * names are ignored: Inge sends param0, param1 …, WSDL clients send
     * username, password …
     */
    public static function parseRequest(string $body): array
    {
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($body, LIBXML_NONET)) {
            throw new \InvalidArgumentException('not XML');
        }
        $bodies = $dom->getElementsByTagNameNS('http://schemas.xmlsoap.org/soap/envelope/', 'Body');
        $call = null;
        foreach ($bodies->item(0)?->childNodes ?? [] as $n) {
            if ($n instanceof \DOMElement) {
                $call = $n;
                break;
            }
        }
        if (!$call) {
            throw new \InvalidArgumentException('no call');
        }
        $params = [];
        foreach ($call->childNodes as $n) {
            if ($n instanceof \DOMElement) {
                $params[] = $n->textContent;
            }
        }
        return [$call->localName, $params];
    }

    /** seed the tree on first use */
    private function bootstrap(): void
    {
        if ($this->store->initialized()) {
            return;
        }
        (new Seeder($this->config, $this->store, $this->files))->seed();
        $this->store->state['initialized'] = true;
    }

    private function admin(string $method, string $action, string $body): array
    {
        $json = ['Content-Type' => 'application/json'];
        if (getenv('DIMAG_SIM_ADMIN_API') === 'off') {
            return [404, $json, '{"error":"control API disabled"}'];
        }
        if ($action === 'health') {
            return [200, $json, '{"ok":true}'];
        }
        $this->store->begin();
        try {
            switch ("$method $action") {
                case 'GET state':
                    $this->bootstrap();
                    $out = $this->store->state;
                    $this->store->commit();
                    return [200, $json, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
                case 'POST reset':
                    $this->store->state = ['seq' => 0, 'ord' => 0, 'objects' => [], 'faults' => [], 'exports' => 0];
                    $this->files->reset();
                    $this->bootstrap();
                    $this->store->commit();
                    return [200, $json, '{"ok":true}'];
                case 'POST seed':
                    $this->bootstrap();
                    $added = (new Seeder($this->config, $this->store, $this->files))->add(json_decode($body, true, 512, JSON_THROW_ON_ERROR));
                    $this->store->commit();
                    return [200, $json, json_encode(['added' => $added])];
                case 'POST faults':
                    $this->bootstrap();
                    $faults = json_decode($body ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                    $this->store->state['faults'] = $faults;
                    $this->store->commit();
                    return [200, $json, json_encode(['faults' => $faults])];
            }
            $this->store->release();
            return [404, $json, '{"error":"unknown action"}'];
        } catch (\Throwable $e) {
            $this->store->release();
            return [400, $json, json_encode(['error' => $e->getMessage()])];
        }
    }
}
