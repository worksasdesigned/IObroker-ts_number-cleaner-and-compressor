### iobroker-compressor

Tool zur zeitlichen Verdichtung von `ts_number`-Messwerten in ioBroker-MySQL.

- Analyze: Zählt Datensätze im Zeitraum (optional gefiltert nach `id`).
- Test (Dry-Run): Zeigt gruppierte Intervalle und den jeweiligen Mittelwert, ohne DB zu ändern.
- Run: Aggregiert Werte je Intervall, schreibt Mittelwert mit Zeitstempel des ersten Datensatzes (+1ms) und löscht die übrigen Datensätze.

Intervalle (Radiobuttons): 1 Minute, 5, 15, 30, 1 Stunde, 3, 6, 12 Stunden, 1 Tag.

Struktur ist an `iobroker-cleaner` angelehnt (PDO, Streaming-Ausgaben, NDJSON für Test/Run).

### Konfiguration (config.php)

- **DB_HOST / DB_NAME / DB_USER / DB_PASS**: Verbindungsdaten. Per ENV überschreibbar: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.
  - Effekt: Ziel-Datenbank. Kein Einfluss auf Verdichtungslogik.

- **DEFAULT_STATE_ID**: Standard-`id` für die UI-Vorbelegung.
  - Effekt: Nur Voreinstellung; jede Aktion nutzt die übermittelte `id`.

- **PROGRESS_EVERY_ROWS** und **PROGRESS_EVERY_MS**: Häufigkeit der Fortschrittsereignisse im Stream.
  - Effekt: Kleinere Werte = häufigere UI-Updates; größere Werte = weniger Overhead.

- **TEST_LIMIT_ROWS**: Obergrenze der Zeilen im Testlauf.
  - Effekt: Limitiert die Menge, die im Dry-Run gruppiert wird.

- **RUN_BATCH_SIZE**: Geplante Batchgröße für Lösch-/Insert-Operationen (Performance-Tuning).
  - Effekt: Größere Batches = weniger Transaktionswechsel, potenziell schneller; kleinere Batches = geringere Locks. (Je nach Setup anpassen.)

- **INTERVALS**: Mapping von Schlüssel → Millisekunden, z. B. `1m → 60000`, `1h → 3600000`.
  - Effekt: Steuert verfügbare Radiobuttons. Der Parameter `intervalKey` muss einem Schlüssel in `INTERVALS` entsprechen.

### Request-Parameter und Formate

- **from / to**: Unterstützt `datetime-local` (z. B. `2025-02-01T00:00`), Sekundentimestamps (10-stellig) und Millisekunden (≥13-stellig).
- **id**: Geräte-/State-ID in `ts_number`.
- **intervalKey**: Einer der Schlüssel aus `INTERVALS` (z. B. `1h`).

### Beispiel-Datensatz und Ergebnis (Intervall: 30 Minuten)

Ausgangswerte für `id=102` in einem 1‑Stunden-Fenster:

| Zeit | Wert |
|---|---:|
| 10:05 | 10.0 |
| 10:15 | 12.0 |
| 10:40 | 14.0 |
| 10:50 | 16.0 |

Gruppierung bei `30m` ergibt zwei Gruppen:

| Intervall-Start | Count | Mittelwert | erster TS | letzter TS |
|---|---:|---:|---:|---:|
| 10:00 | 2 | 11.0 | 10:05 | 10:15 |
| 10:30 | 2 | 15.0 | 10:40 | 10:50 |

Run-Verhalten:
- Für jede Gruppe mit `Count > 1` werden die Originale im Bereich `[erster TS … letzter TS]` gelöscht.
- Ein neuer Datensatz mit `val = Mittelwert` wird an `erster TS + 1ms` eingefügt. Existiert dieser TS bereits, wird in 1‑ms‑Schritten bis zu 10× erhöht.
- Ergebnis: Reduzierte Anzahl Datensätze bei Erhalt des Mittelwertsignals pro Intervall.

### Hinweise

- Vor dem Echtlauf Backup anlegen.
- `INTERVALS` kann erweitert werden (neuen Schlüssel + Millisekundenwert hinzufügen). Die UI übernimmt die Einträge automatisch.

