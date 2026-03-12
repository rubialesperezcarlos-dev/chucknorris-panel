<?php
/**
 * Chuck Norris AI - Web Dashboard
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth_web.php';
// Calcular URLs - funciona tanto en raíz como en subdirectorio
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
$proxyUrl = $scriptDir . '/proxy.php';
$downloadToken = defined('DASHBOARD_API_KEY') ? hash('sha256', DASHBOARD_API_KEY) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Chuck Norris AI - Control Panel</title>
    <style>
        :root {
            --bg: #0d1117;
            --surface: #161b22;
            --border: #30363d;
            --text: #e6edf3;
            --accent: #58a6ff;
            --success: #3fb950;
            --warn: #d29922;
            --danger: #f85149;
        }
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { font-size: 1.75rem; margin-bottom: 24px; color: var(--accent); }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border); }
        th { color: #8b949e; font-weight: 600; }
        button, .btn { background: var(--accent); color: #fff; border: none; padding: 10px 18px; border-radius: 6px; cursor: pointer; font-size: 14px; }
        button:hover, .btn:hover { opacity: 0.9; }
        button:disabled { opacity: 0.5; cursor: not-allowed; }
        input[type="url"], input[type="text"] { width: 100%; max-width: 500px; padding: 10px 12px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg); color: var(--text); }
        .status { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; }
        .status.online { background: rgba(63,185,80,0.2); color: var(--success); }
        .status.offline { background: rgba(248,81,73,0.2); color: var(--danger); }
        .status.running { background: rgba(88,166,255,0.2); color: var(--accent); }
        .status.pending { background: rgba(210,153,34,0.2); color: var(--warn); }
        .status.completed { background: rgba(63,185,80,0.2); color: var(--success); }
        .status.failed { background: rgba(248,81,73,0.2); color: var(--danger); }
        #logStream { font-family: 'Consolas', monospace; font-size: 12px; background: #0d1117; border: 1px solid var(--border); border-radius: 6px; padding: 12px; height: 400px; overflow-y: auto; white-space: pre-wrap; word-break: break-all; }
        .log-line { margin: 2px 0; }
        .log-line.error { color: var(--danger); }
        .log-line.warn { color: var(--warn); }
        .hidden { display: none; }
        .flex { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Chuck Norris AI – Panel de control</h1>
        <p style="margin-top: -12px; margin-bottom: 20px;"><a href="logout.php">Cerrar sesión</a></p>

        <div class="card">
            <h2 style="margin-top:0;">Nuevo escaneo</h2>
            <div class="flex">
                <input type="url" id="targetUrl" placeholder="https://ejemplo.com" />
                <button id="btnCreate">Iniciar escaneo</button>
            </div>
            <p style="color:#8b949e; font-size: 13px; margin-top: 8px;">Se asignará al worker con más RAM disponible (32GB prioritario).</p>
            <p style="color:#8b949e; font-size: 12px; margin-top: 12px; border-left: 3px solid var(--warn); padding-left: 10px;">
                <strong>¿Terminó o está colgado?</strong> En la tabla mira <em>Último log</em> y <em>Fin</em>: si sigue <span class="status running">running</span> y el último log no cambia &gt;5–10 min, en el servidor ejecuta <code>docker ps</code> (¿sigue el contenedor?) y <code>docker logs &lt;id&gt; --tail 50</code>. Cuando acaba, estado → <span class="status completed">completed</span>/<span class="status failed">failed</span> y <em>Fin</em> tiene fecha.
                Cuando acaba, el estado pasa a <span class="status completed">completed</span> o <span class="status failed">failed</span> y aparece el enlace de reporte.
            </p>
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Workers</h2>
            <div id="workersList">Cargando…</div>
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Tareas</h2>
            <div id="tasksList">Cargando…</div>
        </div>

        <div class="card" id="logPanel" style="display:none;">
            <h2 style="margin-top:0;">Logs en vivo – Tarea <span id="logTaskId"></span></h2>
            <p id="logMeta" style="color:#8b949e; font-size: 12px; margin-top: 0;"></p>
            <div id="logStream"></div>
            <p style="margin-bottom:0;"><button id="btnCloseLog">Cerrar</button></p>
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Reportes</h2>
            <div id="reportsList">Cargando…</div>
        </div>
    </div>

    <script>
        const proxy = '<?= htmlspecialchars($proxyUrl) ?>';
        const downloadToken = '<?= htmlspecialchars($downloadToken) ?>';

        function api(path, options = {}) {
            const url = proxy + '?path=' + encodeURIComponent(path);
            return fetch(url, { method: options.method || 'GET', ...options }).then(async r => {
                let data;
                try { data = await r.json(); } catch (e) { data = { error: 'Respuesta no JSON (' + r.status + ')' }; }
                if (!r.ok) return Promise.reject(data);
                return data;
            });
        }

        function post(path, body) {
            return api(path, { method: 'POST', body: JSON.stringify(body), headers: { 'Content-Type': 'application/json' } });
        }

        // Workers
        function loadWorkers() {
            api('workers/list').then(data => {
                const list = document.getElementById('workersList');
                if (!data.workers || data.workers.length === 0) {
                    list.innerHTML = '<p>No hay workers registrados.</p>';
                    return;
                }
                list.innerHTML = '<table><thead><tr><th>Host</th><th>RAM</th><th>CPU</th><th>Tareas</th><th>Estado</th><th>Último heartbeat</th></tr></thead><tbody>' +
                    data.workers.map(w => '<tr><td>' + escapeHtml(w.hostname) + '</td><td>' + (w.ram_used_mb || 0) + ' / ' + (w.ram_total_mb || 0) + ' MB</td><td>' + (w.cpu_usage_percent || 0) + '%</td><td>' + (w.active_tasks || 0) + '</td><td><span class="status ' + (w.status || 'offline') + '">' + (w.status || 'offline') + '</span></td><td>' + (w.last_heartbeat_at || '-') + '</td></tr>').join('') +
                    '</tbody></table>';
            }).catch(() => { document.getElementById('workersList').innerHTML = '<p>Error al cargar workers.</p>'; });
        }

        // Tasks
        function loadTasks() {
            api('tasks/list').then(data => {
                const list = document.getElementById('tasksList');
                if (!data.tasks || data.tasks.length === 0) {
                    list.innerHTML = '<p>No hay tareas.</p>';
                    return;
                }
                list.innerHTML = '<table><thead><tr><th>ID</th><th>URL</th><th>Estado</th><th>Inicio</th><th>Último log</th><th>Fin</th><th>Worker</th><th>Acciones</th></tr></thead><tbody>' +
                    data.tasks.map(t => {
                        const lastLog = t.last_log_at || '—';
                        let actions = '<button class="btn btn-log" data-id="' + t.id + '">Ver logs</button> ';
                        if (t.status === 'completed' || t.status === 'failed') {
                            const taskReports = reportsMap[t.id] || [];
                            const htmlReport = taskReports.find(r => (r.filename || '').endsWith('.html'));
                            if (htmlReport) {
                                actions += '<a href="download_report.php?id=' + htmlReport.id + '&token=' + downloadToken + '&view=1" class="btn" target="_blank" style="background:#3fb950;">Ver Reporte</a> ';
                            }
                            actions += '<a href="generate_report.php?task_id=' + t.id + '&token=' + downloadToken + '" class="btn" target="_blank" style="background:#30363d;">Raw</a>';
                        }
                        return '<tr><td>' + t.id + '</td><td>' + escapeHtml(t.target_url) + '</td><td><span class="status ' + (t.status || '') + '">' + (t.status || '') + '</span></td><td>' + (t.started_at || '—') + '</td><td title="Si running y esto no cambia hace varios minutos, puede estar colgado">' + lastLog + '</td><td>' + (t.completed_at || '—') + '</td><td>' + escapeHtml(t.worker_hostname || '-') + '</td><td>' + actions + '</td></tr>';
                    }).join('') +
                    '</tbody></table>';
                document.querySelectorAll('.btn-log').forEach(el => el.addEventListener('click', () => openLog(el.dataset.id)));
            }).catch(() => { document.getElementById('tasksList').innerHTML = '<p>Error al cargar tareas.</p>'; });
        }

        function escapeHtml(s) {
            if (!s) return '';
            const d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        // Create task
        document.getElementById('btnCreate').addEventListener('click', () => {
            const url = document.getElementById('targetUrl').value.trim();
            if (!url) return alert('Introduce una URL.');
            document.getElementById('btnCreate').disabled = true;
            post('tasks/create', { target_url: url }).then(data => {
                if (data.error) alert(data.error);
                else { document.getElementById('targetUrl').value = ''; loadTasks(); }
            }).catch(err => {
                alert(err && err.error ? err.error : 'Error al crear la tarea');
            }).finally(() => { document.getElementById('btnCreate').disabled = false; });
        });

        // Reports list: by task — save for cross-referencing with tasks table
        let reportsMap = {};

        function loadReports() {
            api('reports/list').then(data => {
                const list = document.getElementById('reportsList');
                reportsMap = {};
                if (!data.reports || data.reports.length === 0) {
                    list.innerHTML = '<p>No hay reportes.</p>';
                    return;
                }
                data.reports.forEach(r => {
                    const tid = parseInt(r.task_id, 10);
                    if (!reportsMap[tid]) reportsMap[tid] = [];
                    reportsMap[tid].push(r);
                });
                list.innerHTML = '<table><thead><tr><th>ID</th><th>Task</th><th>Archivo</th><th>Tamaño</th><th>Fecha</th><th>Acciones</th></tr></thead><tbody>' +
                    data.reports.map(r => {
                        const isHtml = (r.filename || '').endsWith('.html');
                        const viewLink = isHtml ? '<a href="download_report.php?id=' + r.id + '&token=' + downloadToken + '&view=1" target="_blank" style="margin-right:8px;">Ver</a>' : '';
                        const dlLink = '<a href="download_report.php?id=' + r.id + '&token=' + downloadToken + '">Descargar</a>';
                        const sizeKb = r.file_size ? (Math.round(parseInt(r.file_size)/1024) + ' KB') : '-';
                        const icon = isHtml ? '📄 ' : '📝 ';
                        return '<tr><td>' + r.id + '</td><td>' + r.task_id + '</td><td>' + icon + escapeHtml(r.filename) + '</td><td>' + sizeKb + '</td><td>' + (r.created_at || '') + '</td><td>' + viewLink + dlLink + '</td></tr>';
                    }).join('') +
                    '</tbody></table>';
                loadTasks();
            }).catch(() => { document.getElementById('reportsList').innerHTML = '<p>Error al cargar reportes.</p>'; });
        }

        // Live logs
        let logTaskId = null;
        let logPollTimer = null;
        let lastLogId = 0;

        let logMetaTimer = null;

        function refreshLogMeta() {
            if (!logTaskId) return;
            api('tasks/get?id=' + logTaskId).then(t => {
                const meta = document.getElementById('logMeta');
                if (!meta) return;
                const st = t.status || '?';
                let txt = 'Estado actual: <strong>' + st + '</strong>';
                if (t.started_at) txt += ' · Inicio: ' + t.started_at;
                if (t.completed_at) txt += ' · Fin: ' + t.completed_at + ' (escaneo terminado)';
                else if (st === 'running') txt += ' · Si lleva mucho rato sin cambiar, revisa el worker (docker ps / logs).';
                meta.innerHTML = txt;
            }).catch(() => {});
        }

        function openLog(taskId) {
            logTaskId = parseInt(taskId, 10);
            document.getElementById('logPanel').style.display = 'block';
            document.getElementById('logTaskId').textContent = logTaskId;
            document.getElementById('logStream').innerHTML = '';
            document.getElementById('logMeta').textContent = 'Cargando estado…';
            lastLogId = 0;
            logInitialLoad = true;
            if (logMetaTimer) clearInterval(logMetaTimer);
            logMetaTimer = setInterval(refreshLogMeta, 8000);
            refreshLogMeta();
            pollLogs();
        }

        let logInitialLoad = true;

        // Quitar códigos ANSI para que se lea en el navegador (PentestGPT/TUI mete muchos ESC[...)
        function stripAnsi(s) {
            if (!s) return '';
            return String(s).replace(/\x1b\[[0-9;?]*[ -/]*[@-~]/g, '').replace(/\x1b\][^\x07]*\x07/g, '');
        }

        function appendLogLine(stream, l) {
            const div = document.createElement('div');
            div.className = 'log-line ' + (l.level || '');
            const line = stripAnsi(l.log_line || '');
            div.textContent = line;
            stream.appendChild(div);
        }

        function pollLogs() {
            if (!logTaskId) return;
            const stream = document.getElementById('logStream');
            // Primera petición: sin after_id para que la API devuelva cola (últimas líneas). Siguientes: incremental por id.
            let path;
            if (lastLogId > 0) {
                path = 'tasks/logs/get?task_id=' + logTaskId + '&after_id=' + lastLogId + '&limit=200';
            } else {
                path = 'tasks/logs/get?task_id=' + logTaskId + '&limit=500';
            }
            api(path).then(data => {
                if (data.error) {
                    const div = document.createElement('div');
                    div.className = 'log-line error';
                    div.textContent = '[API] ' + (data.error || 'Error desconocido');
                    stream.appendChild(div);
                    schedulePoll();
                    return;
                }
                if (!data.logs || !data.logs.length) {
                    if (logInitialLoad) {
                        const div = document.createElement('div');
                        div.className = 'log-line';
                        div.textContent = '(Sin líneas aún. El worker enviará logs al arrancar el escaneo. Reintentando…)';
                        if (!stream.querySelector('.log-line')) stream.appendChild(div);
                    }
                    logInitialLoad = false;
                    schedulePoll();
                    return;
                }
                data.logs.forEach(l => {
                    lastLogId = Math.max(lastLogId, parseInt(l.id, 10) || 0);
                    appendLogLine(stream, l);
                });
                logInitialLoad = false;
                stream.scrollTop = stream.scrollHeight;
                schedulePoll();
            }).catch(err => {
                const div = document.createElement('div');
                div.className = 'log-line error';
                const msg = err && err.error ? err.error : 'No se pudieron cargar los logs';
                const extra = err && err.message ? ' — ' + err.message
                    : (err && err.detail ? ' — ' + err.detail : (err && err.preview ? ' — ' + err.preview : ''));
                div.textContent = '[Red] ' + msg + extra;
                if (!stream.querySelector('.log-line.error')) stream.appendChild(div);
                schedulePoll();
            });
        }

        function schedulePoll() {
            if (logPollTimer) clearTimeout(logPollTimer);
            if (!logTaskId) return;
            logPollTimer = setTimeout(pollLogs, 1500);
        }

        document.getElementById('btnCloseLog').addEventListener('click', () => {
            if (logPollTimer) clearTimeout(logPollTimer);
            if (logMetaTimer) clearInterval(logMetaTimer);
            logTaskId = null;
            logPollTimer = null;
            logMetaTimer = null;
            document.getElementById('logPanel').style.display = 'none';
        });

        // Refresh lists — reports first so reportsMap is ready when tasks render
        loadWorkers();
        loadReports();
        setInterval(loadWorkers, 10000);
        setInterval(loadReports, 15000);
    </script>
</body>
</html>
