# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei
dokumentiert.

Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/),
die Versionierung an [Semantic Versioning](https://semver.org/lang/de/).

## [1.0] – 2026-09-03

### Hinzugefügt

- Erste Version des IP-Symcon Moduls **Saugroboter360** zur Steuerung von
  360 (Qihoo) Saugrobotern der Modelle **S6** und **S7** über die 360 Cloud
  (`q.smart.360.cn`).
- Steuerung: Reinigung starten / stoppen / pausieren / fortsetzen.
- Zur Ladestation zurückschicken.
- Saugstufe setzen (Leise / Auto / Stark / Maximal).
- Roboter orten (Signalton).
- Statusabfrage über die Geräteliste: Batteriestand, Online-Status und
  Arbeitsmodus.
- Konfigurationsformular mit Cookie, Seriennummer und Aktualisierungsintervall
  sowie den Schaltflächen „Geräte anzeigen" und „Status jetzt aktualisieren".
- PHP-Befehls-API (`SR360_*`) zur Nutzung in Skripten.
- Deutsche und englische Übersetzungen.

### Hinweise

- Die 360 Cloud besitzt keine offizielle, dokumentierte API. Die verwendeten
  Endpunkte und `infoType`-Codes stammen aus öffentlichen
  Reverse-Engineering-Quellen und können sich ohne Vorankündigung ändern.
- Der Session-Cookie der 360-App wird zur Authentifizierung benötigt und kann
  nach einiger Zeit ablaufen.
- Die Kommandos `stop` / `resume` (`infoType` 21017) sind plausibel, aber noch
  nicht mit einem echten Gerät verifiziert.
