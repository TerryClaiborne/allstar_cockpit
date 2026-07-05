#!/usr/bin/env php
<?php
declare(strict_types=1);

$node = $argv[1] ?? '';
if (!preg_match('/^[0-9]+$/', $node)) {
    fwrite(STDERR, "ami_status requires numeric node\n");
    exit(2);
}

function parse_ini_sections_loose(string $file): array
{
    $sections = [];
    $section = '';
    foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === ';' || $line[0] === '#') {
            continue;
        }
        if (preg_match('/^\[([^\]]+)\]/', $line, $m)) {
            $section = trim($m[1]);
            $sections[$section] ??= [];
            continue;
        }
        if ($section !== '' && str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $sections[$section][trim($k)] = trim(preg_replace('/[;#].*$/', '', $v));
        }
    }
    return $sections;
}

function find_ami_config(): array
{
    $sections = parse_ini_sections_loose('/etc/asterisk/manager.conf');
    $general = $sections['general'] ?? [];
    $host = $general['bindaddr'] ?? '127.0.0.1';
    if ($host === '0.0.0.0' || $host === '') {
        $host = '127.0.0.1';
    }
    $port = isset($general['port']) && is_numeric($general['port']) ? (int)$general['port'] : 5038;

    foreach ($sections as $name => $values) {
        if (strtolower($name) === 'general') {
            continue;
        }
        if (!empty($values['secret'])) {
            return [$host, $port, $name, $values['secret']];
        }
    }

    throw new RuntimeException('No AMI user with secret found in /etc/asterisk/manager.conf');
}

function read_response($fp, string $actionId, int $timeout = 8): array
{
    $end = time() + $timeout;
    $lines = [];
    $seenAction = false;

    while (time() < $end && !feof($fp)) {
        $line = fgets($fp);
        if ($line === false) {
            usleep(20000);
            continue;
        }
        $line = trim($line);
        if ($line === '') {
            if ($seenAction) {
                break;
            }
            continue;
        }
        if ($line === "ActionID: $actionId") {
            $seenAction = true;
        }
        $lines[] = $line;
    }
    return $lines;
}

function ami_action($fp, array $headers, string $actionId): array
{
    $headers['ActionID'] = $actionId;
    foreach ($headers as $k => $v) {
        fwrite($fp, $k . ': ' . $v . "\r\n");
    }
    fwrite($fp, "\r\n");
    return read_response($fp, $actionId);
}

function keyed_bool(string $value): bool
{
    $v = strtolower(trim($value));
    return in_array($v, ['1', 'yes', 'true', 'keyed', 'tx'], true);
}

function mode_label(string $mode, bool $connectionContext = false, string $link = ''): string
{
    $mode = strtoupper(trim($mode));
    return match ($mode) {
        'R' => 'Local Monitor',
        'T' => 'Transceive',
        'C' => 'Connecting',
        // In app_rpt/AMI, an active Conn line does not always carry an R/T flag.
        // Treat a current AllStar connection with no explicit T flag as Local Monitor.
        // Exact LinkedNodes mode data is still applied first, so Transceive is not lost.
        default => $connectionContext ? 'Local Monitor' : 'Unknown',
    };
}

function parse_linked_node_item(string $item): ?array
{
    $item = strtoupper(trim($item));
    $item = trim($item, " \t\n\r\0\x0B,;");

    if ($item === '') {
        return null;
    }

    // AllScan style / common app_rpt style: T12345, R12345, C12345
    if (preg_match('/^([RTC])([0-9]{3,9})$/', $item, $m)) {
        return ['node' => $m[2], 'mode_raw' => $m[1], 'mode' => mode_label($m[1])];
    }

    // Some status strings may show the mode after the node: 12345T
    if (preg_match('/^([0-9]{3,9})([RTC])$/', $item, $m)) {
        return ['node' => $m[1], 'mode_raw' => $m[2], 'mode' => mode_label($m[2])];
    }

    // Defensive formats: 12345:T, 12345|T, 12345 T
    if (preg_match('/^([0-9]{3,9})\s*[:| ]\s*([RTC])$/', $item, $m)) {
        return ['node' => $m[1], 'mode_raw' => $m[2], 'mode' => mode_label($m[2])];
    }

    // Node only.
    if (preg_match('/^[0-9]{3,9}$/', $item)) {
        return ['node' => $item, 'mode_raw' => '', 'mode' => 'Unknown'];
    }

    return null;
}

function display_direction(string $raw, string $source): string
{
    // Do not flip this value for now. app_rpt/AMI direction needs to be
    // observed across multiple connect scenarios before we normalize it.
    // The UI displays the app_rpt/AMI value, and raw_direction preserves it too.
    return strtoupper(trim($raw));
}

function duration_label(string $seconds): string
{
    if (!is_numeric($seconds)) return $seconds;
    $s = max(0, (int)$seconds);
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    $sec = $s % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $sec);
}

