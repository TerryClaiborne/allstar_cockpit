<?php
declare(strict_types=1);

namespace AllStarCockpit\Support;

final class AsteriskMonitor
{
    public function __construct(private Config $config)
    {
    }

    public function snapshot(): array
    {
        $public = $this->config->publicConfig();
        $node = $public['node'];
        $configured = $public['configured'];

        $ami = $configured ? $this->runSafe('ami_status', $node) : $this->notConfigured();
        $amiData = $this->decodeAmiStatus($ami);
        $amiOk = ($amiData['ok'] ?? false) === true;
        $fastStatus = $this->config->getBool('FAST_STATUS_ONLY', true);

        $results = [
            'core_uptime' => ['ok' => $amiOk, 'output' => '', 'error' => ''],
            'core_channels' => ['ok' => $amiOk, 'output' => '', 'error' => ''],
            'rpt_nodes' => ['ok' => $amiOk, 'output' => '', 'error' => ''],
            'rpt_stats' => ['ok' => $amiOk, 'output' => '', 'error' => ''],
            'echolink_db' => ['ok' => $amiOk, 'output' => '', 'error' => ''],
            'iax_channels' => ['ok' => $amiOk, 'output' => '', 'error' => ''],
            'ami_status' => $ami,
        ];

        if ($configured && $amiOk) {
            // These app_rpt reads are intentionally kept even in fast AMI mode.
            // Local Monitor can leave AMI/downstream empty while rpt stats still
            // reports the node(s) connected to the local node. The Downstream
            // panel must not be tied only to Transceive.
            $results['rpt_nodes'] = $this->runSafe('rpt_nodes', $node);
            $results['rpt_stats'] = $this->runSafe('rpt_stats', $node);
            // IAX client/channel display needs both the concise channel list
            // and iax2 channel peer table. Keep this read-only.
            $results['core_channels'] = $this->runSafe('core_channels', $node);
            $results['iax_channels'] = $this->runSafe('iax_channels', $node);
        }

        if (!$fastStatus || !$amiOk) {
            $results['core_uptime'] = $this->runSafe('core_uptime', $node);
            $results['core_channels'] = $this->runSafe('core_channels', $node);
            $results['rpt_nodes'] = $configured ? $this->runSafe('rpt_nodes', $node) : $this->notConfigured();
            $results['rpt_stats'] = $configured ? $this->runSafe('rpt_stats', $node) : $this->notConfigured();
            $results['echolink_db'] = $this->runSafe('echolink_db', $node);
        }

        $rxKeyed = false;
        $txKeyed = false;

        if ($amiOk) {
            $connections = $amiData['connections'] ?? [];
            $downstream = $amiData['downstream_links'] ?? [];
            $rxKeyed = (bool)($amiData['rx_keyed'] ?? false);
            $txKeyed = (bool)($amiData['tx_keyed'] ?? false);
        } else {
            $connections = $this->parseFallbackConnections($results, $node);
            $downstream = [];
        }

        if (($results['iax_channels']['ok'] ?? false) === true && ($results['core_channels']['ok'] ?? false) === true) {
            $connections = $this->mergeConnections(
                $connections,
                $this->parseIaxClientConnections(
                    $results['iax_channels']['output'] ?? '',
                    $results['core_channels']['output'] ?? '',
                    $node
                )
            );
        }

        // Include visible direct AllStar connections as downstream candidates too.
        // A Local Monitor connection can be receive-only and still represent a
        // linked network entry point; it should appear in Downstream Nodes.
        $downstream = $this->mergeDownstreamLinks(
            $downstream,
            $this->connectionsAsDownstreamLinks($connections)
        );

        if (($results['rpt_nodes']['ok'] ?? false) === true) {
            $downstream = $this->mergeDownstreamLinks(
                $downstream,
                $this->parseRptNodesDownstream($results['rpt_nodes']['output'] ?? '', $node)
            );
        }

        if (($results['rpt_stats']['ok'] ?? false) === true) {
            $downstream = $this->mergeDownstreamLinks(
                $downstream,
                $this->parseRptStatsDownstream($results['rpt_stats']['output'] ?? '', $node)
            );
        }

        $remoteReportedDownstream = [];
        if ($this->config->getBool('ENABLE_EXTERNAL_LOOKUPS', false)) {
            $remoteReportedDownstream = $this->fetchRemoteReportedDownstream($downstream, $node);
            if ($remoteReportedDownstream) {
                $downstream = $this->mergeDownstreamLinks($downstream, $remoteReportedDownstream);
                $downstream = $this->removeDirectLinksCoveredByRemoteReports($downstream, $remoteReportedDownstream);
            }
        }

        [$connections, $downstream, $hiddenSummary] = $this->filterHiddenNodes($connections, $downstream);
        $downstream = $this->enrichDownstreamLinks($downstream);
        $connections = $this->enrichConnections($connections, $downstream);
        $activity = $this->connectionsAsLiveActivity($connections, $rxKeyed, $txKeyed, $hiddenSummary);

        return [
            'timestamp' => gmdate('c'),
            'config' => $public,
            'services' => [
                'asterisk_cli' => $results['core_uptime']['ok'],
                'allstar_configured' => $configured,
                'echolink_visible' => $results['echolink_db']['ok'] || $amiOk,
                'collector' => true,
                'ami_status' => ($amiData['ok'] ?? false) === true,
            ],
            'current_connections' => $connections,
            'downstream_links' => $downstream,
            'live_activity' => $activity,
            'raw_available' => [
                'ami_status' => ($amiData['ok'] ?? false) === true,
                'core_uptime' => $results['core_uptime']['ok'],
                'core_channels' => $results['core_channels']['ok'],
                'rpt_nodes' => $results['rpt_nodes']['ok'],
                'rpt_stats' => $results['rpt_stats']['ok'],
                'echolink_db' => $results['echolink_db']['ok'],
                'iax_channels' => $results['iax_channels']['ok'],
            ],
            'warnings' => $this->warnings($results, $configured, $amiData),
        ];
    }

