<?php
declare(strict_types=1);

namespace AllStarCockpit\Support;

final class EventStore
{
    public function __construct(private Config $config)
    {
    }

    public function activityLog(): string
    {
        return $this->config->path('ACTIVITY_LOG', 'logs/activity.jsonl');
    }

    public function snapshotFile(): string
    {
        return $this->config->path('SNAPSHOT_FILE', 'run/current_snapshot.json');
    }

    public function downstreamHistoryLog(): string
    {
        return $this->config->path('DOWNSTREAM_HISTORY_LOG', 'logs/downstream-history.jsonl');
    }

    public function downstreamCurrentFile(): string
    {
        return $this->config->path('DOWNSTREAM_CURRENT_FILE', 'run/downstream-current.json');
    }

    public function append(array $event): void
    {
        $file = $this->activityLog();
        $dir = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $event = array_merge([
            'ts' => gmdate('c'),
            'id' => bin2hex(random_bytes(6)),
        ], $event);

        file_put_contents($file, json_encode($event, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function readHistory(int $limit = 250): array
    {
        $file = $this->activityLog();
        if (!is_file($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return [];
        }

        $events = [];

        // Read from newest backwards so filtering old private-node entries still leaves enough visible rows.
        for ($i = count($lines) - 1; $i >= 0 && count($events) < max(1, $limit); $i--) {
            $decoded = json_decode($lines[$i], true);
            if (!is_array($decoded)) {
                continue;
            }

            $node = (string)($decoded['node'] ?? '');
            if ($node !== '' && $this->config->shouldHideNode($node)) {
                continue;
            }

            // TX start rows are useful internally while keyed, but they make
            // History noisy after TX End. Live Activity shows active key-down;
            // History keeps completed items such as Connect, TX End, and Disconnect.
            if (($decoded['type'] ?? '') === 'tx') {
                continue;
            }

            $events[] = $decoded;
        }

        return $events;
    }

    public function readDownstreamHistory(int $limit = 250): array
    {
        $file = $this->downstreamHistoryLog();
        if (!is_file($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return [];
        }

        $events = [];
        $cutoff = time() - $this->downstreamRetentionSeconds();

        for ($i = count($lines) - 1; $i >= 0 && count($events) < max(1, $limit); $i--) {
            $decoded = json_decode($lines[$i], true);
            if (!is_array($decoded)) {
                continue;
            }

            $ts = strtotime((string)($decoded['ts'] ?? ''));
            if ($ts !== false && $ts < $cutoff) {
                continue;
            }

            $node = preg_replace('/[^0-9]/', '', (string)($decoded['node'] ?? '')) ?? '';
            if ($node !== '' && !$this->config->shouldShowDownstreamNode($node)) {
                continue;
            }

            $events[] = $decoded;
        }

        return $events;
    }



    private function downstreamHistoryHasRows(): bool
    {
        $file = $this->downstreamHistoryLog();
        if (!is_file($file)) {
            return false;
        }

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return false;
        }

        while (($line = fgets($handle)) !== false) {
            if (trim($line) !== '') {
                fclose($handle);
                return true;
            }
        }

        fclose($handle);
        return false;
    }


    public function clearDownstreamHistory(): void
    {
        $history = $this->downstreamHistoryLog();
        $historyDir = dirname($history);
        if (!is_dir($historyDir)) {
            mkdir($historyDir, 0775, true);
        }
        file_put_contents($history, '', LOCK_EX);

        $current = $this->downstreamCurrentFile();
        $currentDir = dirname($current);
        if (!is_dir($currentDir)) {
            mkdir($currentDir, 0775, true);
        }
        file_put_contents($current, json_encode([
            'updated_at' => gmdate('c'),
            'nodes' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public function loadLastSnapshot(): array
    {
        $file = $this->snapshotFile();
        if (!is_file($file)) {
            return ['connections' => [], 'connection_state' => []];
        }

        $decoded = json_decode((string)file_get_contents($file), true);
        return is_array($decoded) ? $decoded : ['connections' => [], 'connection_state' => []];
    }

    public function saveSnapshot(array $snapshot): void
    {
        $file = $this->snapshotFile();
        $dir = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($file, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function loadDownstreamCurrent(): array
    {
        $file = $this->downstreamCurrentFile();
        if (!is_file($file)) {
            return ['nodes' => []];
        }

        $decoded = json_decode((string)file_get_contents($file), true);
        return is_array($decoded) ? $decoded : ['nodes' => []];
    }

    private function saveDownstreamCurrent(array $state): void
    {
        $file = $this->downstreamCurrentFile();
        $dir = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public function recordConnectionChanges(array $currentConnections, array $downstreamLinks = []): void
    {
        $last = $this->loadLastSnapshot();
        $lastConnections = $last['connections'] ?? [];
        $lastState = $last['connection_state'] ?? [];
        $now = time();

        $lastByKey = [];
        foreach ($lastConnections as $conn) {
            if (isset($conn['key'])) {
                $lastByKey[$conn['key']] = $conn;
            }
        }

        $currentByKey = [];
        foreach ($currentConnections as $conn) {
            if (isset($conn['key'])) {
                $node = (string)($conn['node'] ?? '');
                if ($node !== '' && $this->config->shouldHideNode($node)) {
                    continue;
                }
                $currentByKey[$conn['key']] = $conn;
            }
        }

        $nextState = [];

        foreach ($currentByKey as $key => $conn) {
            $old = $lastByKey[$key] ?? null;
            $oldState = $old['state'] ?? '';
            $newState = $conn['state'] ?? 'connected';
            $connectedAt = (int)($lastState[$key]['connected_at'] ?? $now);
            $keyedAt = $lastState[$key]['keyed_at'] ?? null;

            if ($old === null) {
                $this->append($this->eventPayload('connect', $conn));
                $connectedAt = $now;
            }

            if ($old !== null && $oldState !== $newState) {
                if ($newState === 'keyed') {
                    $this->append($this->eventPayload('tx', $conn));
                    $keyedAt = $now;
                } elseif ($oldState === 'keyed') {
                    $duration = is_int($keyedAt) ? max(1, $now - $keyedAt) : null;
                    $payload = $this->eventPayload('tx_end', $conn);
                    if ($duration !== null) {
                        $payload['duration_seconds'] = $duration;
                        $payload['duration'] = $this->durationLabel($duration);
                    }
                    $this->append($payload);
                    $keyedAt = null;
                } else {
                    $payload = $this->eventPayload('state', $conn);
                    $payload['state'] = $newState;
                    $this->append($payload);
                }
            }

            if ($newState !== 'keyed') {
                $keyedAt = null;
            }

            $nextState[$key] = [
                'connected_at' => $connectedAt,
                'keyed_at' => $keyedAt,
            ];
        }

        foreach ($lastByKey as $key => $conn) {
            $node = (string)($conn['node'] ?? '');
            if ($node !== '' && $this->config->shouldHideNode($node)) {
                continue;
            }

            if (!isset($currentByKey[$key])) {
                if (($conn['state'] ?? '') === 'keyed') {
                    $keyedAt = $lastState[$key]['keyed_at'] ?? null;
                    $duration = is_int($keyedAt) ? max(1, $now - $keyedAt) : null;
                    $payload = $this->eventPayload('tx_end', $conn);
                    if ($duration !== null) {
                        $payload['duration_seconds'] = $duration;
                        $payload['duration'] = $this->durationLabel($duration);
                    }
                    $this->append($payload);
                }

                $this->append($this->eventPayload('disconnect', $conn));
            }
        }

        $downstreamLinks = array_values(array_filter($downstreamLinks, function (array $item): bool {
            $node = (string)($item['node'] ?? '');
            return $node !== '' && $this->config->shouldShowDownstreamNode($node);
        }));

        $this->recordDownstreamChanges($downstreamLinks, $now);

        $this->saveSnapshot([
            'updated_at' => gmdate('c'),
            'connections' => array_values($currentByKey),
            'downstream_links' => $downstreamLinks,
            'connection_state' => $nextState,
        ]);
    }

    private function recordDownstreamChanges(array $downstreamLinks, int $now): void
    {
        $last = $this->loadDownstreamCurrent();
        $lastNodes = is_array($last['nodes'] ?? null) ? $last['nodes'] : [];
        $current = [];
        $historyWasEmpty = !$this->downstreamHistoryHasRows();
        $goneAfterMisses = max(1, $this->config->getInt('DOWNSTREAM_GONE_MISSES', 3));

        foreach ($downstreamLinks as $item) {
            $node = preg_replace('/[^0-9]/', '', (string)($item['node'] ?? '')) ?? '';
            if ($node === '' || !$this->config->shouldShowDownstreamNode($node)) {
                continue;
            }

            $key = 'node:' . $node;
            $old = is_array($lastNodes[$key] ?? null) ? $lastNodes[$key] : null;
            $firstSeen = (int)($old['first_seen_epoch'] ?? $now);
            $oldModeRaw = strtoupper((string)($old['mode_raw'] ?? ''));
            $newModeRaw = strtoupper((string)($item['mode_raw'] ?? ''));

            if ($old === null || $historyWasEmpty) {
                $this->appendDownstreamEvent($this->downstreamEventPayload('downstream_seen', $item, $now, $firstSeen, $now));
                if ($old === null) {
                    $firstSeen = $now;
                }
            } elseif ($oldModeRaw !== '' && $newModeRaw !== '' && $oldModeRaw !== $newModeRaw) {
                $payload = $this->downstreamEventPayload('downstream_mode', $item, $now, $firstSeen, $now);
                $payload['previous_mode_raw'] = $oldModeRaw;
                $payload['previous_mode'] = $this->modeLabel($oldModeRaw);
                $this->appendDownstreamEvent($payload);
            }

            $current[$key] = $this->downstreamStatePayload($item, $firstSeen, $now, 0);
        }

        foreach ($lastNodes as $key => $old) {
            if (isset($current[$key]) || !is_array($old)) {
                continue;
            }

            $missed = (int)($old['missed_count'] ?? 0) + 1;
            if ($missed < $goneAfterMisses) {
                $old['missed_count'] = $missed;
                $current[$key] = $old;
                continue;
            }

            $lastSeen = (int)($old['last_seen_epoch'] ?? $now);
            $firstSeen = (int)($old['first_seen_epoch'] ?? $lastSeen);
            $duration = max(0, $lastSeen - $firstSeen);
            $this->appendDownstreamEvent($this->downstreamEventPayload('downstream_gone', $old, $now, $firstSeen, $lastSeen, $duration));
        }

        $this->saveDownstreamCurrent([
            'updated_at' => gmdate('c', $now),
            'nodes' => $current,
        ]);

        $this->pruneDownstreamHistory($now);
    }

    private function downstreamStatePayload(array $item, int $firstSeen, int $lastSeen, int $missedCount): array
    {
        $node = preg_replace('/[^0-9]/', '', (string)($item['node'] ?? '')) ?? '';
        return [
            'node' => $node,
            'label' => $item['label'] ?? '',
            'callsign' => $item['callsign'] ?? '',
            'mode' => $item['mode'] ?? '',
            'mode_raw' => $item['mode_raw'] ?? '',
            'location' => $item['location'] ?? '',
            'description' => $item['description'] ?? '',
            'allstar_node_url' => $item['allstar_node_url'] ?? '',
            'callsign_url' => $item['callsign_url'] ?? '',
            'direct_link' => (bool)($item['direct_link'] ?? false),
            'remote_reported' => (bool)($item['remote_reported'] ?? false),
            'remote_source_node' => $item['remote_source_node'] ?? '',
            'lookup_note' => $item['lookup_note'] ?? '',
            'first_seen_epoch' => $firstSeen,
            'first_seen' => gmdate('c', $firstSeen),
            'last_seen_epoch' => $lastSeen,
            'last_seen' => gmdate('c', $lastSeen),
            'missed_count' => $missedCount,
        ];
    }

    private function appendDownstreamEvent(array $event): void
    {
        $file = $this->downstreamHistoryLog();
        $dir = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $event = array_merge([
            'ts' => gmdate('c'),
            'id' => bin2hex(random_bytes(6)),
        ], $event);

        file_put_contents($file, json_encode($event, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function downstreamEventPayload(string $type, array $item, int $eventTime, int $firstSeen, int $lastSeen, ?int $duration = null): array
    {
        $node = preg_replace('/[^0-9]/', '', (string)($item['node'] ?? '')) ?? '';
        $sourceNode = preg_replace('/[^0-9]/', '', (string)($item['remote_source_node'] ?? '')) ?? '';
        $origin = ($item['remote_reported'] ?? false) ? 'remote-stats' : (($item['direct_link'] ?? false) ? 'local-asterisk' : 'local-status');

        $payload = [
            'type' => $type,
            'ts' => gmdate('c', $eventTime),
            'source' => 'Downstream',
            'node' => $node,
            'label' => $item['label'] ?? '',
            'callsign' => $item['callsign'] ?? '',
            'allstar_node_url' => $item['allstar_node_url'] ?? '',
            'callsign_url' => $item['callsign_url'] ?? '',
            'mode' => $item['mode'] ?? '',
            'mode_raw' => $item['mode_raw'] ?? '',
            'location' => $item['location'] ?? '',
            'description' => $item['description'] ?? '',
            'origin' => $origin,
            'direct_node' => $sourceNode,
            'remote_source_node' => $sourceNode,
            'lookup_note' => $item['lookup_note'] ?? '',
            'first_seen' => gmdate('c', $firstSeen),
            'last_seen' => gmdate('c', $lastSeen),
        ];

        if ($duration !== null) {
            $payload['duration_seconds'] = $duration;
            $payload['duration'] = $this->durationLabel($duration);
        }

        return $payload;
    }

    private function pruneDownstreamHistory(int $now): void
    {
        $file = $this->downstreamHistoryLog();
        if (!is_file($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return;
        }

        $cutoff = $now - $this->downstreamRetentionSeconds();
        $kept = [];

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }
            $ts = strtotime((string)($decoded['ts'] ?? ''));
            if ($ts === false || $ts >= $cutoff) {
                $kept[] = json_encode($decoded, JSON_UNESCAPED_SLASHES);
            }
        }

        if (count($kept) !== count($lines)) {
            file_put_contents($file, implode(PHP_EOL, $kept) . ($kept ? PHP_EOL : ''), LOCK_EX);
        }
    }

    private function downstreamRetentionSeconds(): int
    {
        return max(1, $this->config->getInt('DOWNSTREAM_HISTORY_RETENTION_HOURS', 48)) * 3600;
    }

    private function eventPayload(string $type, array $conn): array
    {
        $payload = [
            'type' => $type,
            'source' => $conn['source'] ?? 'unknown',
            'node' => $conn['node'] ?? '',
            'label' => $conn['label'] ?? '',
            'callsign' => $conn['callsign'] ?? '',
            'allstar_node_url' => $conn['allstar_node_url'] ?? '',
            'callsign_url' => $conn['callsign_url'] ?? '',
            'remote' => $conn['remote'] ?? '',
            'observed_ip' => $conn['observed_ip'] ?? '',
            'direction' => $conn['direction'] ?? 'unknown',
            'raw_direction' => $conn['raw_direction'] ?? '',
            'state' => $conn['state'] ?? '',
            'mode' => $conn['mode'] ?? '',
            'mode_raw' => $conn['mode_raw'] ?? '',
            'connection_time' => $conn['elapsed'] ?? '',
            'elapsed' => $conn['elapsed'] ?? '',
            'connection_type' => $conn['connection_type'] ?? '',
            'asterisk_channel' => $conn['asterisk_channel'] ?? '',
            'rpt_data' => $conn['rpt_data'] ?? '',
        ];

        foreach ([
            'iax_channel',
            'iax_peer',
            'iax_username',
            'iax_context',
            'iax_extension',
            'iax_state',
            'iax_lag',
            'iax_jitter',
            'iax_format',
        ] as $field) {
            if (isset($conn[$field]) && $conn[$field] !== '') {
                $payload[$field] = $conn[$field];
            }
        }

        return $payload;
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

    private function durationLabel(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }
}
