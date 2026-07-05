(() => {
    document.body.dataset.theme = 'dark';

    const state = {
        pollMs: 1000,
        timer: null,
        lastData: {},
        lastConnections: [],
        downstreamLinks: [],
        downstreamHistory: [],
    };

    const el = (id) => document.getElementById(id);

    function setText(id, value) {
        const node = el(id);
        if (node) node.textContent = value;
    }

    function safe(value) {
        return value === undefined || value === null || value === '' ? '—' : String(value);
    }

    function safeBlank(value) {
        return value === undefined || value === null || value === '' || value === '—' ? '' : String(value);
    }

    function norm(value) {
        return safe(value).toLowerCase().replace(/[^a-z0-9_-]+/g, '_');
    }

    function fmtTime(ts) {
        if (!ts) return '';
        const date = new Date(ts);
        if (Number.isNaN(date.getTime())) return ts;
        return date.toLocaleTimeString();
    }

    function fmtDateTime(ts) {
        if (!ts) return '';
        const date = new Date(ts);
        if (Number.isNaN(date.getTime())) return ts;
        return date.toLocaleString();
    }

    function eventLabel(value) {
        const raw = safe(value);
        if (raw === '—') return raw;

        const key = raw.toLowerCase().replace(/[\s-]+/g, '_');
        if (key === 'tx_end' || key === 'txend' || key === 'unkey') return 'End Transmit';
        if (key === 'connect') return 'Connect';
        if (key === 'disconnect') return 'Disconnect';
        if (key === 'downstream_seen') return 'Downstream Seen';
        if (key === 'downstream_gone') return 'Downstream Gone';
        if (key === 'downstream_mode') return 'Mode Changed';

        return raw
            .replace(/_/g, ' ')
            .replace(/\btx\b/i, 'TX')
            .replace(/\brx\b/i, 'RX')
            .replace(/\b\w/g, (m) => m.toUpperCase());
    }

    function isEchoLinkConnection(conn = {}) {
        const source = safe(conn.source).toLowerCase();
        const node = String(conn.node || '');
        return source.includes('echolink') || /^3\d{6,}$/.test(node);
    }

    function isIaxConnection(conn = {}) {
        const source = safe(conn.source).toLowerCase();
        const type = safe(conn.connection_type).toLowerCase();
        const node = String(conn.node || '');
        return source === 'iax' || type === 'iax_channel' || node.startsWith('iax-channel:');
    }

    function iaxLabel(conn = {}) {
        const user = safeBlank(conn.iax_username);
        const channel = safeBlank(conn.iax_channel || conn.asterisk_channel || conn.node);
        if (user && channel) return `${user} · ${channel}`;
        return channel || safeBlank(conn.label) || 'IAX Client';
    }

    function echoLinkDisplayId(value) {
        const raw = String(value || '').replace(/\D/g, '');
        if (!raw) return '';
        const n = Number(raw);
        if (n >= 3000000 && n < 4000000) return String(n - 3000000);
        return raw;
    }

    function echoLinkLabel(item = {}) {
        const id = echoLinkDisplayId(item.node) || safeBlank(item.label) || safeBlank(item.callsign) || safeBlank(item.node);
        return id ? `EchoLink ${id}` : 'EchoLink';
    }

    function displayIp(item = {}) {
        return isEchoLinkConnection(item) ? 'N/A' : safe(item.observed_ip);
    }

    function allStarNodeUrl(node) {
        const clean = String(node || '').replace(/\D/g, '');
        return clean ? `https://stats.allstarlink.org/stats/${encodeURIComponent(clean)}` : '';
    }

    function qrzUrl(callsign) {
        const raw = String(callsign || '').trim().toUpperCase();
        if (!raw) return '';
        let clean = raw.replace(/\/[A-Z0-9]+$/, '');
        clean = clean.replace(/-(?:R|L|M|P|PORTABLE|MOBILE)$/, '');
        const match = clean.match(/\b([A-Z]{1,3}[0-9][A-Z0-9]{1,4})\b/);
        clean = match ? match[1] : clean.replace(/[^A-Z0-9]/g, '');
        return clean ? `https://www.qrz.com/db/${encodeURIComponent(clean)}` : '';
    }



    function makeExternalLink(text, href, className = '') {
        const a = document.createElement('a');
        a.href = href;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.className = className;
        a.textContent = text;
        a.title = text;
        a.addEventListener('click', (evt) => evt.stopPropagation());
        return a;
    }

    function appendNodeCallLinks(parent, node, callsign, options = {}) {
        const wrap = document.createElement('span');
        wrap.className = options.className || 'node-call-links';

        const nodeText = node ? `Node ${node}` : '';
        const nodeHref = options.nodeHref || allStarNodeUrl(node);
        if (nodeText && nodeHref) {
            wrap.appendChild(makeExternalLink(nodeText, nodeHref, 'node-anchor'));
        } else if (nodeText) {
            const span = document.createElement('span');
            span.className = 'node-anchor node-anchor-static';
            span.textContent = nodeText;
            wrap.appendChild(span);
        }

        const call = safeBlank(callsign);
        const callHref = options.callsignHref || qrzUrl(call);
        if (call) {
            if (wrap.childNodes.length) {
                const sep = document.createElement('span');
                sep.className = 'node-call-separator';
                sep.textContent = ' \u00a0.\u00a0 ';
                wrap.appendChild(sep);
            }
            if (callHref) {
                wrap.appendChild(makeExternalLink(call, callHref, 'callsign-anchor'));
            } else {
                const span = document.createElement('span');
                span.className = 'callsign-anchor callsign-anchor-static';
                span.textContent = call;
                wrap.appendChild(span);
            }
        }

        if (!wrap.childNodes.length) {
            wrap.textContent = '—';
        }

        parent.appendChild(wrap);
    }

    function modeFromRaw(raw) {
        const value = String(raw || '').trim().toUpperCase();
        if (value === 'R') return 'Local Monitor';
        if (value === 'T') return 'Transceive';
        if (value === 'C') return 'Connecting';
        return '';
    }

    function isUsableMode(value) {
        const mode = String(value || '').trim().toLowerCase();
        return mode !== '' && mode !== '—' && mode !== 'unknown';
    }

    function connectionModeLabel(conn = {}) {
        const rawMode = modeFromRaw(conn.mode_raw);
        if (rawMode) return rawMode;

        const node = String(conn.node || '');
        if (node) {
            const down = (state.downstreamLinks || []).find((item) => String(item.node || '') === node);
            if (down) {
                const downRaw = modeFromRaw(down.mode_raw);
                if (downRaw) return downRaw;
                if (isUsableMode(down.mode)) return String(down.mode);
            }
        }

        if (isUsableMode(conn.mode)) return String(conn.mode);
        if (isEchoLinkConnection(conn)) return 'EchoLink';
        if (String(conn.source || '').toLowerCase() === 'allstar' && (conn.link || conn.state || conn.node)) return 'Local Monitor';
        return 'Unknown';
    }

    function connectionTitle(conn = {}) {
        if (isIaxConnection(conn)) {
            return iaxLabel(conn);
        }
        if (isEchoLinkConnection(conn)) {
            return echoLinkLabel(conn);
        }
        if (conn.node) return conn.callsign ? `Node ${conn.node} . ${conn.callsign}` : `Node ${conn.node}`;
        return safe(conn.label || conn.link || conn.source);
    }

    function downstreamTitle(item = {}) {
        if (item.label) return String(item.label);
        if (item.node) return item.callsign ? `Node ${item.node} . ${item.callsign}` : `Node ${item.node}`;
        return safe(item.source || item.mode || 'Downstream node');
    }

    function isKeyed(value) {
        return String(value || '').toLowerCase().includes('key');
    }

    function connectionPriority(conn = {}) {
        const stateText = String(conn.state || '').toLowerCase();
        const modeText = connectionModeLabel(conn).toLowerCase();
        if (stateText.includes('key')) return 0;
        if (modeText.includes('transceive')) return 1;
        return 2;
    }

    function sortedConnections(connections) {
        return (Array.isArray(connections) ? connections : [])
            .map((conn, index) => ({ conn, index }))
            .sort((a, b) => {
                const pa = connectionPriority(a.conn);
                const pb = connectionPriority(b.conn);
                if (pa !== pb) return pa - pb;
                return a.index - b.index;
            })
            .map((item) => item.conn);
    }

    async function fetchJson(url) {
        const res = await fetch(url, { cache: 'no-store' });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return await res.json();
    }

    function renderWarnings(warnings) {
        const wrap = el('warnings');
        if (!wrap) return;
        wrap.innerHTML = '';
        (warnings || []).forEach((warning) => {
            const div = document.createElement('div');
            div.className = 'warning';
            div.textContent = warning;
            wrap.appendChild(div);
        });
    }

    function addMiniField(parent, label, value, className = '') {
        const field = document.createElement('div');
        field.className = `mini-field ${className}`.trim();

        const lab = document.createElement('span');
        lab.className = 'mini-label';
        lab.textContent = label;

        const val = document.createElement('strong');
        val.className = 'mini-value';
        val.textContent = safe(value);

        field.append(lab, val);
        parent.appendChild(field);
    }

    function updateConfiguredNodeLinks(config = {}) {
        const configured = !!config.configured;
        const node = config.node || '';
        const href = configured ? allStarNodeUrl(node) : '';

        const nodePill = el('nodePill');
        if (!nodePill) return;

        nodePill.textContent = configured ? `Node: ${node}` : 'Node: not configured';
        if (href) {
            nodePill.href = href;
            nodePill.classList.remove('disabled-link');
            nodePill.title = `Open AllStar node status page for ${node}`;
        } else {
            nodePill.removeAttribute('href');
            nodePill.classList.add('disabled-link');
            nodePill.title = 'MYNODE is not configured yet.';
        }
    }

    function addConnectionCell(parent, label, value, className = '', options = {}) {
        const cell = document.createElement('div');
        cell.className = `connection-cell ${className}`.trim();

        const lab = document.createElement('span');
        lab.className = 'connection-label';
        lab.textContent = label;

        const val = document.createElement('strong');
        val.className = 'connection-value';

        if (options.node) {
            const btn = document.createElement('button');
            btn.className = 'node-link';
            btn.type = 'button';
            btn.textContent = safe(value);
            btn.addEventListener('click', () => openNodeDetail(options.node, options.connection || {}));
            val.appendChild(btn);
        } else {
            val.textContent = safe(value);
        }

        cell.append(lab, val);
        parent.appendChild(cell);
    }

    function renderConnections(connections) {
        const wrap = el('connections');
        if (!wrap) return;
        wrap.innerHTML = '';
        const list = sortedConnections(connections);
        setText('connectionCount', String(list.length));

        if (!list.length) {
            const div = document.createElement('div');
            div.className = 'empty-state idle-state';
            div.textContent = 'No linked nodes seen right now.';
            wrap.appendChild(div);
            return;
        }

        list.forEach((conn) => {
            const item = document.createElement('div');
            item.className = `connection-item connection-row state-${norm(conn.state)} direction-${norm(conn.direction)}`;

            addConnectionCell(item, 'Source', conn.source, 'connection-source-cell');
            const nodeOptions = (isEchoLinkConnection(conn) || isIaxConnection(conn)) ? {} : {
                node: conn.node,
                connection: conn,
            };
            addConnectionCell(item, isIaxConnection(conn) ? 'IAX Channel' : 'Node / Label', connectionTitle(conn), 'connection-node-cell', nodeOptions);
            addConnectionCell(item, 'Dir', conn.direction, 'connection-dir-cell');
            addConnectionCell(item, 'State', conn.state, 'connection-state-cell');
            addConnectionCell(item, 'Mode', connectionModeLabel(conn), 'connection-mode-cell');
            addConnectionCell(item, 'IP', displayIp(conn), 'connection-ip-cell');

            if (conn.node) {
                item.classList.add('connection-clickable');
                item.tabIndex = 0;
                item.title = 'Click for details';
                const openDetail = () => {
                    if (isIaxConnection(conn)) {
                        renderIaxDetail(conn);
                    } else {
                        openNodeDetail(conn.node, conn);
                    }
                };
                item.addEventListener('click', openDetail);
                item.addEventListener('keydown', (evt) => {
                    if (evt.key === 'Enter' || evt.key === ' ') {
                        evt.preventDefault();
                        openDetail();
                    }
                });
            }

            wrap.appendChild(item);
        });
    }

    function addDownstreamCell(parent, label, value, className = '', options = {}) {
        const cell = document.createElement('div');
        cell.className = `downstream-cell ${className}`.trim();

        const lab = document.createElement('span');
        lab.className = 'downstream-label';
        lab.textContent = label;

        const val = document.createElement('strong');
        val.className = 'downstream-value';

        const text = safe(value);
        const href = options.href || '';

        if (href && value) {
            const a = document.createElement('a');
            a.href = href;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.className = options.linkClass || '';
            a.textContent = text;
            a.addEventListener('click', (evt) => evt.stopPropagation());
            val.appendChild(a);
        } else {
            val.textContent = text;
        }

        cell.append(lab, val);
        parent.appendChild(cell);
    }

    function isTalkingConnection(conn = {}) {
        const stateText = String(conn.state || '').toLowerCase();
        return stateText.includes('key');
    }

    function downstreamPriority(item = {}, index = 0, talkingNodes = new Set()) {
        const node = String(item.node || '');
        if (node && talkingNodes.has(node)) return 0;

        const mode = connectionModeLabel(item).toLowerCase();
        if (mode.includes('transceive')) return 1;
        if (mode.includes('local monitor')) return 2;
        return 3 + (index / 1000000);
    }

    function renderDownstreamNodes(nodes) {
        const wrap = el('downstreamNodes');
        if (!wrap) return;
        wrap.innerHTML = '';

        const talkingNodes = new Set((state.lastConnections || [])
            .filter(isTalkingConnection)
            .map((conn) => String(conn.node || ''))
            .filter(Boolean));

        const list = (Array.isArray(nodes) ? nodes : [])
            .filter((item) => item && item.node)
            .map((item, index) => ({ item, index }))
            .sort((a, b) => {
                const pa = downstreamPriority(a.item, a.index, talkingNodes);
                const pb = downstreamPriority(b.item, b.index, talkingNodes);
                if (pa !== pb) return pa - pb;
                return Number(a.item.node || 0) - Number(b.item.node || 0);
            })
            .map((entry) => entry.item);

        setText('downstreamCount', String(list.length));

        if (!list.length) {
            const div = document.createElement('div');
            div.className = 'empty-state idle-state';
            div.textContent = 'No downstream nodes reported by local status.';
            wrap.appendChild(div);
            return;
        }

        list.forEach((item) => {
            const node = String(item.node || '');
            const talking = node && talkingNodes.has(node);
            const row = document.createElement('div');
            row.className = `downstream-item downstream-grid mode-${norm(item.mode)} mode-raw-${norm(item.mode_raw)}${talking ? ' downstream-audio-active' : ''}`;
            row.tabIndex = 0;
            row.title = talking ? 'Talking now — click for details' : 'Click for details';
            row.addEventListener('click', () => openNodeDetail(item.node, item));
            row.addEventListener('keydown', (evt) => {
                if (evt.key === 'Enter' || evt.key === ' ') {
                    evt.preventDefault();
                    openNodeDetail(item.node, item);
                }
            });

            addDownstreamCell(row, 'Node', item.node ? `Node ${item.node}` : '', 'downstream-node-cell', {
                href: item.allstar_node_url || allStarNodeUrl(item.node),
                linkClass: 'node-anchor',
            });
            addDownstreamCell(row, 'Callsign', item.callsign || '', 'downstream-call-cell', {
                href: item.callsign_url || qrzUrl(item.callsign),
                linkClass: 'callsign-anchor',
            });
            addDownstreamCell(row, 'Mode', connectionModeLabel(item), 'downstream-mode-cell');
            addDownstreamCell(row, 'Location', item.location || '', 'downstream-location-cell');

            wrap.appendChild(row);
        });
    }

    function isPrivateActivity(event = {}) {
        return String(event.source || '').toLowerCase().includes('private');
    }

    function isLocalOnlyActivity(event = {}) {
        const label = safe(event.label || event.node || '').toLowerCase();
        const source = safe(event.source).toLowerCase();
        return label.includes('local transmit keyed') || source.includes('local node');
    }

    function isKeyedActivity(event = {}) {
        const stateText = String(event.state || '').toLowerCase();
        const typeText = String(event.type || '').toLowerCase();
        return stateText.includes('key') || typeText === 'tx' || typeText === 'keyed' || typeText === 'channel';
    }

    function renderLiveActivity(items) {
        const wrap = el('liveActivity');
        if (!wrap) return;
        wrap.innerHTML = '';

        const keyedItems = (Array.isArray(items) ? items : []).filter(isKeyedActivity);
        const visibleRemote = keyedItems.filter((event) => !isLocalOnlyActivity(event) && !isPrivateActivity(event));
        const visibleItems = visibleRemote.length
            ? visibleRemote
            : keyedItems.filter((event) => !isLocalOnlyActivity(event) || keyedItems.length === 1);

        if (!visibleItems.length) {
            const div = document.createElement('div');
            div.className = 'empty-state idle-state';
            div.textContent = 'No current key-up activity.';
            wrap.appendChild(div);
            return;
        }

        visibleItems.forEach((event) => {
            const item = document.createElement('div');
            item.className = `activity-item activity-grid event-${norm(event.type)} state-${norm(event.state)} direction-${norm(event.direction)} audio-active`;

            const badge = document.createElement('div');
            badge.className = `event-type ${norm(event.type || 'tx')}`;
            badge.textContent = eventLabel(event.type || 'TX');

            const main = document.createElement('div');
            main.className = 'activity-main';

            const title = document.createElement('div');
            title.className = 'item-title';
            title.textContent = safe(event.label || event.node || event.source);

            const fields = document.createElement('div');
            fields.className = 'activity-fields';
            addMiniField(fields, 'Source', event.source, 'field-source');
            addMiniField(fields, 'State', event.state || event.direction, 'field-state');
            if (event.direction) addMiniField(fields, 'Dir', event.direction, 'field-direction');
            if (event.mode) addMiniField(fields, 'Mode', event.mode, 'field-mode');
            if (event.observed_ip || isEchoLinkConnection(event)) addMiniField(fields, 'IP', displayIp(event), 'field-ip');
            if (event.elapsed || event.duration) addMiniField(fields, 'Time', event.elapsed || event.duration, 'field-time');

            main.append(title, fields);
            item.append(badge, main);
            wrap.appendChild(item);
        });
    }

    function historyDuration(event = {}) {
        const type = String(event.type || '').toLowerCase();
        if (type === 'tx_end') return safeBlank(event.duration || event.tx_time || event.tx_duration);
        if (type === 'connect') return '00:00:00';
        return safeBlank(event.connection_time || event.elapsed || event.connection_elapsed);
    }

    function renderHistory(history) {
        const body = el('historyBody');
        if (!body) return;
        body.innerHTML = '';

        if (!history.length) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 7;
            td.className = 'empty-cell';
            td.textContent = 'No history yet.';
            tr.appendChild(td);
            body.appendChild(tr);
            return;
        }

        history.forEach((event) => {
            const tr = document.createElement('tr');
            tr.className = `history-${norm(event.type)}`;

            [
                fmtDateTime(event.ts),
                eventLabel(event.type),
                safe(event.source),
            ].forEach((value) => {
                const td = document.createElement('td');
                td.textContent = value;
                tr.appendChild(td);
            });

            const nodeTd = document.createElement('td');
            nodeTd.className = 'history-node-call';
            if (isEchoLinkConnection(event)) {
                nodeTd.textContent = echoLinkLabel(event);
                nodeTd.classList.add('history-echolink-label');
            } else if (event.node) {
                appendNodeCallLinks(nodeTd, event.node, event.callsign || '', {
                    nodeHref: event.allstar_node_url || allStarNodeUrl(event.node),
                    callsignHref: event.callsign_url || qrzUrl(event.callsign || ''),
                });
            } else {
                nodeTd.textContent = safe(event.label);
            }
            tr.appendChild(nodeTd);

            [
                safe(event.direction),
                displayIp(event),
                historyDuration(event),
            ].forEach((value) => {
                const td = document.createElement('td');
                td.textContent = value;
                tr.appendChild(td);
            });

            if (isIaxConnection(event)) {
                tr.classList.add('history-clickable');
                tr.tabIndex = 0;
                tr.title = 'Click for IAX details';
                tr.addEventListener('click', () => renderIaxDetail(event));
                tr.addEventListener('keydown', (evt) => {
                    if (evt.key === 'Enter' || evt.key === ' ') {
                        evt.preventDefault();
                        renderIaxDetail(event);
                    }
                });
            } else if (event.node) {
                tr.classList.add('history-clickable');
                tr.tabIndex = 0;
                tr.title = 'Click for details';
                tr.addEventListener('click', () => openNodeDetail(event.node, event));
                tr.addEventListener('keydown', (evt) => {
                    if (evt.key === 'Enter' || evt.key === ' ') {
                        evt.preventDefault();
                        openNodeDetail(event.node, event);
                    }
                });
            }

            body.appendChild(tr);
        });
    }

    function appendDetailRow(body, label, value, options = {}) {
        const row = document.createElement('div');
        row.className = 'detail-row';

        const left = document.createElement('span');
        left.className = 'detail-label';
        left.textContent = label;

        const right = document.createElement('span');
        right.className = 'detail-value';

        if (options.href && value) {
            const a = document.createElement('a');
            a.href = options.href;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.textContent = value;
            right.appendChild(a);
        } else {
            right.textContent = safe(value);
        }

        row.append(left, right);
        body.appendChild(row);
    }



    function appendNodeCallDetailRow(body, node, callsign, options = {}) {
        const row = document.createElement('div');
        row.className = 'detail-row';

        const left = document.createElement('span');
        left.className = 'detail-label';
        left.textContent = options.label || 'Node / Callsign';

        const right = document.createElement('span');
        right.className = 'detail-value detail-node-call';
        appendNodeCallLinks(right, node, callsign, {
            nodeHref: options.nodeHref || allStarNodeUrl(node),
            callsignHref: options.callsignHref || qrzUrl(callsign || ''),
        });

        row.append(left, right);
        body.appendChild(row);
    }

    function appendLinkedNodeGridCell(row, className, label, value, href = '', linkClass = '') {
        const cell = document.createElement('div');
        cell.className = `linked-node-grid-cell ${className}`.trim();
        cell.dataset.label = label;

        const text = safeBlank(value);
        if (href && text) {
            cell.appendChild(makeExternalLink(text, href, linkClass));
        } else {
            cell.textContent = text || '—';
        }

        row.appendChild(cell);
    }

    function appendLinkedNodes(body, title, nodes) {
        const filtered = (nodes || []).filter((item) => item && item.node);
        const row = document.createElement('div');
        row.className = 'detail-row detail-row-wide downstream-row';

        const left = document.createElement('span');
        left.className = 'detail-label';
        left.textContent = title;

        const right = document.createElement('div');
        right.className = 'linked-node-grid-wrap detail-value';

        if (!filtered.length) {
            const empty = document.createElement('span');
            empty.className = 'downstream-empty';
            empty.textContent = 'No downstream/linked nodes reported by local status yet.';
            right.appendChild(empty);
        } else {
            const grid = document.createElement('div');
            grid.className = 'linked-node-grid';

            ['Node', 'Callsign', 'Mode'].forEach((heading) => {
                const head = document.createElement('div');
                head.className = `linked-node-grid-head linked-node-grid-head-${norm(heading)}`;
                head.textContent = heading;
                grid.appendChild(head);
            });

            filtered.forEach((item) => {
                const nodeText = item.node ? `Node ${item.node}` : '';
                appendLinkedNodeGridCell(grid, 'linked-node-grid-node', 'Node', nodeText, item.allstar_node_url || allStarNodeUrl(item.node), 'node-anchor');
                appendLinkedNodeGridCell(grid, 'linked-node-grid-call', 'Callsign', item.callsign || '', item.callsign_url || qrzUrl(item.callsign || ''), 'callsign-anchor');
                appendLinkedNodeGridCell(grid, 'linked-node-grid-mode', 'Mode', connectionModeLabel(item));
            });

            right.appendChild(grid);
        }

        row.append(left, right);
        body.appendChild(row);
    }



    function downstreamOriginLabel(event = {}) {
        const origin = String(event.origin || '').toLowerCase();
        if (origin === 'remote-stats') return 'Remote Stats';
        if (origin === 'local-asterisk') return 'Local Asterisk';
        if (origin === 'local-status') return 'Local Status';
        return safe(event.lookup_note || event.source || 'Downstream');
    }
    function addDownstreamHistoryControls(body, count) {
        const controls = document.createElement('div');
        controls.className = 'detail-row detail-row-wide downstream-history-controls';

        const left = document.createElement('span');
        left.className = 'detail-label';
        left.textContent = 'Saved Rows';

        const right = document.createElement('div');
        right.className = 'detail-value';

        const meta = document.createElement('span');
        meta.className = 'item-meta';
        meta.textContent = `${count} saved history ${count === 1 ? 'entry' : 'entries'}`;

        const clear = document.createElement('button');
        clear.className = 'count-badge';
        clear.id = 'clearDownstreamHistoryButton';
        clear.type = 'button';
        clear.textContent = 'Clear History';
        clear.style.marginLeft = '10px';
        clear.addEventListener('click', clearDownstreamHistory);

        right.append(meta, clear);
        controls.append(left, right);
        body.appendChild(controls);
    }

    async function clearDownstreamHistory() {
        if (!window.confirm('Clear saved downstream history? This will not disconnect anything.')) {
            return;
        }

        const body = el('modalBody');
        if (body) {
            body.innerHTML = '';
            appendDetailRow(body, 'Clearing', 'Clearing logs/downstream-history.jsonl...');
        }

        try {
            const res = await fetch('../api/clear_downstream_history.php', {
                method: 'POST',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'AllStar-Cockpit' },
            });
            const json = await res.json();
            if (!res.ok || json?.ok === false) {
                throw new Error(json?.error || 'Clear failed');
            }
            state.downstreamHistory = [];
            renderDownstreamHistoryRows([]);
            const fresh = el('modalBody');
            if (fresh) appendDetailRow(fresh, 'Cleared', 'Downstream history was cleared. Live downstream nodes will be captured again as they appear.');
        } catch (err) {
            const fresh = el('modalBody');
            if (fresh) {
                fresh.innerHTML = '';
                appendDetailRow(fresh, 'Error', err?.message || 'Could not clear downstream history.');
            }
        }
    }

    function renderDownstreamHistoryRows(history) {
        const body = el('modalBody');
        if (!body) return;

        body.innerHTML = '';
        addDownstreamHistoryControls(body, history.length);

        if (!history.length) {
            appendDetailRow(body, 'History', 'No downstream history has been captured yet. Leave the dashboard open while nodes appear/disappear.');
            appendDetailRow(body, 'Log', 'logs/downstream-history.jsonl');
            return;
        }

        history.slice(0, 1000).forEach((event) => {
            const row = document.createElement('div');
            row.className = 'detail-row detail-row-wide downstream-history-row';

            const left = document.createElement('span');
            left.className = 'detail-label';
            left.textContent = `${fmtDateTime(event.ts)} · ${eventLabel(event.type)}`;

            const right = document.createElement('div');
            right.className = 'detail-value linked-node-grid-wrap';

            const line1 = document.createElement('div');
            line1.className = 'detail-node-call';
            appendNodeCallLinks(line1, event.node || '', event.callsign || '', {
                nodeHref: event.allstar_node_url || allStarNodeUrl(event.node),
                callsignHref: event.callsign_url || qrzUrl(event.callsign || ''),
            });
            right.appendChild(line1);

            const bits = [];
            const mode = connectionModeLabel(event);
            if (mode && mode !== 'Unknown') bits.push(`Mode: ${mode}`);
            bits.push(`Source: ${downstreamOriginLabel(event)}`);
            if (event.remote_source_node || event.direct_node) bits.push(`Via: ${event.remote_source_node || event.direct_node}`);
            if (event.location) bits.push(`Location: ${event.location}`);
            if (event.duration) bits.push(`Duration: ${event.duration}`);

            const line2 = document.createElement('div');
            line2.className = 'item-meta';
            line2.textContent = bits.join(' · ');
            right.appendChild(line2);

            row.append(left, right);
            body.appendChild(row);
        });
    }

    async function openDownstreamHistory() {
        const modal = el('modalBackdrop');
        const title = el('modalTitle');
        const body = el('modalBody');
        if (!modal || !title || !body) return;

        title.textContent = 'Downstream History';
        body.innerHTML = '';
        appendDetailRow(body, 'Loading', 'Reading logs/downstream-history.jsonl...');
        modal.hidden = false;

        try {
            const res = await fetch('../api/downstream_history.php?limit=1000&_=' + Date.now(), { cache: 'no-store' });
            const json = await res.json();
            const history = Array.isArray(json?.data?.downstream_history) ? json.data.downstream_history : [];
            state.downstreamHistory = history;
            renderDownstreamHistoryRows(history);
        } catch (err) {
            const history = Array.isArray(state.downstreamHistory) ? state.downstreamHistory : [];
            renderDownstreamHistoryRows(history);
            if (!history.length) {
                appendDetailRow(body, 'Error', 'Could not read downstream history API.');
            }
        }
    }



    function renderIaxDetail(conn = {}) {
        const modal = el('modalBackdrop');
        const title = el('modalTitle');
        const body = el('modalBody');
        if (!modal || !title || !body) return;

        title.textContent = 'IAX Client Details';
        body.innerHTML = '';
        modal.hidden = false;

        appendDetailRow(body, 'Source', 'IAX');
        appendDetailRow(body, 'Channel', conn.iax_channel || conn.asterisk_channel || conn.node);
        appendDetailRow(body, 'Username', conn.iax_username || 'N/A');
        appendDetailRow(body, 'Peer / IP', conn.iax_peer || conn.observed_ip || conn.remote || 'N/A');
        appendDetailRow(body, 'Context', conn.iax_context || 'N/A');
        appendDetailRow(body, 'Extension', conn.iax_extension || 'N/A');
        appendDetailRow(body, 'State', conn.asterisk_state || conn.state || 'connected');
        appendDetailRow(body, 'Direction', conn.direction || 'IN');
        appendDetailRow(body, 'Mode', connectionModeLabel(conn));
        appendDetailRow(body, 'Rpt Data', conn.rpt_data || 'N/A');
        appendDetailRow(body, 'Note', 'Direct IAX client/channel reported by Asterisk. This is read-only status.');
    }

    function renderEchoLinkDetail(node, conn = {}) {
        const modal = el('modalBackdrop');
        const title = el('modalTitle');
        const body = el('modalBody');
        if (!modal || !title || !body) return;

        const merged = conn || {};
        const echoNode = echoLinkDisplayId(merged.node || node) || safeBlank(merged.node || node);

        title.textContent = echoNode ? `EchoLink ${echoNode}` : 'EchoLink Details';
        body.innerHTML = '';
        modal.hidden = false;

        appendDetailRow(body, 'Source', 'EchoLink');
        appendDetailRow(body, 'EchoLink Node', echoNode || 'N/A');
        appendDetailRow(body, 'Node / Label', echoLinkLabel(merged));
        appendDetailRow(body, 'AllStar Node Page', 'N/A');
        appendDetailRow(body, 'QRZ', 'N/A');
        appendDetailRow(body, 'Status', merged.state || merged.status);
        appendDetailRow(body, 'Direction', merged.direction);
        appendDetailRow(body, 'Observed IP', 'N/A');
        appendDetailRow(body, 'Mode', 'EchoLink');
        appendDetailRow(body, 'Link', merged.link);
        appendDetailRow(body, 'Elapsed', merged.elapsed || merged.connection_time || merged.duration);
        appendDetailRow(body, 'Note', 'EchoLink details are limited. EchoLink node numbers are not AllStarLink nodes and are not linked to AllStarLink or QRZ.');
    }

    async function openNodeDetail(node, conn = null) {
        const modal = el('modalBackdrop');
        const title = el('modalTitle');
        const body = el('modalBody');
        if (!modal || !title || !body) return;

        title.textContent = isEchoLinkConnection(conn || { node }) ? `EchoLink ${echoLinkDisplayId(node) || node}` : `Node ${node}`;
        body.innerHTML = '<div class="item-meta">Loading node details...</div>';
        modal.hidden = false;

        if (conn && isIaxConnection(conn)) {
            renderIaxDetail(conn);
            return;
        }

        if (conn && isEchoLinkConnection(conn)) {
            renderEchoLinkDetail(node, conn);
            return;
        }

        try {
            const detail = await fetchJson(`../api/node_lookup.php?node=${encodeURIComponent(node)}`);
            let live = conn || {};
            const currentLive = (state.lastConnections || []).find((item) => String(item.node || '') === String(node || ''));
            const downstreamLive = (state.downstreamLinks || []).find((item) => String(item.node || '') === String(node || ''));
            if (downstreamLive) live = Object.assign({}, live, downstreamLive);
            if (currentLive) live = Object.assign({}, live, currentLive);
            const merged = Object.assign({}, detail, live);

            body.innerHTML = '';

            if (isEchoLinkConnection(merged)) {
                appendDetailRow(body, 'EchoLink Node', echoLinkDisplayId(merged.node) || merged.node);
                appendDetailRow(body, 'AllStar Node Page', 'N/A');
                appendDetailRow(body, 'QRZ', 'N/A');
            } else {
                appendNodeCallDetailRow(body, merged.node || node, merged.callsign || '', {
                    nodeHref: merged.allstar_node_url || allStarNodeUrl(merged.node || node),
                    callsignHref: merged.callsign_url || qrzUrl(merged.callsign || ''),
                });
            }

            appendDetailRow(body, 'Description', merged.description);
            appendDetailRow(body, 'Location', merged.location);
            appendDetailRow(body, 'Status', merged.state || merged.status);
            appendDetailRow(body, 'Direction', merged.direction);
            appendDetailRow(body, 'Observed IP', displayIp(merged));
            appendDetailRow(body, 'Mode', connectionModeLabel(merged));
            appendDetailRow(body, 'Link', merged.link);
            appendDetailRow(body, 'Elapsed', merged.elapsed || merged.connection_time);
            appendLinkedNodes(body, 'Linked / downstream nodes', Array.isArray(merged.linked_nodes_seen) && merged.linked_nodes_seen.length ? merged.linked_nodes_seen : state.downstreamLinks);
            appendDetailRow(body, 'Note', detail.note);
        } catch (err) {
            body.innerHTML = '';
            const div = document.createElement('div');
            div.className = 'warning';
            div.textContent = `Lookup failed: ${err.message}`;
            body.appendChild(div);
        }
    }

    async function refresh() {
        try {
            const payload = await fetchJson('../api/status.php');
            const data = payload.data || {};
            const config = data.config || {};

            state.lastData = data;
            state.lastConnections = data.current_connections || [];
            state.downstreamLinks = data.downstream_links || [];
            state.downstreamHistory = data.downstream_history_preview || [];

            if (config.poll_interval_seconds) {
                state.pollMs = Math.max(1000, Number(config.poll_interval_seconds) * 1000);
            }

            updateConfiguredNodeLinks(config);
            setText('lastUpdated', fmtTime(data.timestamp));
            renderWarnings(data.warnings || []);
            renderConnections(state.lastConnections);
            renderLiveActivity(data.live_activity || []);
            renderHistory(data.history_preview || []);
            renderDownstreamNodes(state.downstreamLinks);
        } catch (err) {
            renderWarnings([`API error: ${err.message}`]);
        } finally {
            scheduleNext();
        }
    }

    function scheduleNext() {
        if (state.timer) clearTimeout(state.timer);
        state.timer = setTimeout(refresh, state.pollMs);
    }

    const downstreamHistoryButton = el('downstreamHistoryButton');
    if (downstreamHistoryButton) downstreamHistoryButton.addEventListener('click', openDownstreamHistory);

    const closeButton = el('modalClose');
    if (closeButton) closeButton.addEventListener('click', () => { el('modalBackdrop').hidden = true; });

    const backdrop = el('modalBackdrop');
    if (backdrop) {
        backdrop.addEventListener('click', (evt) => {
            if (evt.target === backdrop) backdrop.hidden = true;
        });
    }

    refresh();
})();
