<?php
declare(strict_types=1);

namespace AllStarCockpit\Support;

final class Config
{
    private array $values = [];
    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? dirname(__DIR__, 2);
        $example = $this->root . '/config.ini.example';
        $config = $this->root . '/config.ini';

        if (is_file($example)) {
            $this->values = array_merge($this->values, parse_ini_file($example, false, INI_SCANNER_TYPED) ?: []);
        }

        if (is_file($config)) {
            $this->values = array_merge($this->values, parse_ini_file($config, false, INI_SCANNER_TYPED) ?: []);
        }
    }

    public function root(): string
    {
        return $this->root;
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? $default;
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return trim((string)$value);
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->values[$key] ?? $default;
        return is_numeric($value) ? (int)$value : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? $default;
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }

    public function path(string $key, string $defaultRelative): string
    {
        $value = $this->getString($key, '');
        if ($value !== '') {
            return $value;
        }
        return $this->root . '/' . ltrim($defaultRelative, '/');
    }

    public function hiddenNodes(): array
    {
        $raw = $this->getString('HIDE_NODES', $this->getString('PRIVATE_NODES', ''));
        $nodes = [];

        foreach (preg_split('/[,\s]+/', $raw) ?: [] as $item) {
            $item = preg_replace('/[^0-9]/', '', $item) ?? '';
            if ($item !== '') {
                $nodes[$item] = true;
            }
        }

        if ($this->getBool('HIDE_PRIVATE_NODE_RANGE', true)) {
            for ($n = 1000; $n <= 1999; $n++) {
                $nodes[(string)$n] = true;
            }
        }

        return $nodes;
    }

    public function shouldHideNode(string $node): bool
    {
        $node = preg_replace('/[^0-9]/', '', (string)$node) ?? '';
        if ($node === '') {
            return false;
        }

        $hidden = $this->hiddenNodes();
        return isset($hidden[$node]);
    }

    public function shouldShowDownstreamNode($node): bool
    {
        $node = preg_replace('/[^0-9]/', '', (string)$node) ?? '';
        if ($node === '' || $this->shouldHideNode($node)) {
            return false;
        }

        if (!$this->getBool('VALID_DOWNSTREAM_ONLY', true)) {
            return true;
        }

        return $this->isValidPublicStyleNode($node);
    }

    private function isValidPublicStyleNode(string $node): bool
    {
        $len = strlen($node);
        if ($len < 4) {
            return false;
        }

        // EchoLink-style numbers beginning with 3 are useful as direct EchoLink
        // connections, but they are usually noise in downstream lists.
        if ($node[0] === '3') {
            return $this->getBool('SHOW_ECHOLINK_DOWNSTREAM', false);
        }

        $n = (int)$node;

        // Current AllStarLink system-assigned primary ranges.
        if (($n >= 20000 && $n <= 29999) || ($n >= 40000 && $n <= 69999)) {
            return true;
        }

        // Allow older/legacy four-digit public nodes, but not the private 1000-1999 range.
        if ($len === 4 && $n >= 2000) {
            return true;
        }

        // Extended/NNX-style nodes: primary node plus one sub-address digit.
        // Example: 63001 -> 630010 through 630019.
        if ($len >= 5) {
            $parent = substr($node, 0, -1);
            $p = (int)$parent;
            $parentLen = strlen($parent);

            if (($p >= 20000 && $p <= 29999) || ($p >= 40000 && $p <= 69999)) {
                return true;
            }

            if ($parentLen === 4 && $p >= 2000) {
                return true;
            }
        }

        return false;
    }

    public function publicConfig(): array
    {
        $node = $this->getString('MYNODE', '');
        $isConfigured = $node !== '' && !preg_match('/YOUR|CHANGE_ME|PLACEHOLDER/i', $node);

        return [
            'app_name' => $this->getString('APP_NAME', 'AllStar Cockpit'),
            'node' => $isConfigured ? $node : '',
            'configured' => $isConfigured,
            'poll_interval_seconds' => max(1, $this->getInt('POLL_INTERVAL_SECONDS', 1)),
            'history_limit' => max(25, $this->getInt('HISTORY_LIMIT', 250)),
            'show_observed_ips' => $this->getBool('SHOW_OBSERVED_IPS', false),
            'enable_external_lookups' => $this->getBool('ENABLE_EXTERNAL_LOOKUPS', false),
            'hide_private_node_range' => $this->getBool('HIDE_PRIVATE_NODE_RANGE', true),
        ];
    }
}
