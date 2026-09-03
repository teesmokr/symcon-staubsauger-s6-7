# symcon-staubsauger-s6-7

IP-Symcon Modul zur Steuerung von **360 (Qihoo) Saugrobotern** der Modelle
**S6** und **S7** direkt über die 360 Cloud (`q.smart.360.cn`).

Die Steuerung erfolgt über den Session-Cookie, den die 360-App verwendet.
Dieser Cookie muss in der Instanzkonfiguration hinterlegt werden.

## Inhalt

1. [Funktionsumfang](#funktionsumfang)
2. [Voraussetzungen](#voraussetzungen)
3. [Installation](#installation)
4. [Einrichtung](#einrichtung)
5. [Variablen](#variablen)
6. [PHP-Befehlsreferenz](#php-befehlsreferenz)
7. [Hinweise](#hinweise)

## Funktionsumfang

- Reinigung starten / stoppen / pausieren
- Zur Ladestation zurückschicken
- Saugstufe setzen (Leise / Auto / Stark / Maximal)
- Roboter orten (Signalton)
- Statusabfrage (Batterie, Online-Status, Arbeitsmodus)

## Voraussetzungen

- IP-Symcon ab Version 6.0
- Ein bei der 360-App registrierter Saugroboter (S6 oder S7)
- Der gültige Session-Cookie der 360-App (siehe unten)

## Installation

Über den Module Store bzw. das Module Control die URL dieses Repositories
hinzufügen:

```
https://github.com/teesmokr/symcon-staubsauger-s6-7
```

Anschließend eine neue Instanz **Saugroboter360** anlegen.

## Einrichtung

In der Instanzkonfiguration werden folgende Angaben benötigt:

| Feld | Beschreibung |
|------|--------------|
| **360 Cookie** | Der vollständige Cookie der 360-App inkl. `qid` und `sid`. Format: `q=u=&t=1;t=&v=2.0&a=1; qid=<QID>; sid=<SID>` |
| **Seriennummer (SN)** | Die Seriennummer des Roboters. Über die Schaltfläche **Geräte anzeigen** können die vorhandenen Geräte samt Seriennummer aus der Cloud ausgelesen werden. |
| **Aktualisierungsintervall** | Intervall in Sekunden für das automatische Abrufen des Status (0 = deaktiviert). |

Mit der Schaltfläche **Geräte anzeigen** wird die Geräteliste aus der Cloud
abgefragt – so lässt sich die passende Seriennummer ermitteln.

## Variablen

| Ident | Typ | Beschreibung |
|-------|-----|--------------|
| `Action` | Integer | Steuerung: Starten / Pause / Stopp / Laden |
| `FanLevel` | Integer | Saugstufe (Leise / Auto / Stark / Maximal) |
| `Locate` | Boolean | Roboter orten |
| `Online` | Boolean | Erreichbarkeit des Roboters |

> Hinweis: Arbeitsstatus und Batteriestand werden von der 360 Cloud nicht über
> die Geräteliste geliefert (nur verschlüsselt per Push) und sind deshalb keine
> eigenen Variablen.

## Kachel (Tile-Visualisierung)

Das Modul bringt eine eigene, kompakte Kachel für die
Symcon-Kachelvisualisierung mit (HTML-SDK, ab Symcon 7.0). Über die Kachel
lässt sich der Roboter direkt **starten**, **pausieren** und **zur Ladestation**
schicken. Alle Beschriftungen sind auf Deutsch.

## PHP-Befehlsreferenz

Alle Aktionen lassen sich auch per Skript ausführen (`<InstanzID>` ersetzen):

```php
SR360_StartCleaning(<InstanzID>);       // Reinigung starten
SR360_StopCleaning(<InstanzID>);        // Reinigung stoppen
SR360_Pause(<InstanzID>);               // Pausieren
SR360_Resume(<InstanzID>);              // Fortsetzen
SR360_GoCharging(<InstanzID>);          // Zur Ladestation
SR360_Locate(<InstanzID>);              // Roboter orten
SR360_SetFanLevel(<InstanzID>, 2);      // Saugstufe (0=Leise,1=Auto,2=Stark,3=Max)
SR360_UpdateStatus(<InstanzID>);        // Online-Status abrufen
```

## Hinweise

- Die 360 Cloud besitzt keine offizielle, dokumentierte API. Die verwendeten
  Endpunkte wurden durch Analyse der App ermittelt und können sich ohne
  Vorankündigung ändern.
- Der Cookie kann nach längerer Zeit ablaufen und muss dann erneut hinterlegt
  werden.
- Der Echtzeit-Status (Arbeitsmodus, Batterie) wird von 360 ausschließlich
  verschlüsselt per Push geliefert und ist über die Geräteliste nicht abrufbar.
  Daher zeigt das Modul diese Werte nicht an; die Kachel dient der Steuerung.
