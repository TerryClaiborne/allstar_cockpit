<?php
declare(strict_types=1);

namespace AllStarCockpit\Support;

final class NodeLookup
{
    private ?array $astDbCache = null;

    public function __construct(private Config $config)
    {
    }

    public function lookup(string $node): array
    {
        $node = preg_replace('/[^0-9]/', '', $node) ?? '';
        if ($node === '') {
            return [
                'ok' => false,
                'error' => 'No node supplied.',
            ];
        }

        $db = $this->lookupAstDb($node);
        $snapshot = $this->snapshotContext($node);

        $displayCallsign = (string)($db['callsign'] ?? ($snapshot['callsign'] ?? ''));
        $qrzCallsign = $this->qrzCallsign($displayCallsign);
        $qrz = $qrzCallsign !== '' ? 'https://www.qrz.com/db/' . rawurlencode($qrzCallsign) : '';

        return [
            'ok' => true,
            'node' => $node,
            'allstar_node_url' => $this->isEchoLinkNode($node) ? '' : $this->allstarNodeUrl($node),
            'echolink_node' => $this->isEchoLinkNode($node),
            'echolink_display_id' => $this->isEchoLinkNode($node) ? $this->echoLinkDisplayId($node) : '',
            'callsign' => $displayCallsign,
            'qrz_callsign' => $qrzCallsign,
            'callsign_url' => $qrz,
            'description' => (string)($db['description'] ?? ($snapshot['description'] ?? '')),
            'location' => (string)($db['location'] ?? ($snapshot['location'] ?? '')),
            'status' => (string)($snapshot['state'] ?? 'local-observed'),
            'connected_since' => (string)($snapshot['connected_since'] ?? ''),
            'last_heard' => (string)($snapshot['last_heard'] ?? ''),
            'last_tx_duration' => (string)($snapshot['last_tx_duration'] ?? ''),
            'observed_ip' => $this->config->getBool('SHOW_OBSERVED_IPS', false) ? (string)($snapshot['observed_ip'] ?? '') : 'hidden',
            'direction' => (string)($snapshot['direction'] ?? ''),
            'elapsed' => (string)($snapshot['elapsed'] ?? ''),
            'link' => (string)($snapshot['link'] ?? ''),
            'mode' => (string)($snapshot['mode'] ?? ''),
            'downstream_links' => $snapshot['downstream_links'] ?? [],
            'linked_nodes_seen' => $snapshot['linked_nodes_seen'] ?? [],
            'note' => $displayCallsign !== ''
                ? 'Callsign is displayed as reported locally. QRZ lookup uses the base callsign without suffixes such as /R.'
                : 'No local astdb.txt entry was found for this node yet.',
            'external_lookups_enabled' => $this->config->getBool('ENABLE_EXTERNAL_LOOKUPS', false),
        ];
    }

    private function isEchoLinkNode(string $node): bool
    {
        $n = (int)$node;
        return $n >= 3000000 && $n < 4000000;
    }

    private function echoLinkDisplayId(string $node): string
    {
        $n = (int)$node;
        if ($n >= 3000000 && $n < 4000000) {
            return (string)($n - 3000000);
        }
        return $node;
    }

    private function allstarNodeUrl(string $node): string
    {
        return 'https://stats.allstarlink.org/stats/' . rawurlencode($node);
    }

    private function qrzCallsign(string $callsign): string
    {
        $callsign = strtoupper(trim($callsign));

        // Keep the display callsign unchanged, but strip QRZ-unfriendly suffixes
        // such as KC3KMV/R, KC3KMV-L, or KC3KMV-R for the QRZ URL target.
        $callsign = preg_replace('/\/[A-Z0-9]+$/', '', $callsign) ?? $callsign;
        $callsign = preg_replace('/-(?:R|L|M|P|PORTABLE|MOBILE)$/', '', $callsign) ?? $callsign;

        // If the local database includes words after the callsign, use only the
        // first callsign-looking token.
        if (preg_match('/\b([A-Z]{1,3}[0-9][A-Z0-9]{1,4})\b/', $callsign, $m)) {
            return $m[1];
        }

        return preg_replace('/[^A-Z0-9]/', '', $callsign) ?? '';
    }

    private function snapshotContext(string $node): array
    {
        $file = $this->config->path('SNAPSHOT_FILE', 'run/current_snapshot.json');
        if (!is_file($file)) {
            return [];
        }

        $snapshot = json_decode((string)file_get_contents($file), true);
        if (!is_array($snapshot)) {
            return [];
        }

        $context = [
            'linked_nodes_seen' => $snapshot['downstream_links'] ?? [],
        ];

        foreach (($snapshot['connections'] ?? []) as $conn) {
            if ((string)($conn['node'] ?? '') === $node) {
                $context = array_merge($context, $conn);
                break;
            }
        }

        foreach (($snapshot['downstream_links'] ?? []) as $linked) {
            if ((string)($linked['node'] ?? '') === $node) {
                $context = array_merge($context, $linked);
                break;
            }
        }

        return $context;
    }

    private function lookupAstDb(string $node): array
    {
        $cache = $this->astDbCache();
        return $cache[$node] ?? [];
    }

    private function astDbCache(): array
    {
        if (is_array($this->astDbCache)) {
            return $this->astDbCache;
        }

        $this->astDbCache = [];

        foreach ($this->astDbPaths() as $file) {
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }

            $handle = fopen($file, 'r');
            if (!$handle) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }

                $parts = explode('|', $line);
                $node = preg_replace('/[^0-9]/', '', (string)($parts[0] ?? '')) ?? '';
                if ($node === '' || isset($this->astDbCache[$node])) {
                    continue;
                }

                $this->astDbCache[$node] = [
                    'callsign' => trim((string)($parts[1] ?? '')),
                    'description' => trim((string)($parts[2] ?? '')),
                    'location' => trim((string)($parts[3] ?? '')),
                    'source' => $file,
                ];
            }

            fclose($handle);
        }

        return $this->astDbCache;
    }

    private function astDbPaths(): array
    {
        return [
            $this->config->root() . '/data/astdb.txt',
            '/var/www/html/allscan/astdb.txt',
            '/var/www/html/supermon/astdb.txt',
            '/var/log/asterisk/astdb.txt',
            '/var/lib/asterisk/astdb.txt',
        ];
    }
}
