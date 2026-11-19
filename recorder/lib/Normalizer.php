<?php

declare(strict_types=1);

namespace DimagSim\Recorder;

/**
 * Turns a raw recording into a fixture that can be published and compared:
 * everything that differs between two runs or two installations, and
 * everything that names the installation, becomes a placeholder.
 *
 *   dimagsim-<run>-x      dimagsim-{run}-x
 *   --root AID            {root}, its title {root_titel}, its signature {root_signatur}
 *   <prefix>-<n>          {p1}, {p2} … (AIDs DIMAG generated, e.g. protocol objects)
 *   other AIDs            {other1} …  (objects of the installation, e.g. in withStruct exports)
 *   sftp Path             {sftp_dir}
 *   URL / ArchivName      {url} / {archive}
 *   dates, times, UUIDs   {datetime} {time} {uuid}, export runs /Export/{date}/{export}
 *   ersteller etc.        {user}
 *
 * Extra words to remove (names of the archive, hostnames) are passed as
 * $scrub; they become {scrubbed}.
 */
final class Normalizer
{
    private const PUBLIC_HOSTS = ['schemas.xmlsoap.org', 'www.w3.org', 'dimag.la-bw.de'];

    private array $map = [];
    private int $p = 0;
    private int $other = 0;
    private string $prefix = '';
    private array $context = [];

    public function __construct(private readonly array $scrub = [])
    {
    }

    public function fixture(array $raw): array
    {
        $this->learn($raw);
        $steps = [];
        foreach ($raw['steps'] as $step) {
            $steps[] = $this->step($step);
        }
        return [
            'scenario' => $raw['scenario'],
            'dimag_version' => $this->context['version'] ?? null,
            'xsd_version' => $this->context['xsd'] ?? null,
            'recorded_on' => substr((string) $raw['recorded_at'], 0, 10),
            'soap_path' => $raw['soap_path'] ?? null,
            'known_differences' => $raw['known_differences'] ?? [],
            'steps' => $steps,
        ];
    }

    private function learn(array $raw): void
    {
        $this->context['run'] = $raw['run'];
        $this->context['root'] = $raw['root'] ?? '';
        foreach ($raw['steps'] as $s) {
            $msg = is_array($s['result'] ?? null) ? (string) ($s['result']['msg'] ?? '') : '';
            if (($s['operation'] ?? '') === 'getStatus' && str_starts_with($msg, 'DIMAG-')) {
                preg_match('/^DIMAG-(\S+)/', $msg, $m);
                $this->context['version'] = $m[1] ?? null;
                foreach (['url' => 'URL', 'archive' => 'ArchivName', 'sftp_dir' => 'sftp Path', 'xsd' => 'Import XSD Version', 'prefix' => 'DIMAG prefix'] as $k => $label) {
                    if (preg_match('/' . preg_quote($label, '/') . ':\s*\t([^\n]*)/', $msg, $m)) {
                        $this->context[$k] = trim($m[1]);
                    }
                }
                break;
            }
        }
        $this->prefix = (string) ($this->context['prefix'] ?? '');

        // title and signature of the root, from any exported control file that contains it
        foreach ($raw['steps'] as $s) {
            $content = (string) ($s['content'] ?? '');
            if ($this->context['root'] !== '' && str_contains($content, '<aid>' . $this->context['root'] . '</aid>')) {
                $dom = new \DOMDocument();
                if (@$dom->loadXML($content)) {
                    foreach ($dom->getElementsByTagName('verz-obj') as $el) {
                        $f = $this->fields($el);
                        if (($f['aid'] ?? '') === $this->context['root']) {
                            $this->context['root_titel'] = $f['titel'] ?? null;
                            $this->context['root_signatur'] = $f['signatur'] ?? null;
                        }
                    }
                }
            }
        }
        // the root's signature: the signature of an S below it without the S's own part
        foreach ($raw['steps'] as $s) {
            $content = (string) ($s['content'] ?? '');
            $pattern = '#<pid>' . preg_quote((string) $this->context['root'], '#') . '</pid>\s*<signatur>([^<]*)</signatur>\s*<signatur-anteil>([^<]*)</signatur-anteil>#';
            if ($this->context['root'] !== '' && preg_match_all($pattern, $content, $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    if (str_ends_with($hit[1], ' ' . $hit[2])) {
                        $this->context['root_signatur'] = substr($hit[1], 0, -strlen($hit[2]) - 1);
                        break 2;
                    }
                }
            }
        }
        // from an import message: "Protokoll zu <root>\n<title> updated"
        if (empty($this->context['root_titel']) && $this->context['root'] !== '') {
            foreach ($raw['steps'] as $s) {
                $msg = (string) ($s['result']['msg'] ?? '');
                if (preg_match('/Protokoll zu ' . preg_quote($this->context['root'], '/') . '\n(.*) (?:updated|inserted)<br\/>/', $msg, $m)) {
                    $this->context['root_titel'] = $m[1];
                    break;
                }
            }
        }
    }

