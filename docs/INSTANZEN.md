# Umbenennen und mehrere Instanzen

## 1. Anzeigenamen ändern

Der Name, der in der Oberfläche und im Browser Tab steht, ist eine
Einstellung in der Datenbank. So ändern Sie ihn:

1. Als Administrator anmelden.
2. **Administration, Systemeinstellungen** öffnen.
3. Feld **Systemname** ändern und speichern.

Mehr ist nicht nötig. Logo, Favicon und Login Hintergrund ändern Sie auf
derselben Seite.

## 2. Adresse oder Domain ändern

Wenn das System unter einer neuen Adresse erreichbar sein soll, zum Beispiel
von `https://auth.alt.de` auf `https://auth.neu.de`:

1. In der `.env` anpassen:

   ```dotenv
   APP_URL=https://auth.neu.de
   ```

2. Den Reverse Proxy auf die neue Domain umstellen und ein passendes
   Zertifikat hinterlegen.
3. In **Administration, Systemeinstellungen** das Feld **Basis URL** auf die
   neue Adresse setzen.
4. Neu starten:

   * Docker: `docker compose restart app scheduler queue`
   * Standalone: `php artisan config:clear` und den PHP FPM Dienst neu laden

Wichtig: OAuth Clients und SAML Service Provider kennen die alten URLs. Diese
Gegenstellen müssen ebenfalls auf die neue Adresse umgestellt werden
(Redirect URIs, Metadata URL, ACS URL).

## 3. Adresse nach einer Wiederherstellung anpassen

Eine Sicherung enthält immer die `APP_URL` und den Systemnamen des Systems,
aus dem sie stammt. Wenn Sie eine Sicherung aus dem Produktivsystem in eine
andere Instanz einspielen, übernimmt diese Instanz zunächst die alten Werte.
Danach wie in Abschnitt 2 auf die richtige Adresse und den richtigen Namen
umstellen.

## 4. Mehrere Instanzen mit Docker (empfohlen)

Jede Instanz bekommt einen eigenen Ordner, eine eigene `.env`, einen eigenen
Projektnamen, einen eigenen Port und eine eigene Domain. Die Datenbanken und
hochgeladenen Dateien sind dadurch komplett getrennt.

Beispiel: eine Produktivinstanz und eine Testinstanz auf demselben Server.

```bash
mkdir -p ~/auth-prod ~/auth-test
```

Datei `~/auth-prod/docker-compose.yml` und `~/auth-test/docker-compose.yml`:
jeweils die `docker-compose.yml` aus diesem Projekt (siehe
[`DEPLOYMENT.md`](DEPLOYMENT.md), Abschnitt Docker).

Datei `~/auth-prod/.env`:

```dotenv
COMPOSE_PROJECT_NAME=auth-prod
APP_IMAGE=ghcr.io/kzg-software/identity-provider:latest
APP_PORT=8080
APP_URL=https://auth.firma.de
APP_NAME="Anmeldung Firma"
TRUSTED_PROXIES=*

DB_CONNECTION=mysql
DB_HOST=db
DB_DATABASE=auth
DB_USERNAME=auth
DB_PASSWORD=langes-passwort-prod
DB_ROOT_PASSWORD=anderes-langes-passwort-prod
AUTO_MIGRATE=true
SESSION_SECURE_COOKIE=true
```

Datei `~/auth-test/.env`: dieselbe Vorlage, aber

```dotenv
COMPOSE_PROJECT_NAME=auth-test
APP_PORT=8081
APP_URL=https://auth-test.firma.de
APP_NAME="Anmeldung Firma (Test)"
DB_PASSWORD=langes-passwort-test
DB_ROOT_PASSWORD=anderes-langes-passwort-test
```

Starten:

```bash
cd ~/auth-prod && docker compose up -d
cd ~/auth-test && docker compose up -d
```

Durch `COMPOSE_PROJECT_NAME` heißen die Container und Volumes der einen Instanz
`auth-prod_*` und der anderen `auth-test_*`. Sie stören sich nicht.

Im Reverse Proxy zwei Einträge anlegen: `auth.firma.de` zeigt auf Port 8080,
`auth-test.firma.de` auf Port 8081.

### Testinstanz mit echten Daten befüllen

1. In der Produktivinstanz eine Sicherung erstellen (siehe
   [`BACKUP.md`](BACKUP.md)).
2. Die Testinstanz frisch starten und im Browser den Einrichtungs Assistenten
   öffnen.
3. **Aus Sicherung wiederherstellen** wählen und die Datei einspielen.
4. Danach in der Testinstanz `APP_URL`, `APP_NAME` und die **Basis URL** in den
   Systemeinstellungen auf die Testwerte umstellen (Abschnitt 3).

So testen Sie Updates oder Konfigurationsänderungen mit echten Daten, ohne die
Produktivinstanz anzufassen.

## 5. Mehrere Instanzen ohne Docker

Dasselbe Prinzip, nur von Hand:

* Ein eigenes Verzeichnis pro Instanz, zum Beispiel `/var/www/auth-prod` und
  `/var/www/auth-test`.
* Eine eigene Datenbank pro Instanz. Niemals dieselbe Datenbank für zwei
  Instanzen verwenden.
* Eine eigene `.env` pro Instanz mit eigenem `APP_KEY` (`php artisan key:generate`),
  eigener `APP_URL` und eigenen Datenbank Zugangsdaten.
* Einen eigenen nginx vhost und einen eigenen PHP FPM Pool pro Instanz.
* Eigene systemd Units für Scheduler und Queue pro Instanz (unterschiedliche
  Namen, unterschiedliches `WorkingDirectory`).

Die Schritte im Einzelnen stehen in [`DEPLOYMENT.md`](DEPLOYMENT.md),
Abschnitt A. Sie werden pro Instanz einmal durchlaufen.

## 6. Was Instanzen niemals teilen dürfen

* Dieselbe Datenbank
* Dieselbe `.env` oder denselben `APP_KEY`
* Dasselbe Storage Verzeichnis oder Volume

Geteilt werden dürfen: der Server, der Reverse Proxy und der Datenbankserver
(solange jede Instanz ihre eigene Datenbank darin hat).
