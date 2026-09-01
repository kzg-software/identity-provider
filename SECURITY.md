# Sicherheitsrichtlinie

## Unterstützte Versionen

Sicherheitsupdates gibt es nur für die jeweils **neueste veröffentlichte
Version**. Es gibt keine langfristig gepflegten älteren Zweige. Bitte vor einer
Meldung auf die aktuelle Version aktualisieren (Version steht im Footer und
unter *Administration → Aktualisierungen*).

| Version | Unterstützt |
|---|---|
| neuestes `vX.Y.Z` | ja |
| ältere Versionen | nein |

## Eine Schwachstelle melden

**Bitte keine öffentlichen Issues, Pull Requests oder Diskussionen für
Sicherheitsprobleme.**

Melde sie über die private Meldefunktion von GitHub:
[Security Advisories → „Report a vulnerability"](https://github.com/kzg-software/identity-provider/security/advisories/new).

Ist das nicht möglich, per E-Mail an **kzg-software@proton.me** mit dem Betreff
`SECURITY: identity-provider`.

Hilfreich in der Meldung:

* betroffene Version und Betriebsart (Docker / Skript / manuell)
* Beschreibung der Schwachstelle und der möglichen Auswirkung
* Schritte oder ein minimaler Proof of Concept zum Nachvollziehen
* betroffener Bereich (OAuth/OIDC, SAML, LDAP/AD-Anbindung, Windows-SSO,
  Installer, Session-/Cookie-Handling, Datei-Uploads, …)

Bitte keine echten Zugangsdaten, Tokens oder personenbezogenen Daten Dritter
mitschicken.

## Ablauf

* Empfangsbestätigung innerhalb von **72 Stunden**.
* Erste Einschätzung (Schweregrad, betroffen ja/nein) innerhalb von **7 Tagen**.
* Fix und Release so schnell wie möglich, abhängig von Schweregrad und Umfang.
* Nach Veröffentlichung des Fixes wird ein Advisory publiziert. Wenn gewünscht,
  wird die meldende Person darin genannt.
* Wir bitten um **Coordinated Disclosure**: keine Veröffentlichung von Details,
  bis ein Fix verfügbar ist. Ein Bug-Bounty-Programm gibt es nicht.

## Geltungsbereich

Im Geltungsbereich:

* der Code in diesem Repository (App, Docker-Image, `deploy/`-Skripte,
  CI-Workflows)
* Authentifizierungs- und Autorisierungslogik, Token- und Assertion-Ausstellung
  und -Prüfung, Session-Handling, LDAP-/AD-Anbindung

Nicht im Geltungsbereich:

* Fehlkonfiguration einer eigenen Installation (fehlendes HTTPS, offener
  LDAP-Port, zu weit gefasste `TRUSTED_PROXIES`, schwache Bind-Konten)
* Schwachstellen in Abhängigkeiten ohne konkrete Auswirkung hier – bitte
  stattdessen beim jeweiligen Projekt melden
* fehlende Security-Header oder Best-Practice-Hinweise ohne belegbare
  Auswirkung
* Social Engineering, physischer Zugriff, DoS durch schiere Last

## Bekannte Einschränkungen

Einige Punkte sind bewusst offen und in der `README.md` unter „Was noch fehlt"
dokumentiert, unter anderem:

* die Signaturprüfung für das SAML-Redirect-Binding fehlt (POST-Binding wird
  geprüft und ist der empfohlene Weg)
* der In-App-Endpunkt `/auth/negotiate` prüft den gemeldeten Windows-Namen
  **nicht** kryptografisch gegen den Domain Controller (nur für abgeschottete
  interne Netze gedacht; siehe `NegotiateController`)
* SAML-Assertion-Verschlüsselung ist vorbereitet, aber nicht fertig

Meldungen zu diesen bekannten Punkten sind willkommen, gelten aber nicht als
neue Schwachstelle.

## Betreiber-Hinweise

* Immer hinter einem Reverse Proxy mit gültigem TLS-Zertifikat betreiben.
* `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, aktuelle Version einsetzen.
* Bind-Konten mit minimalen Rechten (nur Lesezugriff), keine Domänen-Admins.
* `.env`, `git-credentials` und Schlüssel außerhalb des öffentlich
  ausgelieferten Verzeichnisses halten.
