# Sicherheitskorrekturen – 2.5.1

Ausgangspunkt: beigefügter Quellcode `oidc-factor_general_update.zip`, Version `2.5.1`.
Dieses Archiv enthält ausschließlich neue und geänderte Dateien; es ist kein vollständiges, installierbares Nextcloud-App-Paket.

## Zuordnung der Änderungen

| Auditpunkt | Implementierte Korrektur |
| --- | --- |
| 1 | Nicht mehr gespeicherte Access-JWTs mit `typ=at+jwt` werden im Validierungslistener abgelehnt und gelangen nicht in die ID-Token-Validierung. ID-Tokens benötigen passende Signaturalgorithmen, Issuer, Audience, Zeitangaben und `sub`. |
| 2 | Explizite Identitäts-Claims werden vor der Client-/Gruppenprüfung und Zustimmung in die entsprechenden Berechtigungs-Scopes übersetzt. Nicht gewährte Claims werden vor Speicherung und Ausgabe entfernt. Historische Grants unterliegen bei ID-Token-/UserInfo-Ausgabe ebenfalls dieser Prüfung. `phone` und `address` sind eigenständige Scopes; `profile` gibt diese Daten nicht mehr frei. |
| 3 | Widerruf oder Scope-Entzug sperrt bereits genehmigte Device-Anfragen. Die Token-Ausgabe liest unter derselben Datenbanksperre die aktuelle Freigabe und Zustimmung erneut und berücksichtigt nur weiterhin gewährte Scopes. Zustimmung, Code-Verbrauch und Token-Speicherung werden entsprechend serialisiert. |
| 4 | Ein verlangter `sub` muss zum angemeldeten Benutzer passen. Ein essentieller `acr` muss vom unterstützten Kontext `0` erfüllt werden; andere Stufen werden nicht behauptet. Nicht erfüllbare Anforderungen führen zu einem Autorisierungsfehler. |
| 5 | `resource` muss eine vollständige URI ohne Fragment sein und einer administrativ genehmigten Zieladresse exakt entsprechen. DCR-Metadaten erteilen keine Berechtigung für eine Audience. Überlange URIs werden abgelehnt und nicht abgeschnitten. Gespeicherte normale Grants werden bei Code-/Refresh-Einlösung erneut geprüft. |
| 6 | Jede Consent-Seite verwendet eine zufällige, benutzergebundene Anfrage-ID mit vollständigem Snapshot. Bestätigung und Ablehnung betreffen nur diese Anfrage; Wiederverwendung und veraltete Formulare werden abgelehnt. Laufzeit: 10 Minuten, maximal 10 offene Anfragen pro Sitzung. |
| 8 | DCR prüft Redirects anhand von Anwendungstyp, Authentisierung und Grant-Typen bei Registrierung und Änderung, einschließlich beibehaltener Redirects. Öffentliche Web-Clients benötigen HTTPS; Implicit-Web-Redirects zusätzlich einen Nicht-Loopback-Host. Native Redirects verwenden HTTP-Loopback oder private Domain-Schemes. |
| 10 | Verbrauchte Refresh-Token-Hashes bleiben für die gesamte Lebensdauer ihrer Familie erhalten. Die bisherige Bereinigung nach sieben Tagen entfällt; beim Löschen der Familie werden die Hashes weiterhin entfernt. |
| 12 | Autorisierungsendpunkte erhalten anonyme und benutzerbezogene Rate Limits. Login-/POST-Transaktionen haben ein globales Datenbankbudget von 2.000 Einträgen und maximal 32 KiB Nutzdaten; Prüfung und Einfügung sind serialisiert. Abgelaufene Einträge werden auch beim Anlegen bereinigt. Erschöpfung führt zu HTTP 429 mit `Retry-After: 60`. |
| 13 | Bei `refresh_expire_time=never` werden abgelaufene Grants ohne unbenutztes Refresh-Token, noch gültigen Autorisierungscode oder Legacy-Refresh-Berechtigung entfernt. Tatsächlich erneuerbare Grants bleiben erhalten. |
| 14 | DCR verwendet `COUNT(*)` statt `rowCount()` für SELECT-Ergebnisse. Ab 100 dynamischen Clients wird eine Neuregistrierung abgelehnt; die maßgebliche Zählung und Einfügung liegen in derselben gesperrten Transaktion. |

Die vier kleineren Fehler sind ebenfalls korrigiert:

1. Die ID-Token-Validierung verwendet `sub` statt `preferred_username`; das optionale Profilfeld ist nicht mehr für die Identifikation nötig. HS256-ID-Tokens werden mit dem zugehörigen Client-Secret geprüft. Weil der Client dieses Secret selbst kennt, akzeptiert der Listener zusätzlich nur solche HS256-Tokens, deren Hash der Provider beim Ausstellen gespeichert hat. Dadurch können Clients keine eigenen ID-Tokens für andere Benutzer herstellen und beim Provider validieren lassen.
2. Der erste Wochentag wird aus Zeichenketten in einen Integer von 0 bis 6 überführt; ungültige Werte fallen auf die Locale bzw. Montag zurück.
3. `updated_at` wird nicht mehr fälschlich aus dem letzten Login berechnet. Mangels zuverlässigem Profiländerungsdatum entfällt der automatisch erzeugte Claim einschließlich seiner Discovery-Ankündigung.
4. Debug-Logs enthalten keine Werte der benutzerdefinierten Claims mehr.