    private function decodeAmiStatus(array $result): array
    {
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?? 'AMI helper failed'];
        }
        $data = json_decode($result['output'], true);
        return is_array($data) ? $data : ['ok' => false, 'error' => 'AMI helper returned non-JSON output'];
    }

    private function notConfigured(): array
    {
        return ['ok' => false, 'output' => '', 'error' => 'MYNODE is not configured.'];
    }

    private function runSafe(string $command, string $node = ''): array
    {
        $useSudo = $this->config->getBool('USE_SUDO_HELPER', false);
        $helper = $this->config->getString('HELPER_PATH', $this->config->root() . '/bin/allstar-cockpit-read.sh');
        $asterisk = $this->config->getString('ASTERISK_BIN', '/usr/sbin/asterisk');

        $nodeScopedCommands = ['rpt_nodes' => true, 'rpt_stats' => true, 'ami_status' => true];
        $needsNodeArg = $node !== '' && isset($nodeScopedCommands[$command]);

        if ($useSudo) {
            $cmd = 'sudo ' . escapeshellarg($helper) . ' ' . escapeshellarg($command);
            if ($needsNodeArg) {
                $cmd .= ' ' . escapeshellarg($node);
            }
        } else {
            if (!is_executable($helper)) {
                return ['ok' => false, 'output' => '', 'error' => 'Helper is not executable.'];
            }
            $cmd = 'ASTERISK_BIN=' . escapeshellarg($asterisk) . ' ' . escapeshellarg($helper) . ' ' . escapeshellarg($command);
            if ($needsNodeArg) {
                $cmd .= ' ' . escapeshellarg($node);
            }
        }

        $output = [];
        $code = 0;
        exec($cmd . ' 2>&1', $output, $code);

        return [
            'ok' => $code === 0,
            'output' => implode("\n", $output),
            'error' => $code === 0 ? '' : implode("\n", $output),
        ];
    }

    private function warnings(array $results, bool $configured, array $amiData): array
    {
        $warnings = [];
        if (!$configured) {
            $warnings[] = 'MYNODE is not configured in config.ini.';
        }
        if (!$results['core_uptime']['ok']) {
            $warnings[] = 'Asterisk CLI was not readable by the web process.';
        }
        if (($amiData['ok'] ?? false) !== true) {
            $warnings[] = 'AMI XStat/SawStat collector is not active yet: ' . ($amiData['error'] ?? 'unknown error');
        }
        return $warnings;
    }

    private function filterHiddenNodes(array $connections, array $downstream): array
    {
        $hiddenSummary = [
            'connections' => 0,
            'keyed' => 0,
            'downstream' => 0,
        ];

        $connections = array_values(array_filter($connections, function (array $conn) use (&$hiddenSummary): bool {
            $node = (string)($conn['node'] ?? '');
            if ($node !== '' && $this->config->shouldHideNode($node)) {
                $hiddenSummary['connections']++;
                if (($conn['state'] ?? '') === 'keyed') {
                    $hiddenSummary['keyed']++;
                }
                return false;
            }
            return true;
        }));

        $downstream = array_values(array_filter($downstream, function (array $item) use (&$hiddenSummary): bool {
            $node = (string)($item['node'] ?? '');
            if ($node !== '' && !$this->config->shouldShowDownstreamNode($node)) {
                $hiddenSummary['downstream']++;
                return false;
            }
            return true;
        }));

        return [$connections, $downstream, $hiddenSummary];
    }

    private function parseFallbackConnections(array $results, string $localNode): array
    {
        $connections = [];
        $text = $results['core_channels']['output'] ?? '';
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || !$this->isPossibleRemoteChannel($line)) {
                continue;
            }
            $ip = $this->extractIp($line);
            $node = $this->extractNodeWithoutIpOctets($line, $localNode);
            if ($node === '') {
                continue;
            }
            $source = stripos($line, 'echolink') !== false ? 'EchoLink' : 'AllStar';
            $key = strtolower($source) . ':' . $node;
            $connections[$key] = [
                'key' => $key,
                'source' => $source,
                'node' => $node,
                'label' => ($source === 'EchoLink' ? 'EchoLink ' : 'Node ') . $node,
                'remote' => '',
                'observed_ip' => $this->config->getBool('SHOW_OBSERVED_IPS', false) ? $ip : '',
                'direction' => 'unknown',
                'state' => 'connected',
                'elapsed' => '',
                'link' => '',
                'mode' => 'Unknown',
            ];
        }
        return array_values($connections);
    }

    private function isPossibleRemoteChannel(string $line): bool
    {
        $lower = strtolower($line);
        foreach (['simpleusb/', 'usrp/127.0.0.1', 'pseudo/', 'dahdi/', 'local/'] as $ignored) {
            if (str_contains($lower, $ignored)) return false;
        }
        return str_contains($lower, 'iax2/') || str_contains($lower, 'echolink');
    }

    private function extractNodeWithoutIpOctets(string $line, string $localNode): string
    {
        $withoutIps = preg_replace('/\b[0-9]{1,3}(?:\.[0-9]{1,3}){3}\b/', '', $line) ?? $line;
        if (preg_match('/IAX2\/([0-9]{3,7})(?:[-\/:@]|$)/i', $withoutIps, $m) && $m[1] !== $localNode) {
            return $m[1];
        }
        if (preg_match_all('/\b([0-9]{4,7})\b/', $withoutIps, $matches)) {
            foreach ($matches[1] as $candidate) {
                if ($candidate !== $localNode && !in_array($candidate, ['32001', '34001', '4569', '5038'], true)) {
                    return $candidate;
                }
            }
        }
        return '';
    }

    private function extractIp(string $line): string
    {
        if (preg_match('/\b((?:10|172\.(?:1[6-9]|2[0-9]|3[0-1])|192\.168|100)\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})\b/', $line, $m)) {
            return $m[1];
        }
        if (preg_match('/\b([0-9]{1,3}(?:\.[0-9]{1,3}){3})\b/', $line, $m)) {
            return $m[1];
        }
        return '';
    }

    private function mergeConnections(array $primary, array $extra): array
    {
        $merged = [];
        foreach (array_merge($primary, $extra) as $conn) {
            $key = (string)($conn['key'] ?? '');
            if ($key === '') {
                $source = strtolower((string)($conn['source'] ?? 'conn'));
                $node = (string)($conn['node'] ?? ($conn['iax_channel'] ?? ''));
                $key = $source . ':' . $node;
                $conn['key'] = $key;
            }
            $merged[$key] = $conn;
        }
        return array_values($merged);
    }

    private function parseIaxChannelPeerMap(string $iaxText): array
    {
        $peers = [];

        foreach (preg_split('/\R/', $iaxText) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '' || !str_starts_with($line, 'IAX2/')) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            $channel = trim((string)($parts[0] ?? ''));
            if ($channel === '') {
                continue;
            }

            $peers[$channel] = [
                'peer' => trim((string)($parts[1] ?? '')),
                'username' => trim((string)($parts[2] ?? '')),
            ];
        }

        return $peers;
    }

    private function peerForIaxChannel(array $peerMap, string $channel): array
    {
        if (isset($peerMap[$channel])) {
            return $peerMap[$channel];
        }

        $best = [];
        $bestLen = 0;
        foreach ($peerMap as $prefix => $info) {
            $prefix = (string)$prefix;
            if ($prefix !== '' && str_starts_with($channel, $prefix) && strlen($prefix) > $bestLen) {
                $best = is_array($info) ? $info : [];
                $bestLen = strlen($prefix);
            }
        }

        return $best;
    }

    private function parseIaxClientConnections(string $iaxText, string $coreText, string $localNode): array
    {
        $localNode = preg_replace('/[^0-9]/', '', $localNode) ?? '';
        if ($localNode === '') {
            return [];
        }

        $peerMap = $this->parseIaxChannelPeerMap($iaxText);
        $connections = [];

        foreach (preg_split('/\R/', $coreText) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '' || !str_starts_with($line, 'IAX2/')) {
                continue;
            }

            $parts = explode('!', $line);
            $channel = trim((string)($parts[0] ?? ''));
            $context = strtolower(trim((string)($parts[1] ?? '')));
            $extension = trim((string)($parts[2] ?? ''));
            $state = trim((string)($parts[4] ?? ''));
            $application = trim((string)($parts[5] ?? ''));
            $data = trim((string)($parts[6] ?? ''));
            $durationRaw = trim((string)($parts[11] ?? ''));

            if ($channel === '' || $application !== 'Rpt') {
                continue;
            }

            $runsThisNode = $extension === $localNode || $data === $localNode || str_starts_with($data, $localNode . '|') || str_starts_with($data, $localNode . ',');
            if (!$runsThisNode) {
                continue;
            }

            $isKnownIaxClientContext = in_array($context, ['iaxrpt', 'iax-client', 'iaxclient', 'allstar-public'], true);
            if (!$isKnownIaxClientContext) {
                continue;
            }

            if (str_contains($data, '|X') || str_contains($data, ',X')) {
                $modeRaw = 'T';
            } elseif (str_contains($data, '|P') || str_contains($data, ',P')) {
                $modeRaw = 'P';
            } else {
                $modeRaw = 'R';
            }
            $peer = $this->peerForIaxChannel($peerMap, $channel);
            $peerIp = trim((string)($peer['peer'] ?? ''));
            $username = trim((string)($peer['username'] ?? ''));
            $safeNode = 'iax-channel:' . preg_replace('/[^A-Za-z0-9_.:-]/', ':', $channel);
            $key = 'iax:' . strtolower($channel);

            $connections[$key] = [
                'key' => $key,
                'source' => 'IAX',
                'node' => $safeNode,
                'label' => $username !== '' ? ('IAX Client · ' . $username) : 'IAX Client',
                'remote' => $peerIp,
                'observed_ip' => $this->config->getBool('SHOW_OBSERVED_IPS', false) ? $peerIp : '',
                'direction' => 'IN',
                'raw_direction' => 'IN',
                'state' => strtolower($state) === 'up' ? 'connected' : ($state !== '' ? $state : 'connected'),
                'elapsed' => $durationRaw !== '' && ctype_digit($durationRaw) ? $this->durationLabel((int)$durationRaw) : '',
                'link' => '',
                'mode' => $this->modeLabel($modeRaw),
                'mode_raw' => $modeRaw,
                'connection_type' => 'iax_channel',
                'iax_channel' => $channel,
                'asterisk_channel' => $channel,
                'iax_peer' => $peerIp,
                'iax_username' => $username,
                'iax_context' => $context,
                'iax_extension' => $extension,
                'asterisk_state' => $state,
                'rpt_data' => $data,
            ];
        }

        return array_values($connections);
    }

    private function parseRptNodesDownstream(string $text, string $localNode): array
    {
        $links = [];
        $seen = [];

        foreach (preg_split('/[\s,]+/', $text) ?: [] as $raw) {
            $item = strtoupper(trim((string)$raw, " \t\n\r\0\x0B,;"));
            if ($item === '' || str_contains($item, '*') || str_contains($item, '-')) {
                continue;
            }

            $modeRaw = '';
            $node = '';

            if (preg_match('/^([RTC])([0-9]{3,9})$/', $item, $m)) {
                $modeRaw = $m[1];
                $node = $m[2];
            } elseif (preg_match('/^([0-9]{3,9})([RTC])$/', $item, $m)) {
                $node = $m[1];
                $modeRaw = $m[2];
            } elseif (preg_match('/^[0-9]{3,9}$/', $item)) {
                $node = $item;
            } else {
                // Callsign-only entries can appear in rpt nodes output. They do
                // not give a stable AllStar node number, so skip them here.
                continue;
            }

            if ($node === '' || $node === $localNode || isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;

            $links[] = [
                'node' => $node,
                'mode' => $this->modeLabel($modeRaw),
                'mode_raw' => $modeRaw,
            ];
        }

        return $links;
    }

    private function connectionsAsDownstreamLinks(array $connections): array
    {
        $links = [];
        $seen = [];

        foreach ($connections as $conn) {
            if (($conn['source'] ?? '') !== 'AllStar') {
                continue;
            }

            $node = preg_replace('/[^0-9]/', '', (string)($conn['node'] ?? '')) ?? '';
            if ($node === '' || isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;

            $links[] = [
                'node' => $node,
                'mode' => (string)($conn['mode'] ?? $this->modeLabel((string)($conn['mode_raw'] ?? ''))),
                'mode_raw' => (string)($conn['mode_raw'] ?? ''),
                'state' => (string)($conn['state'] ?? 'connected'),
                'observed_ip' => (string)($conn['observed_ip'] ?? ''),
                'direct_link' => true,
            ];
        }

        return $links;
    }

    private function parseRptStatsDownstream(string $text, string $localNode): array
    {
        $links = [];
        $seen = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (stripos($line, 'Nodes currently connected to us') === false) {
                continue;
            }

            $value = trim((string)(explode(':', $line, 2)[1] ?? ''));
            if ($value === '' || strtoupper($value) === 'N/A' || strtoupper($value) === '<NONE>') {
                return [];
            }

            foreach (preg_split('/[\s,]+/', $value) ?: [] as $raw) {
                $item = strtoupper(trim((string)$raw, " \t\n\r\0\x0B,;."));
                if ($item === '' || str_contains($item, '*') || str_contains($item, '-')) {
                    continue;
                }

                $modeRaw = '';
                $node = '';

                if (preg_match('/^([RTC])([0-9]{3,9})$/', $item, $m)) {
                    $modeRaw = $m[1];
                    $node = $m[2];
                } elseif (preg_match('/^([0-9]{3,9})([RTC])$/', $item, $m)) {
                    $node = $m[1];
                    $modeRaw = $m[2];
                } elseif (preg_match('/^[0-9]{3,9}$/', $item)) {
                    $node = $item;
                }

                if ($node === '' || $node === $localNode || isset($seen[$node])) {
                    continue;
                }
                $seen[$node] = true;

                $links[] = [
                    'node' => $node,
                    'mode' => $this->modeLabel($modeRaw),
                    'mode_raw' => $modeRaw,
                    'direct_link' => true,
                ];
            }

            break;
        }

        return $links;
    }

    private function mergeDownstreamLinks(array $primary, array $extra): array
    {
        $merged = [];
        $index = [];

        foreach (array_merge($primary, $extra) as $item) {
            $node = preg_replace('/[^0-9]/', '', (string)($item['node'] ?? '')) ?? '';
            if ($node === '') {
                continue;
            }

            if (!isset($index[$node])) {
                $index[$node] = count($merged);
                $item['node'] = $node;
                $merged[] = $item;
                continue;
            }

            $i = $index[$node];
            $existingRaw = strtoupper((string)($merged[$i]['mode_raw'] ?? ''));
            $incomingRaw = strtoupper((string)($item['mode_raw'] ?? ''));
            if ($existingRaw === '' && $incomingRaw !== '') {
                $merged[$i]['mode_raw'] = $incomingRaw;
                $merged[$i]['mode'] = $this->modeLabel($incomingRaw);
            }

            foreach ($item as $key => $value) {
                if ($value === null || $value === '' || $value === []) {
                    continue;
                }
                if (!array_key_exists($key, $merged[$i]) || $merged[$i][$key] === '' || $merged[$i][$key] === null || $merged[$i][$key] === []) {
                    $merged[$i][$key] = $value;
                }
            }
        }

        return $merged;
    }

    private function fetchRemoteReportedDownstream(array $downstream, string $localNode): array
    {
        $localNode = preg_replace('/[^0-9]/', '', $localNode) ?? '';
        if ($localNode === '') {
            return [];
        }

        // Local Monitor usually exposes only the direct monitored node locally.
        // To show the larger remote linked network, walk the AllStarLink stats
        // API outward from that direct node. Keep this depth-limited and cached
        // so dashboard polling does not hammer stats.allstarlink.org.
        $maxDepth = max(1, min(3, $this->config->getInt('REMOTE_DOWNSTREAM_LOOKUP_DEPTH', 2)));
        $maxApiNodes = max(1, min(25, $this->config->getInt('REMOTE_DOWNSTREAM_LOOKUP_LIMIT', 12)));

        $queue = [];
        $queued = [];
        $visited = [];
        $links = [];

        foreach ($downstream as $item) {
            if (($item['direct_link'] ?? false) !== true) {
                continue;
            }

            $node = preg_replace('/[^0-9]/', '', (string)($item['node'] ?? '')) ?? '';
            if ($node === '' || $node === $localNode || isset($queued[$node]) || !$this->config->shouldShowDownstreamNode($node)) {
                continue;
            }

            $modeRaw = strtoupper(trim((string)($item['mode_raw'] ?? '')));
            if (!in_array($modeRaw, ['R', 'T', 'C'], true)) {
                $modeRaw = '';
            }

            $queue[] = [
                'node' => $node,
                'depth' => 1,
                'path_mode_raw' => $modeRaw,
            ];
            $queued[$node] = true;
        }

        $apiReads = 0;
        while ($queue && $apiReads < $maxApiNodes) {
            $current = array_shift($queue);
            $remoteNode = (string)($current['node'] ?? '');
            $depth = (int)($current['depth'] ?? 1);
            $pathModeRaw = strtoupper((string)($current['path_mode_raw'] ?? ''));

            if ($remoteNode === '' || $remoteNode === $localNode || isset($visited[$remoteNode])) {
                continue;
            }
            $visited[$remoteNode] = true;
            $apiReads++;

            $stats = $this->readAllStarStatsApi($remoteNode);
            if (!is_array($stats)) {
                continue;
            }

            $remoteLinks = $this->parseAllStarStatsDownstream($stats, $remoteNode, $localNode, $pathModeRaw);
            if ($remoteLinks) {
                $links = $this->mergeDownstreamLinks($links, $remoteLinks);
            }

            if ($depth >= $maxDepth) {
                continue;
            }

            foreach ($this->statsLinkedNodeNames($stats) as $nextNode) {
                if ($nextNode === $localNode || $nextNode === $remoteNode || isset($queued[$nextNode]) || isset($visited[$nextNode]) || !$this->config->shouldShowDownstreamNode($nextNode)) {
                    continue;
                }
                $queue[] = [
                    'node' => $nextNode,
                    'depth' => $depth + 1,
                    'path_mode_raw' => $pathModeRaw,
                ];
                $queued[$nextNode] = true;
            }
        }

        return $links;
    }

    private function readAllStarStatsApi(string $node): ?array
    {
        $node = preg_replace('/[^0-9]/', '', $node) ?? '';
        if ($node === '') {
            return null;
        }

        $cacheSeconds = max(10, $this->config->getInt('EXTERNAL_LOOKUP_CACHE_SECONDS', 30));
        $cacheDir = $this->config->path('CACHE_DIR', 'data/cache');
        $cacheFile = rtrim($cacheDir, '/') . '/asl-stats-' . $node . '.json';

        if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) <= $cacheSeconds) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $url = 'https://stats.allstarlink.org/api/stats/' . rawurlencode($node);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 2,
                'header' => "User-Agent: AllStar-Cockpit/remote-downstream\r\nAccept: application/json\r\n",
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        if (is_dir($cacheDir) && is_writable($cacheDir)) {
            @file_put_contents($cacheFile, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return $decoded;
    }

    private function parseAllStarStatsDownstream(array $stats, string $sourceNode, string $localNode, string $sourceModeRaw = ''): array
    {
        $data = $stats['stats']['data'] ?? [];
        if (!is_array($data)) {
            return [];
        }

        $modeByNode = $this->statsModeMap((string)($data['nodes'] ?? ''));
        $linkedNodes = $data['linkedNodes'] ?? [];
        if (!is_array($linkedNodes)) {
            $linkedNodes = [];
        }

        $links = [];
        foreach ($linkedNodes as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $rawName = trim((string)($entry['name'] ?? ''));
            if (!preg_match('/^[0-9]{3,9}$/', $rawName)) {
                continue;
            }
            $node = $rawName;
            if ($node === $localNode || $node === $sourceNode) {
                continue;
            }

            // The stats API reports link modes from the remote node's point of view.
            // For this dashboard, the downstream mode should reflect our direct
            // path into that remote network. If our direct link is Local Monitor,
            // every remote-reported downstream row is receive-only from here.
            $modeRaw = in_array($sourceModeRaw, ['R', 'T'], true) ? $sourceModeRaw : (string)($modeByNode[$node] ?? '');
            $callsign = trim((string)($entry['callsign'] ?? ($entry['User_ID'] ?? '')));
            $frequency = trim((string)($entry['node_frequency'] ?? ''));
            $tone = trim((string)($entry['node_tone'] ?? ''));
            $server = $entry['server'] ?? [];
            $location = '';
            if (is_array($server)) {
                $location = trim((string)($server['Location'] ?? ($server['SiteName'] ?? '')));
            }

            $links[] = [
                'node' => $node,
                'mode' => $this->modeLabel($modeRaw),
                'mode_raw' => $modeRaw,
                'callsign' => $callsign,
                'description' => $frequency !== '' ? $frequency : '',
                'frequency' => $frequency,
                'ctcss' => $tone,
                'location' => $location,
                'remote_reported' => true,
                'remote_source_node' => $sourceNode,
                'lookup_note' => 'AllStarLink stats API',
            ];
        }

        return $links;
    }


    private function statsLinkedNodeNames(array $stats): array
    {
        $data = $stats['stats']['data'] ?? [];
        if (!is_array($data)) {
            return [];
        }

        $nodes = [];
        foreach (($data['links'] ?? []) as $raw) {
            $raw = trim((string)$raw);
            if (preg_match('/^[0-9]{3,9}$/', $raw)) {
                $nodes[$raw] = true;
            }
        }

        $linkedNodes = $data['linkedNodes'] ?? [];
        if (is_array($linkedNodes)) {
            foreach ($linkedNodes as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $raw = trim((string)($entry['name'] ?? ''));
                if (preg_match('/^[0-9]{3,9}$/', $raw)) {
                    $nodes[$raw] = true;
                }
            }
        }

        return array_keys($nodes);
    }

    private function statsModeMap(string $nodes): array
    {
        $map = [];
        foreach (preg_split('/[\s,]+/', strtoupper($nodes)) ?: [] as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }

            if (preg_match('/^([RTC])([0-9]{3,9})$/', $raw, $m)) {
                $map[$m[2]] = $m[1];
            } elseif (preg_match('/^([0-9]{3,9})([RTC])$/', $raw, $m)) {
                $map[$m[1]] = $m[2];
            }
        }
        return $map;
    }

    private function removeDirectLinksCoveredByRemoteReports(array $downstream, array $remoteReported): array
    {
        $sources = [];
        foreach ($remoteReported as $item) {
            $source = preg_replace('/[^0-9]/', '', (string)($item['remote_source_node'] ?? '')) ?? '';
            if ($source !== '') {
                $sources[$source] = true;
            }
        }

        if (!$sources) {
            return $downstream;
        }

        return array_values(array_filter($downstream, function (array $item) use ($sources): bool {
            $node = preg_replace('/[^0-9]/', '', (string)($item['node'] ?? '')) ?? '';
            if ($node === '') {
                return false;
            }
            return !(($item['direct_link'] ?? false) === true && isset($sources[$node]));
        }));
    }

    private function durationLabel(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    private function modeLabel(string $modeRaw): string
    {
        return match (strtoupper(trim($modeRaw))) {
            'R' => 'Local Monitor',
            'T' => 'Transceive',
            'C' => 'Connecting',
            'P' => 'Phone / IAX Client',
            default => 'Unknown',
        };
    }

    private function enrichDownstreamLinks(array $downstream): array
    {
        if (!$downstream) {
            return [];
        }

        $lookup = new NodeLookup($this->config);
        $seen = [];
        $enriched = [];

        foreach ($downstream as $item) {
            $node = preg_replace('/[^0-9]/', '', (string)($item['node'] ?? '')) ?? '';
            if ($node === '' || isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;

            $detail = $lookup->lookup($node);
            $callsign = trim((string)($item['callsign'] ?? ''));
            if ($callsign === '') {
                $callsign = trim((string)($detail['callsign'] ?? ''));
            }
            $description = trim((string)($item['description'] ?? ''));
            if ($description === '') {
                $description = trim((string)($detail['description'] ?? ''));
            }
            $location = trim((string)($item['location'] ?? ''));
            if ($location === '') {
                $location = trim((string)($detail['location'] ?? ''));
            }

            $item['node'] = $node;
            $item['source'] = 'AllStar';
            $item['callsign'] = $callsign;
            $item['description'] = $description;
            $item['location'] = $location;
            $item['label'] = $callsign !== '' ? ('Node ' . $node . ' · ' . $callsign) : ('Node ' . $node);
            $item['allstar_node_url'] = (string)($detail['allstar_node_url'] ?? ('https://stats.allstarlink.org/stats/' . rawurlencode($node)));
            $item['callsign_url'] = (string)($detail['callsign_url'] ?? '');
            $item['qrz_callsign'] = (string)($detail['qrz_callsign'] ?? '');
            $item['lookup_note'] = $callsign !== '' ? 'local/cache' : 'node only';

            $enriched[] = $item;
        }

        usort($enriched, function (array $a, array $b): int {
            $ma = strtoupper((string)($a['mode_raw'] ?? ''));
            $mb = strtoupper((string)($b['mode_raw'] ?? ''));
            $pa = $ma === 'T' ? 0 : ($ma === 'R' ? 1 : 2);
            $pb = $mb === 'T' ? 0 : ($mb === 'R' ? 1 : 2);
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return (int)($a['node'] ?? 0) <=> (int)($b['node'] ?? 0);
        });

        return $enriched;
    }

    private function enrichConnections(array $connections, array $downstream): array
    {
        $lookup = new NodeLookup($this->config);
        $allLinked = [];

        foreach ($downstream as $item) {
            $n = (string)($item['node'] ?? '');
            if ($n !== '') {
                $allLinked[] = $item;
            }
        }

        foreach ($connections as &$conn) {
            $node = (string)($conn['node'] ?? '');
            if ($node === '') {
                continue;
            }

            if (($conn['source'] ?? '') === 'IAX' || ($conn['connection_type'] ?? '') === 'iax_channel') {
                $conn['label'] = (string)($conn['label'] ?? 'IAX Client');
                $conn['linked_nodes_seen'] = $allLinked;
                continue;
            }

            $detail = $lookup->lookup($node);
            $callsign = (string)($detail['callsign'] ?? '');
            $description = (string)($detail['description'] ?? '');
            $location = (string)($detail['location'] ?? '');

            if (($conn['source'] ?? '') === 'EchoLink') {
                $conn['allstar_node_url'] = '';
            } else {
                $conn['allstar_node_url'] = (string)($detail['allstar_node_url'] ?? ('https://stats.allstarlink.org/stats/' . rawurlencode($node)));
            }

            if ($callsign !== '') {
                $conn['callsign'] = $callsign;
                $conn['description'] = $description;
                $conn['location'] = $location;
                $conn['callsign_url'] = (string)($detail['callsign_url'] ?? '');
                $conn['label'] = 'Node ' . $node . ' · ' . $callsign;
            }

            $conn['linked_nodes_seen'] = $allLinked;
        }
        unset($conn);

        return $connections;
    }

    private function connectionsAsLiveActivity(array $connections, bool $rxKeyed, bool $txKeyed, array $hiddenSummary): array
    {
        $events = [];
        $visibleKeyed = [];

        foreach ($connections as $conn) {
            if (($conn['state'] ?? '') !== 'keyed') {
                continue;
            }
            $visibleKeyed[] = $conn;
        }

        foreach ($visibleKeyed as $conn) {
            $detail = $conn['label'] ?? ($conn['remote'] ?? 'Connected station');
            if (!empty($conn['observed_ip'])) {
                $detail .= ' · IP ' . $conn['observed_ip'];
            }
            if (!empty($conn['elapsed'])) {
                $detail .= ' · ' . $conn['elapsed'];
            }

            $events[] = [
                'ts' => gmdate('c'),
                'type' => 'tx',
                'source' => $conn['source'] ?? 'AllStar',
                'node' => $conn['node'] ?? '',
                'label' => $detail,
                'state' => 'keyed',
                'direction' => $conn['direction'] ?? 'unknown',
                'mode' => $conn['mode'] ?? '',
                'mode_raw' => $conn['mode_raw'] ?? '',
                'observed_ip' => $conn['observed_ip'] ?? '',
                'elapsed' => $conn['elapsed'] ?? '',
                'duration' => $conn['elapsed'] ?? '',
            ];
        }

        if (!$events && $hiddenSummary['keyed'] > 0) {
            $events[] = [
                'ts' => gmdate('c'),
                'type' => 'tx',
                'source' => 'Private Node',
                'label' => 'Private/local node keyed',
                'state' => 'keyed',
                'direction' => 'hidden',
                'duration' => '',
            ];
        }

        $hasVisibleConnections = count($connections) > 0;

        if (!$events && ($rxKeyed || ($txKeyed && !$hasVisibleConnections))) {
            $events[] = [
                'ts' => gmdate('c'),
                'type' => 'tx',
                'source' => 'Local Node',
                'label' => $rxKeyed ? 'Incoming audio keyed' : 'Local transmit keyed',
                'state' => 'keyed',
                'direction' => $rxKeyed ? 'incoming' : 'outgoing',
                'duration' => '',
            ];
        }

        return $events;
    }

}
