# Datenbankbereinigung - Stromzähler

Ein PHP-Tool zur Bereinigung fehlerhafter Sensordaten in der MySQL-Datenbank.

## 🚀 Schnellstart

1. **Konfiguration anpassen:**
   - Öffne `config.php` 
   - Trage deine MySQL-Verbindungsdaten ein

2. **Dateien auf Webserver kopieren:**
   - `data_cleaner.html`
   - `data_cleaner.php` 
   - `config.php`

3. **Im Browser öffnen:**
   - Navigiere zu `data_cleaner.html`

## 🛠️ Funktionen

### 📊 Vorab-Analyse
- Zeigt Gesamtanzahl der Datensätze
- Zeigt Zeitraum der Daten (ältester/neuester Eintrag)
- Schätzt Anzahl der fehlerhaften Datensätze

### 🧪 Testlauf  
- Analysiert Datensätze in einem gewählten Zeitraum (max. 10.000)
- Zeigt fehlerhafte Datensätze rot hervorgehoben
- **Löscht KEINE Daten** - nur Simulation

### ⚠️ Echtlauf
- **ACHTUNG:** Löscht fehlerhafte Datensätze permanent!
- Zeigt Fortschrittsbalken mit Live-Updates
- Verarbeitet alle Datensätze in 1000er-Batches
- Gibt detaillierte Statistiken aus

## 🔍 Fehlererkennung

Das Tool erkennt zwei Arten von Fehlern:

1. **Wert fällt zu stark:** Wert sinkt um 50-5000 Punkte
   - Beispiel: 13000 → 12540 (Differenz: -460)

2. **Wert steigt zu stark:** Wert steigt um 1000-5000 Punkte  
   - Beispiel: 13000 → 14540 (Differenz: +1540)

## ⚙️ Konfiguration

In `config.php` können folgende Parameter angepasst werden:

- **Datenbankverbindung:** Host, Benutzername, Passwort
- **Fehlererkennung:** Schwellenwerte für zu starke Änderungen
- **Performance:** Batch-Größe, Fortschrittsintervalle

## 🔐 Sicherheit

- **Erstelle IMMER ein Backup** vor dem Echtlauf!
- Der Echtlauf erfordert eine Bestätigung der Sensor-ID (102)
- Alle Operationen werden geloggt

## 📈 Beispiel-Statistiken

Nach dem Lauf erhältst du:
- Anzahl verarbeiteter Datensätze
- Anzahl gefundener Fehler  
- Anzahl gelöschter Datensätze
- Verbleibende Datensätze in der Datenbank

## 🐛 Problembehandlung

1. **Datenbankverbindung fehlgeschlagen:**
   - Prüfe Verbindungsdaten in `config.php`
   - Stelle sicher, dass MySQL läuft

2. **Keine Datensätze gefunden:**
   - Prüfe die Sensor-ID (Standard: 102)
   - Prüfe den gewählten Zeitraum

3. **Timeout-Fehler:**
   - Reduziere `batch_size` in `config.php`
   - Erhöhe PHP-Zeitlimits in der Server-Konfiguration