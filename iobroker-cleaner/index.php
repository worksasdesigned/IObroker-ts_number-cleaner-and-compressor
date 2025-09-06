<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>iobroker ts_number Bereinigung</title>
    <style>
        :root {
            --bg: #0f172a;
            --panel: #111827;
            --text: #e5e7eb;
            --muted: #9ca3af;
            --primary: #06b6d4;
            --accent: #84cc16;
            --danger: #ef4444;
            --warning: #f59e0b;
            --border: #1f2937;
        }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, Helvetica, Arial, sans-serif; background: var(--bg); color: var(--text); }
        header { padding: 20px; border-bottom: 1px solid var(--border); background: linear-gradient(180deg, #0b1220, #0f172a); }
        h1 { margin: 0; font-size: 20px; font-weight: 600; letter-spacing: 0.2px; }
        main { display: grid; grid-template-columns: 350px 1fr; gap: 24px; padding: 24px; }
        .panel { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 16px; }
        .panel h2 { margin: 0 0 12px 0; font-size: 16px; font-weight: 600; }
        .form-row { display: grid; grid-template-columns: 120px 1fr; align-items: center; gap: 10px; margin-bottom: 10px; }
        label { color: var(--muted); font-size: 13px; }
        input[type="text"], input[type="number"], input[type="datetime-local"] { width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); background: #0b1220; color: var(--text); }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 14px; border-radius: 10px; border: 1px solid var(--border); cursor: pointer; transition: all 0.15s ease; font-weight: 600; font-size: 14px; }
        .btn-primary { background: var(--primary); color: #001219; border-color: #0891b2; }
        .btn-primary:hover { filter: brightness(1.1); }
        .btn-danger { background: var(--danger); color: #fff; border-color: #b91c1c; }
        .btn-outline { background: transparent; color: var(--text); }
        .btn-success { background: var(--accent); color: #001219; border-color: #4d7c0f; }
        .btn-success:hover { filter: brightness(1.1); }
        .btn-light { background: #e5e7eb; color: #111827; border-color: #d1d5db; }
        .btn-light:hover { filter: brightness(0.98); }
        .stack { display: grid; gap: 16px; }
        .muted { color: var(--muted); font-size: 12px; }
        .divider { height: 1px; background: var(--border); margin: 10px 0; }
        .progress { width: 100%; height: 14px; background: #0b1220; border: 1px solid var(--border); border-radius: 999px; overflow: hidden; }
        .progress > .bar { height: 100%; width: 0%; background: linear-gradient(90deg, var(--accent), #22d3ee); transition: width 0.2s ease; }
        .grid-two { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
        .stat { background: #0b1220; border: 1px solid var(--border); border-radius: 10px; padding: 10px; font-size: 13px; }
        .stat b { font-size: 16px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { padding: 8px 10px; border-bottom: 1px solid var(--border); text-align: left; }
        th { position: sticky; top: 0; background: #0e1626; z-index: 1; }
        .tbl-wrap { max-height: 55vh; overflow: auto; border: 1px solid var(--border); border-radius: 10px; }
        .row-err { background: rgba(239, 68, 68, 0.15); color: #fecaca; }
        .small { font-size: 11px; }
        .hint { color: var(--muted); font-size: 12px; }
        footer { padding: 12px 24px; color: var(--muted); border-top: 1px solid var(--border); }
        code { background: #0b1220; border: 1px solid var(--border); border-radius: 6px; padding: 2px 6px; }
        .num { text-align: right; }
        .col-count { width: 140px; }
    </style>
    <script>
        const DEFAULT_ID = <?php echo json_encode(DEFAULT_STATE_ID); ?>;
        const DROP_THRESHOLD = <?php echo json_encode(DROP_THRESHOLD); ?>;
        const SPIKE_THRESHOLD = <?php echo json_encode(SPIKE_THRESHOLD); ?>;

        function $(sel) { return document.querySelector(sel); }
        function create(tag, attrs={}) { const el = document.createElement(tag); Object.assign(el, attrs); return el; }

        function resetProgress() {
            $('#progressBar').style.width = '0%';
            $('#progressText').textContent = '0%';
            $('#processed').textContent = '0';
            $('#deleted').textContent = '0';
            $('#total').textContent = '0';
        }

        function setBusy(busy) {
            [ '#btnTest', '#btnRun', '#btnAnalyze', '#btnAnalyzeDp' ].forEach(id => {
                const el = document.querySelector(id);
                if (el) el.disabled = busy;
            });
        }

        async function doAnalyzeDatapoints() {
            setBusy(true);
            try {
                const fd = new FormData();
                fd.set('action', 'analyze_datapoints');
                const res = await fetch('action.php', { method: 'POST', body: fd });
                let data;
                try { data = await res.json(); } catch(e) { throw new Error(`HTTP ${res.status} - keine JSON Antwort`); }
                if (!res.ok || !data.ok) { throw new Error((data && data.error) || `HTTP ${res.status}`); }
                const tbody = document.getElementById('dpRowsRight');
                tbody.innerHTML = '';
                for (const r of data.rows) {
                    const tr = document.createElement('tr');
                    const cells = [ r.id, r.name, r.anzahl ];
                    cells.forEach((c, idx) => { const td = document.createElement('td'); td.textContent = String(c); if (idx === 2) td.classList.add('num'); tr.appendChild(td); });
                    tbody.appendChild(tr);
                }
            } catch (e) {
                alert(`Analyse fehlgeschlagen: ${e.message || e}`);
            } finally {
                setBusy(false);
            }
        }

        async function streamAction(formData, { onEvent, onDone }) {
            try {
                console.log('Sende Request an action.php mit Daten:', Object.fromEntries(formData));
                
                const res = await fetch('action.php', {
                    method: 'POST',
                    body: formData
                });
                
                console.log('Response Status:', res.status, res.statusText);
                
                if (!res.ok) {
                    const text = await res.text();
                    console.error('Server Fehler:', text);
                    throw new Error(`Server antwortete mit Status ${res.status}: ${text}`);
                }
                
                const reader = res.body.getReader();
                const decoder = new TextDecoder('utf-8');
                let buffer = '';
                let eventCount = 0;
                
                while (true) {
                    const { value, done } = await reader.read();
                    if (done) break;
                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop();
                    for (const line of lines) {
                        const s = line.trim();
                        if (!s) continue;
                        try {
                            const evt = JSON.parse(s);
                            eventCount++;
                            console.log(`Event #${eventCount}:`, evt);
                            onEvent && onEvent(evt);
                        } catch (e) {
                            console.warn('JSON Parse fehlgeschlagen für Zeile:', line, 'Fehler:', e);
                        }
                    }
                }
                
                console.log(`Stream beendet. ${eventCount} Events empfangen.`);
                onDone && onDone();
                
            } catch (error) {
                console.error('streamAction Fehler:', error);
                throw error;
            }
        }

        function updateProgress(processed, deleted, total) {
            $('#processed').textContent = String(processed);
            $('#deleted').textContent = String(deleted);
            $('#total').textContent = String(total);
            const pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
            $('#progressBar').style.width = pct + '%';
            $('#progressText').textContent = pct + '%';
        }

        function addRowToTable(row) {
            const tbody = $('#rows');
            const tr = document.createElement('tr');
            if (row.anomaly) tr.classList.add('row-err');
            const cells = [ row.ts_readable, row.val, row.delta_prev, row.delta_prev_good, row.anomaly ? 'Ja' : 'Nein' ];
            for (const c of cells) {
                const td = document.createElement('td');
                td.textContent = String(c);
                tr.appendChild(td);
            }
            tbody.appendChild(tr);
        }

        async function doAnalyze() {
            setBusy(true);
            try {
                const id = Number($('#paramId').value || DEFAULT_ID);
                const fromValue = $('#paramFrom').value;
                const toValue = $('#paramTo').value;
                const fd = new FormData();
                fd.set('action', 'analyze');
                fd.set('id', String(id));
                if (fromValue) fd.set('from', fromValue);
                if (toValue) fd.set('to', toValue);
                const res = await fetch('action.php', { method: 'POST', body: fd });
                let data;
                try {
                    data = await res.json();
                } catch (e) {
                    throw new Error(`HTTP ${res.status} - Server lieferte keine JSON-Antwort`);
                }
                if (!res.ok || !data.ok) {
                    const msg = (data && data.error) ? data.error : `HTTP ${res.status}`;
                    const details = (data && data.details) ? `\nDetails: ${data.details}` : '';
                    throw new Error(`${msg}${details}`);
                }
                $('#anaTotal').textContent = String(data.count);
                $('#anaMin').textContent = data.min_ts_readable || '-';
                $('#anaMax').textContent = data.max_ts_readable || '-';
            } catch (e) {
                alert(`Analyse fehlgeschlagen: ${e.message || e}`);
            } finally {
                setBusy(false);
            }
        }

        async function doTest() {
            resetProgress();
            $('#rows').innerHTML = '';
            setBusy(true);
            
            // Überprüfe ob Felder ausgefüllt sind
            const fromValue = $('#paramFrom').value;
            const toValue = $('#paramTo').value;
            
            if (!fromValue || !toValue) {
                alert('Bitte füllen Sie beide Felder "Von" und "Bis" aus!');
                setBusy(false);
                return;
            }
            
            const id = Number($('#paramId').value || DEFAULT_ID);
            const fd = new FormData();
            fd.set('action', 'test');
            fd.set('id', String(id));
            fd.set('from', fromValue);
            fd.set('to', toValue);
            
            console.log('Testlauf gestartet:', { from: fromValue, to: toValue });
            
            let processed = 0, anomalies = 0, total = 0;
            try {
                await streamAction(fd, {
                    onEvent: (evt) => {
                        console.log('Event empfangen:', evt);
                        if (evt.type === 'meta') {
                            total = evt.total || 0;
                            updateProgress(0, 0, total);
                            $('#metaText').textContent = `Fenster: ${new Date(evt.window_from).toLocaleString()} – ${new Date(evt.window_to).toLocaleString()} | ID: ${evt.id}`;
                        } else if (evt.type === 'row') {
                            processed = evt.processed; anomalies = evt.anomalies; total = evt.total;
                            updateProgress(processed, anomalies, total);
                            addRowToTable(evt);
                        } else if (evt.type === 'summary') {
                            processed = evt.processed; anomalies = evt.anomalies; total = evt.total;
                            updateProgress(processed, anomalies, total);
                            document.getElementById('finalStats').textContent = `Testlauf: ${processed} Zeilen, Anomalien: ${anomalies}`;
                        } else if (evt.error) {
                            console.error('Fehler vom Server:', evt.error);
                            alert(`Fehler: ${evt.error}`);
                        }
                    },
                    onDone: () => { 
                        console.log('Stream beendet');
                        setBusy(false); 
                    }
                });
            } catch (error) {
                console.error('Fehler beim Testlauf:', error);
                alert(`Fehler beim Testlauf: ${error.message || error}`);
                setBusy(false);
            }
        }

        async function doRun() {
            resetProgress();
            setBusy(true);
            
            const id = Number($('#paramId').value || DEFAULT_ID);
            const fromValue = $('#paramFrom').value;
            const toValue = $('#paramTo').value;
            
            if (!confirm(`ACHTUNG: Dies wird alle Anomalien für ID ${id} LÖSCHEN!\n\nSind Sie sicher, dass Sie fortfahren möchten?`)) {
                setBusy(false);
                return;
            }
            
            const fd = new FormData();
            fd.set('action', 'run');
            fd.set('id', String(id));
            if (fromValue) fd.set('from', fromValue);
            if (toValue) fd.set('to', toValue);
            
            console.log('Echtlauf gestartet für ID:', id);
            
            let processed = 0, deleted = 0, total = 0;
            
            try {
                await streamAction(fd, {
                    onEvent: (evt) => {
                        console.log('Event empfangen:', evt);
                        if (evt.type === 'meta') {
                            total = evt.total || 0;
                            updateProgress(0, 0, total);
                            $('#metaText').textContent = `Echtlauf für ID ${evt.id} gestartet - ${total} Einträge zu prüfen`;
                        } else if (evt.type === 'progress') {
                            processed = evt.processed; deleted = evt.deleted; total = evt.total;
                            updateProgress(processed, deleted, total);
                        } else if (evt.type === 'summary') {
                            processed = evt.processed; deleted = evt.deleted; total = evt.total_before;
                            updateProgress(processed, deleted, total);
                            $('#finalStats').textContent = `Entfernt: ${deleted}, Vorher: ${evt.total_before}, Jetzt: ${evt.total_after}`;
                            alert(`Echtlauf abgeschlossen!\n\nEntfernte Anomalien: ${deleted}\nEinträge vorher: ${evt.total_before}\nEinträge nachher: ${evt.total_after}`);
                        } else if (evt.error) {
                            console.error('Fehler vom Server:', evt.error);
                            alert(`Fehler: ${evt.error}`);
                        }
                    },
                    onDone: () => { 
                        console.log('Stream beendet');
                        setBusy(false); 
                    }
                });
            } catch (error) {
                console.error('Fehler beim Echtlauf:', error);
                alert(`Fehler beim Echtlauf: ${error.message || error}`);
                setBusy(false);
            }
        }
        
        // Setze Standardwerte für zentrale Parameter beim Laden der Seite
        window.addEventListener('DOMContentLoaded', function() {
            // Setze "Von" auf vor 7 Tagen
            const fromDate = new Date();
            fromDate.setDate(fromDate.getDate() - 7);
            fromDate.setHours(0, 0, 0, 0);
            
            // Setze "Bis" auf heute
            const toDate = new Date();
            toDate.setHours(23, 59, 59, 999);
            
            // Formatiere für datetime-local input
            const formatDateTime = (date) => {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                const hours = String(date.getHours()).padStart(2, '0');
                const minutes = String(date.getMinutes()).padStart(2, '0');
                return `${year}-${month}-${day}T${hours}:${minutes}`;
            };
            
            const elFrom = $('#paramFrom'); if (elFrom) elFrom.value = formatDateTime(fromDate);
            const elTo = $('#paramTo'); if (elTo) elTo.value = formatDateTime(toDate);
            const elId = $('#paramId'); if (elId) elId.value = String(DEFAULT_ID);
            
            console.log('Seite geladen. Standardzeitfenster gesetzt:', {
                from: $('#paramFrom').value,
                to: $('#paramTo').value,
                id: $('#paramId').value
            });
        });
    </script>
    <meta name="color-scheme" content="dark light">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <style>
        .actions { display: grid; gap: 16px; }
    </style>
</head>
<body>
    <header>
        <h1>iobroker ts_number Bereinigungstool</h1>
        <div class="muted small">Erkennt Ausreißer (Sprünge über <?php echo (int) DROP_THRESHOLD; ?> / unter -<?php echo (int) DROP_THRESHOLD; ?>) und entfernt sie optional.</div>
        <div class="hint" style="margin-top:6px;display:flex;align-items:center;gap:8px;color:#fde68a;">
            <span aria-hidden="true">⚠️</span>
            <span>Hinweis: Schwellenwerte änderbar in <code>config.php</code> (→ DROP_THRESHOLD / SPIKE_THRESHOLD).</span>
        </div>
    </header>
    <main>
        <section class="panel stack">
            <div>
                <h2>Parameter</h2>
                <div class="divider"></div>
            </div>
            <div class="panel">
                <div class="form-row">
                    <label>Von</label>
                    <input type="datetime-local" id="paramFrom">
                </div>
                <div class="form-row">
                    <label>Bis</label>
                    <input type="datetime-local" id="paramTo">
                </div>
                <div class="form-row">
                    <label>ID</label>
                    <input type="number" id="paramId" value="<?php echo (int) DEFAULT_STATE_ID; ?>" min="1">
                </div>
                <div class="hint small">Diese Angaben gelten für Analyse, Testlauf und Echtlauf.</div>
            </div>
            <div style="margin-top:16px;">
                <h2>Aktionen</h2>
                <div class="divider"></div>
            </div>
            <div class="actions">
                <div class="panel">
                    <h2>1. Datapoints analyse</h2>
                    <div style="margin-top:10px;display:flex;gap:10px;">
                        <button class="btn btn-light" onclick="doAnalyzeDatapoints()">Datapoints Analyse</button>
                    </div>
                    <div class="tbl-wrap" style="margin-top:10px;">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Anzahl</th>
                                </tr>
                            </thead>
                            <tbody id="dpRows"></tbody>
                        </table>
                    </div>
                </div>
                <div class="panel">
                    <h2>2. Vorab Analyse</h2>
                    <div style="margin-top:10px;display:flex;gap:10px;">
                        <button class="btn btn-success" id="btnAnalyze" onclick="doAnalyze()">Analyse starten</button>
                    </div>
                    <div class="grid-two" style="margin-top:10px;">
                        <div class="stat">Gesamt: <br><b id="anaTotal">-</b></div>
                        <div class="stat">Kleinstes Datum: <br><b id="anaMin">-</b></div>
                        <div class="stat">Größtes Datum: <br><b id="anaMax">-</b></div>
                    </div>
                </div>
                <div class="panel">
                    <h2>3. Testlauf (max. <?php echo (int) TEST_LIMIT_ROWS; ?>)</h2>
                    <div style="margin-top:10px;display:flex;gap:10px;">
                        <button class="btn btn-primary" id="btnTest" onclick="doTest()">TESTLAUF</button>
                    </div>
                </div>
                <div class="panel">
                    <h2>4. Echtlauf</h2>
                    <div style="margin-top:10px;display:flex;gap:10px;">
                        <button class="btn btn-danger" id="btnRun" onclick="doRun()">ECHTLAUF (LÖSCHT!)</button>
                    </div>
                    <div class="hint small">Bitte Sicherung erstellen, bevor Sie den Echtlauf starten.</div>
                </div>
            </div>
        </section>
        <section class="panel stack">
            <h2>Fortschritt</h2>
            <div class="progress" title="Fortschritt">
                <div id="progressBar" class="bar"></div>
            </div>
            <div class="grid-two">
                <div class="stat">Bearbeitet<br><b id="processed">0</b></div>
                <div class="stat">Gelöscht/Fehler<br><b id="deleted">0</b></div>
                <div class="stat">Gesamt<br><b id="total">0</b></div>
                <div class="stat">Status<br><b id="progressText">0%</b></div>
            </div>
            <div class="small muted" id="metaText"></div>
            <div class="divider"></div>
            <h2>Testlauf-Daten</h2>
            <div class="tbl-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Wert</th>
                            <th>Delta zu vorher</th>
                            <th>Delta zu letztem OK</th>
                            <th>Anomalie</th>
                        </tr>
                    </thead>
                    <tbody id="rows"></tbody>
                </table>
            </div>
            <div class="small" id="finalStats"></div>
            <div class="divider"></div>
            <h2>Datapoints Analyse</h2>
            <div class="tbl-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th class="col-count">Anzahl</th>
                        </tr>
                    </thead>
                    <tbody id="dpRowsRight"></tbody>
                </table>
            </div>
        </section>
    </main>
    <footer>
        &copy; <?php echo date('Y'); ?> Bereinigung für iobroker ts_number | Entwickelt für große Datenmengen (Streaming)
    </footer>
</body>
</html>

