<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>iobroker ts_number Kompressor</title>
    <style>
        :root { --bg:#0f172a; --panel:#111827; --text:#e5e7eb; --muted:#9ca3af; --primary:#06b6d4; --accent:#84cc16; --danger:#ef4444; --border:#1f2937; }
        * { box-sizing: border-box; }
        body { margin:0; padding:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, Helvetica, Arial, sans-serif; background: var(--bg); color: var(--text); }
        header { padding: 20px; border-bottom: 1px solid var(--border); background: linear-gradient(180deg, #0b1220, #0f172a); }
        h1 { margin:0; font-size:20px; font-weight:600; letter-spacing:0.2px; }
        main { display:grid; grid-template-columns: 350px 1fr; gap:24px; padding:24px; }
        .panel { background: var(--panel); border:1px solid var(--border); border-radius:12px; padding:16px; }
        .panel h2 { margin:0 0 12px 0; font-size:16px; font-weight:600; }
        .form-row { display:grid; grid-template-columns: 120px 1fr; align-items:center; gap:10px; margin-bottom:10px; }
        label { color: var(--muted); font-size:13px; }
        input[type="text"], input[type="number"], input[type="datetime-local"] { width:100%; padding:10px 12px; border-radius:8px; border:1px solid var(--border); background:#0b1220; color: var(--text); }
        .btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:10px 14px; border-radius:10px; border:1px solid var(--border); cursor:pointer; transition: all 0.15s ease; font-weight:600; font-size:14px; }
        .btn-primary { background: var(--primary); color:#001219; border-color:#0891b2; }
        .btn-primary:hover { filter: brightness(1.1); }
        .btn-danger { background: var(--danger); color:#fff; border-color:#b91c1c; }
        .btn-outline { background: transparent; color: var(--text); }
        .btn-success { background: var(--accent); color:#001219; border-color:#4d7c0f; }
        .btn-success:hover { filter: brightness(1.1); }
        .stack { display:grid; gap:16px; }
        .muted { color: var(--muted); font-size:12px; }
        .divider { height:1px; background: var(--border); margin:10px 0; }
        .progress { width:100%; height:14px; background:#0b1220; border:1px solid var(--border); border-radius:999px; overflow:hidden; }
        .progress > .bar { height:100%; width:0%; background: linear-gradient(90deg, var(--accent), #22d3ee); transition: width 0.2s ease; }
        .grid-two { display:grid; grid-template-columns: repeat(2, 1fr); gap:12px; }
        .stat { background:#0b1220; border:1px solid var(--border); border-radius:10px; padding:10px; font-size:13px; }
        .stat b { font-size:16px; }
        table { width:100%; border-collapse: collapse; font-size:12px; }
        th, td { padding:8px 10px; border-bottom:1px solid var(--border); text-align:left; }
        th { position: sticky; top:0; background:#0e1626; z-index:1; }
        .tbl-wrap { max-height: 55vh; overflow:auto; border:1px solid var(--border); border-radius:10px; }
        .small { font-size:11px; }
        .hint { color: var(--muted); font-size:12px; }
        .actions { display:grid; gap:16px; }
        .intervals { display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap:8px; }
        .intervals label { display:flex; align-items:center; gap:6px; padding:6px 8px; background:#0b1220; border:1px solid var(--border); border-radius:8px; cursor:pointer; }
    </style>
    <script>
        const DEFAULT_ID = <?php echo json_encode(DEFAULT_STATE_ID); ?>;
        const INTERVALS = <?php echo json_encode(INTERVALS); ?>;

        function $(sel) { return document.querySelector(sel); }
        function updateProgress(processed, effected, total) {
            $('#processed').textContent = String(processed);
            $('#effected').textContent = String(effected);
            $('#total').textContent = String(total);
            const pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
            $('#progressBar').style.width = pct + '%';
            $('#progressText').textContent = pct + '%';
        }
        function setBusy(b) {
            [ '#btnAnalyze', '#btnTest', '#btnRun' ].forEach(id => { const el = document.querySelector(id); if (el) el.disabled = b; });
        }
        function getSelectedIntervalKey() {
            const el = document.querySelector('input[name="intervalKey"]:checked');
            return el ? el.value : '1h';
        }
        async function doAnalyze() {
            setBusy(true);
            try {
                const fd = new FormData();
                fd.set('action', 'analyze');
                const id = Number($('#paramId').value || DEFAULT_ID);
                fd.set('id', String(id));
                const f = $('#paramFrom').value; const t = $('#paramTo').value;
                if (f) fd.set('from', f); if (t) fd.set('to', t);
                const res = await fetch('action.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (!res.ok || !data.ok) throw new Error((data && data.error) || `HTTP ${res.status}`);
                $('#anaTotal').textContent = String(data.count);
                $('#anaMin').textContent = data.min_ts_readable || '-';
                $('#anaMax').textContent = data.max_ts_readable || '-';
            } catch (e) { alert(`Analyse fehlgeschlagen: ${e.message || e}`); }
            finally { setBusy(false); }
        }
        async function streamAction(fd, { onEvent, onDone }) {
            const res = await fetch('action.php', { method: 'POST', body: fd });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const reader = res.body.getReader(); const decoder = new TextDecoder('utf-8');
            let buf = '';
            while (true) {
                const { value, done } = await reader.read();
                if (done) break;
                buf += decoder.decode(value, { stream:true });
                const lines = buf.split('\n'); buf = lines.pop();
                for (const line of lines) { const s = line.trim(); if (!s) continue; try { const evt = JSON.parse(s); onEvent && onEvent(evt);} catch(_){} }
            }
            onDone && onDone();
        }
        async function doTest() {
            $('#rows').innerHTML='';
            updateProgress(0,0,0);
            setBusy(true);
            try {
                const fd = new FormData();
                fd.set('action', 'test');
                fd.set('id', String(Number($('#paramId').value || DEFAULT_ID)));
                fd.set('intervalKey', getSelectedIntervalKey());
                const f = $('#paramFrom').value; const t = $('#paramTo').value; if (!f || !t) throw new Error('Bitte Von/Bis wählen');
                fd.set('from', f); fd.set('to', t);
                let processed=0, effected=0, total=0;
                await streamAction(fd, { onEvent: (evt) => {
                    if (evt.type==='meta') { total = evt.total || 0; updateProgress(0,0,total); $('#metaText').textContent = `Intervall: ${evt.interval_label} | Fenster: ${new Date(evt.window_from).toLocaleString()} – ${new Date(evt.window_to).toLocaleString()} | ID: ${evt.id}`; }
                    else if (evt.type==='row') { processed=evt.processed; effected=evt.groups; total=evt.total; updateProgress(processed,effected,total); addRow(evt); }
                    else if (evt.type==='progress') { processed=evt.processed; effected=evt.groups; total=evt.total; updateProgress(processed,effected,total); }
                    else if (evt.type==='summary') { processed=evt.processed; effected=evt.groups; total=evt.total; updateProgress(processed,effected,total); }
                }, onDone: () => setBusy(false) });
            } catch(e) { alert(`Test fehlgeschlagen: ${e.message || e}`); setBusy(false); }
        }
        function addRow(evt) {
            const tbody = $('#rows');
            const tr = document.createElement('tr');
            const cells = [ evt.interval_start_readable, evt.count, evt.avg, evt.first_ts_readable, evt.last_ts_readable ];
            for (const c of cells) { const td = document.createElement('td'); td.textContent = String(c); tr.appendChild(td);} tbody.appendChild(tr);
        }
        async function doRun() {
            setBusy(true);
            try {
                const fd = new FormData();
                fd.set('action', 'run');
                fd.set('id', String(Number($('#paramId').value || DEFAULT_ID)));
                fd.set('intervalKey', getSelectedIntervalKey());
                const f = $('#paramFrom').value; const t = $('#paramTo').value; if (f) fd.set('from', f); if (t) fd.set('to', t);
                if (!confirm('ACHTUNG: Dies führt die Verdichtung aus (Insert/Deletes)!')) { setBusy(false); return; }
                let processed=0, effected=0, total=0;
                await streamAction(fd, { onEvent: (evt) => {
                    if (evt.type==='meta') { total = evt.total || 0; updateProgress(0,0,total); $('#metaText').textContent = `Run: ${evt.interval_label} | ID: ${evt.id}`; }
                    else if (evt.type==='progress') { processed=evt.processed; effected=evt.affected; total=evt.total; updateProgress(processed,effected,total); }
                    else if (evt.type==='summary') { processed=evt.processed; effected=evt.affected; total=evt.total_before; updateProgress(processed,effected,total); alert(`Fertig. Eingefügte/Übrige Gruppen: ${evt.inserted}/${evt.groups}. Vorher: ${evt.total_before}, Nachher: ${evt.total_after}`); }
                }, onDone: () => setBusy(false) });
            } catch(e) { alert(`Run fehlgeschlagen: ${e.message || e}`); setBusy(false); }
        }
        // Defaults
        window.addEventListener('DOMContentLoaded', function() {
            const fromDate = new Date(); fromDate.setDate(fromDate.getDate() - 7); fromDate.setHours(0,0,0,0);
            const toDate = new Date(); toDate.setHours(23,59,59,999);
            const fmt = (d)=>{ const y=d.getFullYear(); const m=String(d.getMonth()+1).padStart(2,'0'); const da=String(d.getDate()).padStart(2,'0'); const h=String(d.getHours()).padStart(2,'0'); const mi=String(d.getMinutes()).padStart(2,'0'); return `${y}-${m}-${da}T${h}:${mi}`; };
            const elFrom = $('#paramFrom'); if (elFrom) elFrom.value = fmt(fromDate);
            const elTo = $('#paramTo'); if (elTo) elTo.value = fmt(toDate);
            const elId = $('#paramId'); if (elId) elId.value = String(DEFAULT_ID);
        });
    </script>
    <meta name="color-scheme" content="dark light">
</head>
<body>
    <header>
        <h1>iobroker ts_number Kompressor</h1>
        <div class="muted small">Verdichtet Werte nach Zeitintervallen und schreibt Mittelwerte.</div>
    </header>
    <main>
        <section class="panel stack">
            <div>
                <h2>Parameter</h2>
                <div class="divider"></div>
            </div>
            <div class="panel">
                <div class="form-row"><label>Von</label><input type="datetime-local" id="paramFrom"></div>
                <div class="form-row"><label>Bis</label><input type="datetime-local" id="paramTo"></div>
                <div class="form-row"><label>ID</label><input type="number" id="paramId" value="<?php echo (int) DEFAULT_STATE_ID; ?>" min="1"></div>
                <div class="hint small">Diese Angaben gelten für Analyse, Testlauf und Echtlauf.</div>
            </div>
            <div style="margin-top:16px;">
                <h2>Aktionen</h2>
                <div class="divider"></div>
            </div>
            <div class="actions">
                <div class="panel">
                    <h2>1. TESTLAUF (Dry-Run)</h2>
                    <div class="form-row"><label>Intervall</label>
                        <div class="intervals">
                            <?php foreach (INTERVALS as $k=>$ms): ?>
                                <label><input type="radio" name="intervalKey" value="<?php echo htmlspecialchars($k, ENT_QUOTES); ?>" <?php echo $k==='1h'?'checked':''; ?>> <?php echo htmlspecialchars($k); ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div style="margin-top:10px;display:flex;gap:10px;">
                        <button class="btn btn-primary" id="btnTest" onclick="doTest()">TESTLAUF</button>
                    </div>
                </div>
                <div class="panel">
                    <h2>2. ECHTLAUF</h2>
                    <div class="form-row"><label>Intervall</label>
                        <div class="intervals">
                            <?php foreach (INTERVALS as $k=>$ms): ?>
                                <label><input type="radio" name="intervalKey" value="<?php echo htmlspecialchars($k, ENT_QUOTES); ?>" <?php echo $k==='1h'?'checked':''; ?>> <?php echo htmlspecialchars($k); ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div style="margin-top:10px;display:flex;gap:10px;">
                        <button class="btn btn-danger" id="btnRun" onclick="doRun()">ECHTLAUF (VERDICHTET)</button>
                    </div>
                </div>
                <div class="panel">
                    <h2>3. VORAB ANALYSE</h2>
                    <div style="margin-top:10px;display:flex;gap:10px;">
                        <button class="btn btn-success" id="btnAnalyze" onclick="doAnalyze()">Analyse starten</button>
                    </div>
                    <div class="grid-two" style="margin-top:10px;">
                        <div class="stat">Gesamt: <br><b id="anaTotal">-</b></div>
                        <div class="stat">Kleinstes Datum: <br><b id="anaMin">-</b></div>
                        <div class="stat">Größtes Datum: <br><b id="anaMax">-</b></div>
                    </div>
                </div>
            </div>
        </section>
        <section class="panel stack">
            <h2>Fortschritt</h2>
            <div class="progress" title="Fortschritt"><div id="progressBar" class="bar"></div></div>
            <div class="grid-two">
                <div class="stat">Bearbeitet<br><b id="processed">0</b></div>
                <div class="stat">Gruppen/Mittelwerte<br><b id="effected">0</b></div>
                <div class="stat">Gesamt<br><b id="total">0</b></div>
                <div class="stat">Status<br><b id="progressText">0%</b></div>
            </div>
            <div class="small muted" id="metaText"></div>
            <div class="divider"></div>
            <h2>Testlauf-Gruppen</h2>
            <div class="tbl-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Intervall-Start</th>
                            <th>Anzahl</th>
                            <th>Mittelwert</th>
                            <th>Erster TS</th>
                            <th>Letzter TS</th>
                        </tr>
                    </thead>
                    <tbody id="rows"></tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>

