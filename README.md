# Datenbankbereinigung - (z.B. Stromzähler readings)

Ein PHP-Tool zur Bereinigung fehlerhafter Sensordaten in der MySQL-Datenbank. Speziell die tabelle ts_number von IOBROKER.
VORSICHT: das Tool löscht hart auf der Datenbank die fehlerhaften Werte (Testlauf und Vorabanalyse jeweils vorhanden). Mach ein Backup!
<img width="1119" height="360" alt="1" src="https://github.com/user-attachments/assets/92673256-1cf7-4b3c-a386-a5546e2146ec" />
Daten vor der Bereinigung: Fehlerhafte Werte verzerren das Diagramm

<img width="1112" height="360" alt="2" src="https://github.com/user-attachments/assets/9676e425-27e6-43b6-a416-4e22271ffc2d" />
Daten nach der Bereinigung der Ausreißer sowie Verdichtung auf 5 Minuten Intervalle.


## 🚀 Schnellstart

1. **Konfiguration anpassen:** (in jedem Tool!)
   - Öffne `config.php` 
   - Trage deine MySQL-Verbindungsdaten ein

2. **Dateien auf Webserver kopieren:**

3. **Im Browser öffnen:**
   - Navigiere zu `index.html`

## 🛠️ Funktionen

### 📊 Vorab-Analyse
- Zeigt Gesamtanzahl der Datensätze
- Zeigt Zeitraum der Daten (ältester/neuester Eintrag)
- Schätzt Anzahl der fehlerhaften Datensätze

### 🧪 Testlauf  
- Analysiert Datensätze in einem gewählten Zeitraum (max. 10.000 bzw Wert aus config.php)
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
- **Fehlererkennung:** Schwellenwerte für zu starke Änderungen (bei meinem Strom zähler habe ich +-10 eingetragen)
- **Performance:** Batch-Größe, Fortschrittsintervalle usw.

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
