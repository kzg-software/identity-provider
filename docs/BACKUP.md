# Datensicherung und Wiederherstellung

Das System kann sich selbst komplett in eine einzige Datei sichern und aus so
einer Datei wieder aufbauen. Danach ist alles wieder da: Datenbank,
Einstellungen, Benutzer, Verzeichnisse, OAuth und SAML, Logo und Hintergrundbild.

## Was in einer Sicherung steckt

* Die komplette Datenbank
* Die Konfigurationsdatei `.env` samt Anwendungsschlüssel. Der Schlüssel wird
  gebraucht, damit verschlüsselte Werte wie AD Bind Passwörter oder
  Client Secrets nach der Wiederherstellung wieder lesbar sind.
* Alle hochgeladenen Dateien: Logo, Favicon, Login Hintergrund und was sonst
  unter `storage/app` liegt.

Die fertige Datei heißt zum Beispiel `meine-firma-sicherung-2026-09-02-141530.authbak`
und ist mit einem Passwort verschlüsselt. Ohne dieses Passwort lässt sie sich
nicht öffnen.

## Sicherung erstellen

1. In der Administration auf **Datensicherung** gehen.
2. Auf **Sicherung erstellen** klicken.
3. Im Fenster ein **Passwort für die Sicherungsdatei** vergeben. Merken oder an
   einem sicheren Ort ablegen. Es kann später nicht wiederhergestellt werden.
4. Zur Bestätigung das **eigene Kontopasswort** eingeben.
5. Die Datei wird heruntergeladen. Sicher aufbewahren, am besten getrennt vom
   Passwort.

Tipp: Regelmäßig sichern, zum Beispiel vor jedem Update.

## Wiederherstellen aus der Administration

1. In der Administration auf **Datensicherung** gehen.
2. Auf **Wiederherstellen** klicken.
3. Die `.authbak` Datei auswählen.
4. Das **Passwort der Sicherung** eingeben.
5. Das **eigene Kontopasswort** zur Bestätigung eingeben.
6. Bestätigen, dass die aktuellen Daten überschrieben werden dürfen.
7. Nach kurzer Zeit sind Sie abgemeldet. Melden Sie sich mit den Zugangsdaten
   aus der Sicherung neu an.

Vor dem Überschreiben legt das System den bisherigen Stand automatisch als
Rücksicherung unter `storage/framework/backups/` ab. Geht beim Einspielen etwas
schief, wird dieser Stand wieder hergestellt.

## Wiederherstellen auf einem frischen Server

Nach einem Serverwechsel oder Totalausfall:

1. Das System ganz normal neu aufsetzen, bis der Einrichtungs Assistent im
   Browser erscheint (`/install`).
2. Auf der Startseite **Aus Sicherung wiederherstellen** wählen.
3. Die `.authbak` Datei hochladen und das Passwort eingeben.
4. Fertig. Das System ist im selben Zustand wie zum Zeitpunkt der Sicherung.

Wichtig: Auf dem neuen Server muss derselbe Datenbanktyp laufen wie beim
Erstellen der Sicherung. Eine Sicherung aus SQLite lässt sich nicht in eine
MySQL Datenbank einspielen und umgekehrt.

## Grenzen und Hinweise

* **Dateigröße beim Upload.** Große Sicherungen können am Upload Limit des
  Webservers scheitern. Nötig sind hohe Werte für `upload_max_filesize` und
  `post_max_size` in der PHP Konfiguration und ein passendes
  `client_max_body_size` im nginx davor. Die mitgelieferten Setups (Docker und
  `deploy/install.sh`) sind bereits auf 512 MB eingestellt.
* **Gleicher Datenbanktyp.** Siehe oben.
* **Passwort.** Ohne das Passwort der Sicherungsdatei ist der Inhalt verloren.
  Es gibt keine Hintertür.
* **Kopie zum Testen.** Wird eine Sicherung aus dem Produktivsystem in eine
  Testinstanz eingespielt, übernimmt die Testinstanz auch die Adresse
  (`APP_URL`) und den Systemnamen aus der Sicherung. Wie man das danach umstellt,
  steht in [`INSTANZEN.md`](INSTANZEN.md).
