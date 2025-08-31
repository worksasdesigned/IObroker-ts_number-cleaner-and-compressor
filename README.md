# IObroker-ts_number-cleaner-and-compressor
Kleines PHP Tool das millionen Werte nach Ausreißern durchsucht, und bereinigt. Zudem können Werte verdichtet werden.

Beispiel:
4.4 Millionen werte in der ts_number mit der id=102 (Stromzähler Werte). Da diese über das optische Auge am Zähler alle 10 Sekunden kamen, und sehr Fehleranfällig sind (ca. 0.1% der Werte haben ein Fehlreading) habe ich einen kleinen Korrektur Report iobroker-cleaner gebastelt. Der cleaner kann mit diversen paremetern eine steigende Datenreihe nach Ausreißern durchsuchen und diese bereinigen. 

Um mit den Daten vernüftiger Arbeiten zu können, kann man mit dem iobroker-compressor die 10-Sekunden Daten verdichten. zB auf 5 Minuten Zeitscheiben. Es wird der Mittelwert der Gruppe an den Zeitanfang der Gruppe als neuen Datensatz gespeichert - die anderen Werte werden gelöscht.

Es sind jeweils Test-Läufe vorgesehen. Es sollte sich von selbst verstehen, vor Benutzung der Tools ein Backup zu machen (und auch zu testen ob man es einspielen kann :-) )