    private function step(array $s): array
    {
        unset($s['duration']);
        if (($s['type'] ?? '') === 'sftp') {
            $s['path'] = $this->text((string) $s['path']);
            if (isset($s['content'])) {
                $s['content'] = $this->xmlContent((string) $s['content']);
            }
            if (isset($s['entries'])) {
                $s['entries'] = array_map(fn ($e) => $this->text((string) $e), $s['entries']);
                sort($s['entries']);
            }
            if (isset($s['error'])) {
                $s['error'] = $this->text((string) $s['error']);
            }
            return $s;
        }
        $s['args'] = $this->deep($s['args'] ?? []);
        foreach (['request', 'response'] as $k) {
            if (isset($s[$k])) {
                $s[$k] = $this->text((string) $s[$k]);
            }
        }
        $s['result'] = $this->deep($s['result'] ?? null);
        if (isset($s['fault'])) {
            $s['fault'] = $this->deep($s['fault']);
        }
        if (isset($s['error'])) {
            $s['error'] = $this->text((string) $s['error']);
        }
        return $s;
    }

    private function deep(mixed $v): mixed
    {
        if (is_array($v)) {
            return array_map(fn ($x) => $this->deep($x), $v);
        }
        return is_string($v) ? $this->text($v) : $v;
    }

    /** exported XML: objects that are not ours lose their metadata */
    private function xmlContent(string $content): string
    {
        $content = preg_replace_callback('#<verz-obj>(.*?)(?=<verz-obj>|</verz-obj>)#s', function ($m) {
            if (!preg_match('#<aid>([^<]*)</aid>#', $m[1], $a) || $this->isOurs($a[1])) {
                return $m[0];
            }
            // an object of the installation (the root, or above it): keep the root's aid, type and status only
            $keep = $a[1] === $this->context['root'] ? ['aid', 'typ', 'status-code'] : ['typ', 'status-code'];
            return '<verz-obj>' . preg_replace_callback('#<([a-z-]+)>[^<]*</\1>#',
                fn ($f) => in_array($f[1], $keep, true) ? $f[0] : "<{$f[1]}>{redacted}</{$f[1]}>", $m[1]);
        }, $content);
        return $this->text($content);
    }

    public function text(string $t): string
    {
        $c = $this->context;
        $run = (string) $c['run'];
        $pairs = [];
        foreach (['sftp_dir', 'url', 'archive', 'root_titel', 'root_signatur'] as $k) {
            if (!empty($c[$k])) {
                $pairs[$c[$k]] = '{' . $k . '}';
            }
        }
        // longest first, so the signature "X dimagsim" goes before "X"
        uksort($pairs, fn ($a, $b) => strlen($b) <=> strlen($a));
        $t = strtr($t, $pairs);
        if ($run !== '') {
            $t = str_replace($run, '{run}', $t);
        }
        if ($c['root'] !== '') {
            $t = preg_replace('/(?<![\w-])' . preg_quote($c['root'], '/') . '(?![\w])/', '{root}', $t);
        }
        if ($this->prefix !== '') {
            $t = preg_replace('/(?<=\tDIMAG prefix:      \t)' . preg_quote($this->prefix, '/') . '/', '{prefix}', $t);
            $t = preg_replace_callback('/(?<![\w-])' . preg_quote($this->prefix, '/') . '-\d+(?!\w)/', fn ($m) => $this->alias($m[0], 'p'), $t);
        }
        // hosts other than the public SOAP/XML namespaces
        $t = preg_replace_callback('#https?://([A-Za-z0-9.-]+)(:\d+)?#', fn ($m) => in_array(strtolower($m[1]), self::PUBLIC_HOSTS, true) ? $m[0] : '{url}', $t);
        $t = preg_replace('#/Export/\d{4}/\d{2}/\d{2}/\d{2}\.\d{2}\.\d{2}-\d+#', '/Export/{date}/{export}', $t);
        $t = preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', '{datetime}', $t);
        $t = preg_replace('/\[\d{2}:\d{2}:\d{2}\]/', '[{time}]', $t);
        $t = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', '{uuid}', $t);
        $t = preg_replace('#<(ersteller|geaendert-durch)>[^<]*</\1>#', '<$1>{user}</$1>', $t);
        foreach ($this->scrub as $word) {
            if ($word !== '') {
                $t = str_ireplace($word, '{scrubbed}', $t);
            }
        }
        return $t;
    }

    private function alias(string $aid, string $kind): string
    {
        if (!isset($this->map[$aid])) {
            $this->map[$aid] = '{' . $kind . (++$this->p) . '}';
        }
        return $this->map[$aid];
    }

    private function isOurs(string $aid): bool
    {
        return str_starts_with($aid, 'dimagsim-');
    }

    private function fields(\DOMElement $el): array
    {
        $f = [];
        foreach ($el->childNodes as $n) {
            if ($n instanceof \DOMElement && $n->nodeName !== 'verz-obj') {
                $f[$n->nodeName] = $n->textContent;
            }
        }
        return $f;
    }
}
