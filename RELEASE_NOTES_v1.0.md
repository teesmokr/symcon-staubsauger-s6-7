# v1.0 – Erste Version

Erste Version des IP-Symcon Moduls **Saugroboter360** zur Steuerung von
360 (Qihoo) Saugrobotern (S6 / S7) über die 360 Cloud (`q.smart.360.cn`).

## Funktionen

- Reinigung starten / stoppen / pausieren / fortsetzen
- Zur Ladestation zurückschicken
- Saugstufe setzen (Leise / Auto / Stark / Maximal)
- Roboter orten
- Statusabfrage (Batterie, Online-Status, Arbeitsmodus)
- Konfigurationsformular (Cookie, Seriennummer, Aktualisierungsintervall)
- DE/EN-Übersetzungen und PHP-Befehls-API (`SR360_*`)

## Installation

Im Module Store / Module Control die Repository-URL hinzufügen:

```
https://github.com/teesmokr/symcon-staubsauger-s6-7
```

Anschließend eine neue Instanz **Saugroboter360** anlegen, den 360-Cookie und
die Seriennummer hinterlegen.

## Hinweise

- Die 360 Cloud besitzt keine offizielle API; Endpunkte und `infoType`-Codes
  stammen aus öffentlichen Reverse-Engineering-Quellen und können abweichen.
- Der Session-Cookie der 360-App wird zur Authentifizierung benötigt und kann
  nach einiger Zeit ablaufen.
- Die Kommandos `stop` / `resume` sind noch nicht mit einem echten Gerät
  verifiziert.

---

## So legst du den Release auf GitHub an

1. https://github.com/teesmokr/symcon-staubsauger-s6-7/releases/new
2. **Choose a tag** → `v1.0` eintippen → **„Create new tag: v1.0 on publish"** (Target: `main`)
3. Titel: `v1.0 – Erste Version`
4. Den Text oberhalb der Trennlinie als Beschreibung einfügen
5. **Publish release**