## Anwendung auf den Quellcode

1. Den Inhalt des Archivordners `oidc/` in die vorhandene App-Quellcodebasis übernehmen. Die übrigen Dateien der ursprünglichen App werden weiterhin benötigt.
2. Mit der PHP-/Node-Umgebung des Projekts Abhängigkeiten installieren und den Frontend-Build ausführen:

   ```sh
   composer install --no-dev --prefer-dist --optimize-autoloader
   npm ci
   npm run build
   ```

   Für Backend-Tests stattdessen zunächst `composer install` einschließlich Entwicklungsabhängigkeiten verwenden. Die Composer-Autoload-Dateien müssen neue Klassen berücksichtigen; bei vorhandenen Abhängigkeiten genügt dafür `composer dump-autoload --optimize`.

3. App-Dateien einschließlich des neu gebauten JavaScripts übernehmen und das normale Nextcloud-Upgrade ausführen (`php occ upgrade` im Nextcloud-Verzeichnis). Die neue Migration `0039Date20261005120000` erstellt Datenbanksperren und die Hash-Tabelle für ausgestellte HS256-ID-Tokens. Die Sperren sind Voraussetzung für Registrierungen, Login-Handoffs und Device-/Consent-Änderungen.

## Änderungen an bestehenden Clients

- Bereits geöffnete Consent-Formulare ohne neue Anfrage-ID müssen neu gestartet werden. Alte einzelne `oidc_consent_*`-Sitzungswerte werden nicht mehr als Autorisierungsbeleg verwendet.
- Ressourcen müssen administrativ freigegeben sein. Bei statischen Clients zählt die administrativ gesetzte `resourceUrl` als Freigabe. Bei dynamischen Clients muss ein Administrator diese Adresse über die Client-Einstellungen speichern; ein bloßes `resource_url` aus DCR reicht nicht.
- Weitere Ressourcen können administrativ mit `occ config:app:set oidc approved_resources_<interne_Client-ID> --value='["https://api.example/resource"]'` freigegeben werden. Die interne numerische Client-ID ist maßgeblich, nicht `client_id` aus dem OAuth-Protokoll. Eine spätere Änderung der Standard-Ressource über die Einstellungen ersetzt diese Liste durch die neu gespeicherte Standard-Ressource.
- Nicht gewährte explizite Claims werden ausgelassen. Clients müssen die erforderlichen Scopes erlauben und die Zustimmung dafür erhalten. Telefon und Adresse benötigen künftig `phone` bzw. `address`.
- Die OIDC-Registrierungsregeln für `application_type=native` werden strikt umgesetzt. Native HTTPS-Redirects werden in diesem Registrierungsprofil abgelehnt; RFC 8252 erlaubt in seinem allgemeinen OAuth-Profil zusätzlich beanspruchte HTTPS-Redirects, die OIDC-DCR-Metadaten verlangen hier jedoch HTTP-Loopback oder private Schemes. Private Schemes benötigen einen Domain-Namen, etwa `com.example.app:/callback`.
- HS256-ID-Tokens aus der Zeit vor der Korrektur besitzen keinen Ausstellungsnachweis und werden vom Validierungslistener nicht akzeptiert. Neue Tokens ausstellen. Die Verifikation durch den eigentlichen OIDC-Client ist davon unabhängig.
- Bereits vor dem Upgrade gelöschte Replay-Hashes können nicht rekonstruiert werden. Die verlängerte Aufbewahrung schützt vorhandene und künftig gespeicherte Generationen.
- Bei lang laufenden Refresh-Familien wächst die Anzahl der Replay-Hashes mit der Zahl der Rotationen. Der bestehende Familien-Widerruf und die Bereinigung nach Familienablauf entfernen diese Daten.

## Verifikation

Ausgeführt: `node --test tests/Frontend/*.test.cjs` – **24 Tests bestanden**, einschließlich der Übertragung der tabbezogenen Consent-ID bei Bestätigung und Ablehnung.

Backend-Unit- und Datenbank-Regressionstests sind ergänzt bzw. an die korrigierte Semantik angepasst. In der bereitgestellten Arbeitsumgebung fehlen PHP, Composer-Abhängigkeiten und eine Nextcloud-Testinstallation; Paketdownloads waren gesperrt. Daher wurden PHP-Lint, PHPUnit, Datenbankmigrationen, Parallelitätsprüfungen auf echten Datenbanken und der Produktions-Frontend-Build hier **nicht ausgeführt**. Eine Prüfung von Zeichenketten-/Kommentarabschlüssen und Klammerbalance ersetzt diese Laufzeitprüfungen nicht.

In einer Nextcloud-Testinstallation mit installierter/aktualisierter App und Entwicklungsabhängigkeiten ausführen:

```sh
composer lint
vendor/bin/phpunit -c phpunit.xml
vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Integration/RefreshTokenCleanupIntegrationTest.php tests/Integration/AccessTokenMapperIntegrationTest.php
npm run lint
npm run build
```

Zusätzlich sind in einer Testinstallation Registrierung an der 100-Client-Grenze, HTTP 429 bei ausgeschöpfter Transaktionskapazität sowie konkurrierender Consent-Widerruf/Device-Polling auf den eingesetzten Datenbanken zu prüfen. Dieses Änderungsarchiv ist kein Nachweis vollständiger RFC-Konformität oder einer fehlerfreien Produktivinstallation.