function parse_status(array $xstat, array $sawstat, string $localNode): array
{
    $connections = [];
    $modes = [];
    $downstream = [];
    $rxKeyed = false;
    $txKeyed = false;

    // Pass 1: collect keyed flags and the raw R/T/C mode map from LinkedNodes.
    // Some app_rpt/AMI responses report Conn lines before LinkedNodes, so the
    // connection mode must be filled after this pass instead of guessed early.
    foreach ($xstat as $line) {
        if (preg_match('/Var: RPT_RXKEYED=(.)/', $line, $m)) {
            $rxKeyed = ($m[1] === '1');
        }
        if (preg_match('/Var: RPT_TXKEYED=(.)/', $line, $m)) {
            $txKeyed = ($m[1] === '1');
        }

        if (preg_match('/LinkedNodes: (.*)/', $line, $m)) {
            $items = preg_split('/,\s*/', trim($m[1]));
            foreach ($items as $item) {
                $parsed = parse_linked_node_item((string)$item);
                if (!$parsed) {
                    continue;
                }

                $n = $parsed['node'];
                if ($n === $localNode) {
                    continue;
                }

                $modes[$n] = $parsed['mode_raw'];
                $downstream[] = [
                    'node' => $n,
                    'mode' => $parsed['mode'],
                    'mode_raw' => $parsed['mode_raw'],
                ];
            }
        }
    }

    // Pass 2: build current connections using the completed mode map.
    foreach ($xstat as $line) {
        if (!preg_match('/Conn: (.*)/', $line, $m)) {
            continue;
        }

        $arr = preg_split('/\s+/', trim($m[1]));
        if (!$arr || !isset($arr[0]) || !preg_match('/^[0-9]{3,9}$/', $arr[0])) {
            continue;
        }

        $n = $arr[0];
        if ($n === $localNode) continue;

        $isEchoLink = ((int)$n > 3000000);
        $ip = '';
        $direction = 'unknown';
        $elapsed = '';
        $link = '';

        if ($isEchoLink) {
            $direction = $arr[2] ?? ($arr[1] ?? 'unknown');
            $elapsed = $arr[3] ?? '';
            $link = $arr[4] ?? '';
        } else {
            $ip = $arr[1] ?? '';
            if (isset($arr[5])) {
                $direction = $arr[3] ?? 'unknown';
                $elapsed = $arr[4] ?? '';
                $link = $arr[5] ?? '';
            } else {
                $direction = $arr[2] ?? 'unknown';
                $elapsed = $arr[3] ?? '';
                $link = isset($modes[$n]) ? (($modes[$n] === 'C') ? 'Connecting' : 'Established') : '';
            }
        }

        $source = $isEchoLink ? 'EchoLink' : 'AllStar';
        $modeRaw = $modes[$n] ?? '';
        $mode = mode_label($modeRaw, true, $link);
        if ($source === 'AllStar' && $modeRaw === '' && $mode === 'Local Monitor') {
            // Normalize blank connection mode for the UI and popup.
            // A later exact T/R/C value from LinkedNodes always wins above.
            $modeRaw = 'R';
        }

        $connections[$source . ':' . $n] = [
            'key' => strtolower($source) . ':' . $n,
            'source' => $source,
            'node' => $n,
            'label' => ($isEchoLink ? 'EchoLink ' : 'Node ') . $n,
            'remote' => '',
            'observed_ip' => $ip,
            'direction' => display_direction($direction, $source),
            'raw_direction' => strtoupper($direction),
            'state' => 'connected',
            'elapsed' => duration_label($elapsed),
            'link' => $link,
            'mode' => $mode,
            'mode_raw' => $modeRaw,
            'downstream_links' => [],
        ];
    }

    $keyups = [];
    foreach ($sawstat as $line) {
        if (preg_match('/Conn: (.*)/', $line, $m)) {
            $arr = preg_split('/\s+/', trim($m[1]));
            if (isset($arr[0])) {
                $keyups[$arr[0]] = [
                    'is_keyed' => keyed_bool($arr[1] ?? '0'),
                    'last_keyed_seconds' => $arr[2] ?? '',
                    'last_unkeyed_seconds' => $arr[3] ?? '',
                ];
            }
        }
    }

    foreach ($connections as &$conn) {
        $n = $conn['node'];
        if (isset($keyups[$n])) {
            $conn['state'] = $keyups[$n]['is_keyed'] ? 'keyed' : 'connected';
            $conn['last_keyed'] = duration_label((string)$keyups[$n]['last_keyed_seconds']);
            $conn['last_unkeyed'] = duration_label((string)$keyups[$n]['last_unkeyed_seconds']);
        }
    }
    unset($conn);

    return [
        'connections' => array_values($connections),
        'downstream_links' => $downstream,
        'rx_keyed' => $rxKeyed,
        'tx_keyed' => $txKeyed,
    ];
}

try {
    [$host, $port, $user, $secret] = find_ami_config();
    $fp = @fsockopen($host, $port, $errno, $errstr, 5);
    if (!$fp) {
        throw new RuntimeException("AMI connect failed: $errstr ($errno)");
    }
    stream_set_timeout($fp, 8);

    $loginId = 'ascLogin' . mt_rand();
    $login = ami_action($fp, [
        'Action' => 'Login',
        'Username' => $user,
        'Secret' => $secret,
        'Events' => '0',
    ], $loginId);

    $loginText = implode("\n", $login);
    if (!str_contains($loginText, 'Authentication accepted')) {
        throw new RuntimeException('AMI authentication failed');
    }

    $xstat = ami_action($fp, [
        'Action' => 'RptStatus',
        'Command' => 'XStat',
        'Node' => $node,
    ], 'ascXStat' . mt_rand());

    $sawstat = ami_action($fp, [
        'Action' => 'RptStatus',
        'Command' => 'SawStat',
        'Node' => $node,
    ], 'ascSawStat' . mt_rand());

    ami_action($fp, ['Action' => 'Logoff'], 'ascLogoff' . mt_rand());
    fclose($fp);

    $parsed = parse_status($xstat, $sawstat, $node);
    echo json_encode([
        'ok' => true,
        'node' => $node,
        'connections' => $parsed['connections'],
        'downstream_links' => $parsed['downstream_links'],
        'rx_keyed' => $parsed['rx_keyed'],
        'tx_keyed' => $parsed['tx_keyed'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
