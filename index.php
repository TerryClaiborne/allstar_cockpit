<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Bootstrap.php';

use AllStarCockpit\Support\Auth;
use AllStarCockpit\Support\Config;

$config = new Config(dirname(__DIR__));
$auth = new Auth($config);
$auth->allowReadOnlyPage();
$authStatus = $auth->status();
$versionFile = dirname(__DIR__) . '/VERSION';
$version = is_file($versionFile) ? trim((string)file_get_contents($versionFile)) : 'dev';

function asc_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>AllStar Cockpit</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= asc_e($version) ?>">
</head>
<body>
<div class="app-shell">
    <header class="topbar">
        <div class="topbar-left" aria-label="Configured node">
            <a class="node-pill" id="nodePill" href="#" target="_blank" rel="noopener noreferrer">Node: not configured</a>
        </div>

        <div class="title-capsule" aria-label="Application title">
            <span class="title-main">AllStar Cockpit</span>
            <span class="update-bolt" title="Live dashboard">⚡</span>
        </div>

        <div class="auth-controls auth-mode-<?= asc_e((string)$authStatus['mode']) ?>" aria-label="Login status">
            <span class="auth-state"><?= asc_e((string)$authStatus['label']) ?></span>
            <?php if ($authStatus['enabled'] && $authStatus['authenticated']): ?>
                <a class="auth-action" href="../logout.php">Logout</a>
            <?php elseif ($authStatus['enabled']): ?>
                <a class="auth-action" href="../login.php?next=<?= rawurlencode('public/') ?>">Login</a>
            <?php else: ?>
                <span class="auth-action auth-action-static">Normal</span>
            <?php endif; ?>
        </div>
    </header>

    <main class="dashboard">
        <section class="panel live-panel">
            <div class="panel-title-row">
                <div>
                    <h2>Live Activity</h2>
                    <p>Current key-up activity.</p>
                </div>
            </div>
            <div class="warning-list" id="warnings"></div>
            <div class="activity-list" id="liveActivity">
                <div class="empty-state">No current key-up activity.</div>
            </div>
        </section>

        <aside class="panel connections-panel">
            <div class="panel-title-row">
                <div>
                    <h2>Current Connections</h2>
                    <p>Linked nodes seen now.</p>
                </div>
                <span class="count-badge" id="connectionCount">0</span>
            </div>
            <div class="connection-list" id="connections">
                <div class="empty-state">No current connections found.</div>
            </div>
        </aside>
    </main>

    <section class="panel history-panel">
        <div class="panel-title-row">
            <div>
                <h2>History</h2>
                <p>Click a row for details. Project-local log from <code>logs/activity.jsonl</code>.</p>
            </div>
            <span class="subtle" id="lastUpdated">--:--:--</span>
        </div>
        <div class="table-wrap history-table-wrap">
            <table class="history-table history-table-head" aria-hidden="true">
                <colgroup>
                    <col class="col-time">
                    <col class="col-event">
                    <col class="col-source">
                    <col class="col-node">
                    <col class="col-direction">
                    <col class="col-ip">
                    <col class="col-duration">
                </colgroup>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Event</th>
                        <th>Source</th>
                        <th>Node / Label</th>
                        <th>Direction</th>
                        <th>IP</th>
                        <th>Duration</th>
                    </tr>
                </thead>
            </table>
            <div class="history-body-scroll">
                <table class="history-table history-table-body">
                    <colgroup>
                        <col class="col-time">
                        <col class="col-event">
                        <col class="col-source">
                        <col class="col-node">
                        <col class="col-direction">
                        <col class="col-ip">
                        <col class="col-duration">
                    </colgroup>
                    <tbody id="historyBody">
                        <tr><td colspan="7" class="empty-cell">No history yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="panel downstream-panel">
        <div class="panel-title-row">
            <div>
                <h2>Downstream Nodes</h2>
                <p>Linked nodes reported by local status. Click a row for details; Node opens AllStarLink and Callsign opens QRZ when available.</p>
            </div>
            <span class="count-badge" id="downstreamCount">0</span>
        </div>
        <div class="downstream-list" id="downstreamNodes">
            <div class="empty-state">No downstream nodes reported.</div>
        </div>
    </section>
</div>

<div class="modal-backdrop" id="modalBackdrop" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <button class="modal-close" id="modalClose" type="button" aria-label="Close">×</button>
        <h2 id="modalTitle">Node Detail</h2>
        <div id="modalBody" class="modal-body"></div>
    </div>
</div>

<script src="assets/js/app.js?v=<?= asc_e($version) ?>"></script>
</body>
</html>
