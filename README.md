# IObroker-ts_number-cleaner-and-compressor
Kleines PHP Tool das millionen Werte nach Ausreißern durchsucht, und bereinigt. Zudem können Werte verdichtet werden.
Es ist auch möglich 0 (VORSICHT kann ja auch richtig sein) bzw NULL Werte einer ID zu löschen.


Beispiel:
4.4 Millionen werte in der ts_number mit der id=102 (Stromzähler Werte). Da diese über das optische Auge am Zähler alle 10 Sekunden kamen, und sehr fehleranfällig sind (ca. 0.1% der Werte haben ein Fehlreading) habe ich einen kleinen Korrektur Report iobroker-cleaner gebastelt. Der cleaner kann mit diversen Parametern eine steigende Datenreihe nach Ausreißern durchsuchen und diese bereinigen. 

Um mit den Daten vernüftiger Arbeiten zu können, kann man mit dem iobroker-compressor die 10-Sekunden Daten verdichten. zB auf 5 Minuten Zeitscheiben. Es wird der Mittelwert der Gruppe an den Zeitanfang der Gruppe als neuen Datensatz gespeichert - die anderen Werte werden gelöscht.

Es sind jeweils Test-Läufe vorgesehen. Es sollte sich von selbst verstehen, vor Benutzung der Tools ein Backup zu machen (und auch zu testen ob man es einspielen kann :-) )

Vorher:
<img width="1119" height="360" alt="image" src="https://github.com/user-attachments/assets/781341e7-ac3e-4b63-a974-5707b5b15f8a" />
tausende Datensätze, Ausreißer verzerren das Diagramm (siehe Skala)

Nachher:
5 Minuten Zeitscheiben und Ausreißer wurden eliminiert.
<img width="1112" height="360" alt="image" src="https://github.com/user-attachments/assets/08f0322d-1f25-41d0-b76a-a20d795db7d6" />

Am Knick kann man schön erkennen wann der Hausakku installiert wurde.



