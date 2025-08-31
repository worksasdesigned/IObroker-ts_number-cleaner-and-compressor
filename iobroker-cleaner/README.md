# iobroker ts_number Cleaner (PHP)

Web-Tool zum Erkennen und Entfernen fehlerhafter Ausreißer in `ts_number` der MySQL-Datenbank `iobroker`.

Funktionen:
- Testlauf (max. 10.000 Zeilen) per Datumsbereich, Live-Status via NDJSON
- Echtlauf mit optionalem Zeitfenster (löscht fehlerhafte Datensätze)
- Vorab-Analyse (Anzahl, kleinstes und größtes Datum)

Voraussetzungen:
- PHP 8.0+ (CLI und/oder Webserver)
- MySQL mit Zugriff auf `iobroker`

Installation:
1. Ordner auf Webserver bereitstellen (z. B. `/var/www/html/iobroker-cleaner`)
2. `config.php` mit den Zugangsdaten anpassen (oder Env-Variablen `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` setzen)
3. Browser öffnen: `http(s)://<server>/iobroker-cleaner/index.php`

### Konfiguration (config.php)

- **DB_HOST / DB_NAME / DB_USER / DB_PASS**: Verbindungsdaten zur MySQL-Datenbank. Können per Umgebungsvariablen überschrieben werden (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`).
  - Effekt: Bestimmt, zu welcher Datenbank verbunden wird. Kein Einfluss auf die Erkennungslogik.

- **DEFAULT_STATE_ID**: Standard-`id`, die im UI vorbelegt wird (z. B. `102`).
  - Effekt: Nur UI-Voreinstellung. Jede Aktion verwendet die übermittelte `id`.

- **DROP_THRESHOLD**: Maximal erlaubter negativer Sprung gegenüber dem letzten „guten“ Wert (in Einheiten von `val`). Beispiel: `50.0` bedeutet, dass ein Abfall kleiner als `-50` als Ausreißer markiert wird.
  - Effekt: Kleinerer Wert => sensibler für Abfälle (mehr Treffer). Größerer Wert => toleranter (weniger Treffer).

- **SPIKE_THRESHOLD**: Maximal erlaubter positiver Sprung gegenüber dem letzten „guten“ Wert. Beispiel: `1000.0` markiert Anstiege > `+1000` als Ausreißer.
  - Effekt: Kleinerer Wert => sensibler für Spitzen. Größerer Wert => toleranter.

- **MOVING_WINDOW_SIZE_GOOD**: Anzahl zuletzt akzeptierter „guter“ Punkte für die Trend-/Ratenabschätzung.
  - Effekt: Größerer Wert glättet stärker (robuster gegen Rauschen), passt sich aber langsamer an neue Niveaus an.

- **SMALL_DELTA_CLUSTER_COUNT** und **SMALL_DELTA_ABS_MAX**: Heuristik zur Stabilisierung nach Sprüngen. Wenn es `SMALL_DELTA_CLUSTER_COUNT` aufeinanderfolgende kleine Roh-Deltas gibt (Betrag ≤ `SMALL_DELTA_ABS_MAX`), wird ein neues Niveau als stabil akzeptiert.
  - Effekt: Höherer Count = längere Wartezeit bis zur Stabilisierung. Höherer `ABS_MAX` = einfacher, Stabilität zu erreichen.

- **RATE_TOLERANCE_MULTIPLIER** und **RATE_TOLERANCE_ABS**: Toleranz für erwartbare Werte auf Basis der mittleren Änderungsrate. Toleranz wird berechnet als: `multiplier * avgAbsRate * dt + abs`.
  - Effekt: Größerer Multiplikator/Offset = toleranter gegenüber Abweichungen vom Trend.

- **DELETE_COMMIT_EVERY**: Bei Echtläufen nach so vielen Löschungen wird ein Commit ausgeführt (Transaktion wird geschlossen/neu begonnen).
  - Effekt: Kleinere Zahl = häufiger committen (weniger Locks, mehr Overhead). Größere Zahl = seltener committen (schneller, aber längere Locks möglich).

- **PROGRESS_EVERY_ROWS** und **PROGRESS_EVERY_MS**: Häufigkeit der Fortschritts-Events im Stream.
  - Effekt: Kleinere Werte = häufigere UI-Updates (mehr Netzwerk-Overhead). Größere Werte = selteneres Update.

- **TEST_LIMIT_ROWS**: Obergrenze der Zeilen im Testlauf.
  - Effekt: Limitiert die Menge der im Testlauf ausgewerteten Datensätze.

### Request-Parameter und Formate

- **from / to**: Unterstützt `datetime-local` (z. B. `2025-02-01T00:00`), Sekundentimestamps (10-stellig) und Millisekunden (≥13-stellig).
- **id**: Geräte-/State-ID in `ts_number`.

### API (POST)

- Analyze: `action=analyze&id=102`
- Test (stream): `action=test&from=2025-02-01T00:00&to=2025-02-03T00:00&id=102`
- Run (stream): `action=run&id=102&from=...&to=...` (Zeitfenster optional)

### Beispiel-Datensatz (Schwellen: DROP_THRESHOLD=50, SPIKE_THRESHOLD=1000)

| Zeit (ts) | Wert (val) | Delta zu „gut“ | Anomalie? |
|---|---:|---:|---:|
| 10:00 | 1000 | - | Nein |
| 10:10 | 1002 | +2 | Nein |
| 10:20 | 1005 | +3 | Nein |
| 10:30 | 2100 | +1095 | Ja (Spitze > 1000) |
| 10:40 | 1006 | +? | Nein (Stabilisierung durch kleine Deltas) |
| 10:50 | 1007 | +1 | Nein |
| 11:00 | 950 | -57 | Ja (Abfall < -50) |
| 11:10 | 1008 | +? | Nein |

Erläuterung: Der Sprung auf 2100 überschreitet `SPIKE_THRESHOLD` und wird verworfen. Danach erkennt die Heuristik durch mehrere kleine Deltas (`SMALL_DELTA_*`), dass sich das Niveau wieder stabilisiert. Der Abfall auf 950 unterschreitet `DROP_THRESHOLD` und wird als Ausreißer verworfen.

### Sicherheit

- Vor dem Echtlauf bitte ein vollständiges Backup erstellen!

