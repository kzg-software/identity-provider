# Mitwirken

Danke, dass du zum Identity Provider beitragen möchtest. Dieses Dokument
beschreibt den Ablauf für Fehlermeldungen, Vorschläge und Pull Requests.

## Fehler und Vorschläge

Bitte zuerst prüfen, ob es dazu schon ein Issue gibt. Sonst über die
[Issue-Vorlagen](https://github.com/kzg-software/identity-provider/issues/new/choose)
ein neues anlegen (Bug Report, Feature Request oder Frage).

**Sicherheitslücken nicht als öffentliches Issue melden.** Stattdessen über
[Security Advisories](https://github.com/kzg-software/identity-provider/security/advisories/new).

## Entwicklungsumgebung

Voraussetzungen: PHP 8.3 oder neuer (getestet mit 8.4), Composer 2, die
Extensions aus der `README.md`.

```bash
git clone https://github.com/kzg-software/identity-provider.git
cd identity-provider
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Kein Node, kein Build-Schritt. Das Frontend läuft über Blade mit Tailwind und
Alpine.js über CDN.

## Tests

Vor jedem Pull Request:

```bash
php artisan test
```

Neue Funktionen und Fehlerbehebungen brauchen einen Test. Tests liegen unter
`tests/Feature/`. Für LDAP/AD gibt es den `DirectoryEmulator`
(siehe `tests/Feature/DirectoryPasswordLoginTest.php` als Vorlage).

Auf Windows können einige OIDC/SAML-Tests wegen einer fehlenden lokalen
OpenSSL-Konfiguration fehlschlagen (`openssl_pkey_new`). In der CI laufen sie
grün. Deine Änderung darf keine zusätzlichen Tests rot machen.

## Code-Stil

```bash
vendor/bin/pint
```

Laravel Pint (PSR-12) ist verbindlich; die CI prüft nichts weiter, aber ein
sauberer Diff hilft. Weitere Punkte:

* Deutschsprachige UI-Texte, Kommentare und Commit-Nachrichten.
* Kein `dd()`, `dump()`, `var_dump()` oder auskommentierter Code im PR.
* Migrationen additiv halten und auf **MySQL/MariaDB und SQLite** lauffähig
  (Fremdschlüssel vor abhängigen Indizes lösen, siehe
  `2024_01_01_000101_flexible_group_role_mappings.php`).
* Geheimnisse (Bind-Passwörter, Tokens, Keys) nur verschlüsselt speichern und
  nie ins Log schreiben.
* Neue Einstellungen dokumentieren: `.env.example`, `.env.docker.example` und
  `docs/DEPLOYMENT.md`.

## Branch- und PR-Ablauf

1. Branch von `main` erstellen: `fix/kurzbeschreibung` oder `feat/kurzbeschreibung`.
2. Kleine, thematisch zusammenhängende Commits.
3. Commit-Betreff im Imperativ, eine Zeile, ohne Punkt am Ende, z. B.
   `LDAP: DN-Felder werden beim Speichern bereinigt`. Details im Commit-Body.
4. Pull Request gegen `main` mit Bezug zum Issue (`Fixes #123`) und kurzer
   Beschreibung, was und warum. Screenshots bei UI-Änderungen.
5. `php artisan test` muss lokal grün sein.

## Releases

Releases macht die Maintainerin über einen Git-Tag `vX.Y.Z`. Der Workflow
`docker-image.yml` baut daraus `ghcr.io/kzg-software/identity-provider:X.Y.Z`,
`:X.Y` und `:latest`. Jeder Commit auf `main` baut zusätzlich `:dev`.

## Lizenz

Mit einem Beitrag stimmst du zu, dass er unter der Lizenz dieses Projekts steht
(siehe [`LICENSE`](LICENSE), Attribution & Link-Back License).
