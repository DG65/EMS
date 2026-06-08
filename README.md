# EMS — Energy Management System für IP-Symcon

## Übersicht

Das EMS-Modul koordiniert alle steuerbaren Energiekomponenten einer Hausanlage unter IP-Symcon. Es optimiert Eigenverbrauch, Batterieladung und Fahrzeugladung anhand dynamischer Strompreise (Tibber), PV-Erzeugungsprognosen und dem aktuellen Systemzustand.

Das Modul ist modular aufgebaut: Jeder Geräteblock (Wechselrichter, Batterie, Wallbox, Wärmepumpe usw.) ist optional und wird nur ausgewertet wenn er in der Konfiguration aktiviert und mit IPS-Variablen verknüpft ist. Dadurch ist das Modul für sehr unterschiedliche Anlagenkonfigurationen geeignet.

---

## Funktionsumfang

### Optimierungsstrategien
- **Tibber-Arbitrage**: Batteriespeicher bei günstigen Preisen laden, bei teuren Preisen entladen
- **PV-Eigenverbrauch**: PV-Überschuss priorisiert in Batterie und Fahrzeuge leiten
- **Lastmanagement**: Einhaltung der konfigurierten NAP-Leistungsgrenze (SLS-Schutz)
- **Fahrzeugladung**: Steuerung von Wallboxen nach Preis, SOC und Tageszeit
- **§14a EnWG Modul 1+3**: Berücksichtigung reduzierter Netzentgelte in definierten Zeitfenstern
- **Grid Rewards**: Manueller Schalter zum Übergeben der Wallboxsteuerung an Tibber

### Unterstützte Geräte
| Gerät | Pflicht | Steuerung |
|---|---|---|
| Goodwe Wechselrichter (Modbus-TCP) | Ja | Ja — EMS-Register |
| Goodwe Batteriespeicher (BMS) | Nein | Indirekt über WR |
| Goodwe SmartMeter (NAP) | Empfohlen | Nein — Messung |
| Siemens PAC2200 | Nein | Nein — Redundanzmessung |
| go-e Charger V3 (1–n) | Nein | Ja — API |
| Heishamon / Panasonic Aquarea | Nein | Nein — Monitoring |
| Tibber (TibberV2-Modul) | Empfohlen | Nein — Preisdaten |
| PV Forecast (pvForecast-Modul) | Nein | Nein — Prognosedaten |

---

## Voraussetzungen

- IP-Symcon ab Version 7.0
- Goodwe Wechselrichter bereits via Modbus-TCP in IPS eingebunden
- Für Wallboxsteuerung: go-e Charger Modul (IPSCoyote/GO-eCharger) installiert
- Für Preisoptimierung: TibberV2-Modul (da8ter) installiert
- Für Erzeugungsprognose: pvForecast-Modul installiert

---

## Installation

1. Im IP-Symcon Module Control folgende URL hinzufügen:
   ```
   https://github.com/[repository]/EMS
   ```
2. Neue Instanz anlegen: `Strg+1` → Hersteller "Community" → "EMS"
3. Konfiguration öffnen und Geräteblöcke aktivieren und verknüpfen
4. Intervall und Optimierungsparameter einstellen
5. EMS aktivieren

---

## Konfiguration

Die Konfiguration ist in folgende Sektionen gegliedert:

### 1. Allgemein & Schutz
- EMS aktiv (Hauptschalter)
- Aktualisierungsintervall (Sekunden)
- SLS-Limit in Ampere (Schutzgrenze NAP)
- HAK-Limit in Ampere
- Fallback-Modus bei Kommunikationsfehler

### 2. Netzmesspunkte
- **Primär**: Goodwe SmartMeter — phasenaufgelöste Leistung L1/L2/L3 am NAP
- **Sekundär** (optional): Siemens PAC2200 — Redundanzmessung und Plausibilitätsprüfung

### 3. Wechselrichter & PV
- Goodwe EMS-Steuerregister (Leistungsmodus, Leistungseinstellung)
- PV-Gesamtleistung (P Total)
- MPPT-Leistungen (optional, 1–3 MPPTs)
- Wechselrichtertemperaturen (Schutzmonitoring)

### 4. Batteriespeicher (optional)
- Aktivierung und Anzahl Batteriestrings (1–2)
- SOC, Leistung, Modus je String
- BMS-Temperaturen (Schutzmonitoring)
- Zellspannungen Min/Max (Schutzmonitoring)
- Konfigurierbare SOC-Grenzen:
  - Minimaler SOC (Entladeschutz)
  - Ziel-SOC Nacht (Tibber-Ladefenster)
  - Ziel-SOC Tag (PV-Puffer)

### 5. Wallboxen (optional)
- Aktivierung und Anzahl Wallboxen (1–n)
- Je Wallbox: IPS-Instanz-ID des go-e Charger Moduls
- Priorisierung bei mehreren Wallboxen
- Mindest-SOC Fahrzeug für Ladefreigabe (falls bekannt)
- Manuelles Laden / Grid Rewards Schalter

### 6. Wärmepumpe (optional, Monitoring)
- Aktivierung (nur Monitoring, keine Steuerung)
- Stromverbrauch Wärmepumpe (für Lastberechnung)
- Außentemperatur (alternativ zu Goodwe/Forecast)

### 7. Tibber & Tarif
- Aktivierung Tibber-Preisoptimierung
- IPS-Variable: aktueller Preis (act_price)
- IPS-Variable: aktuelles Preisniveau (act_level)
- IPS-Variable: Einspeisevergütung
- IPS-Variable: 15-Min-Preise JSON (PT15M Heute)
- IPS-Variable: 15-Min-Preise JSON (PT15M Morgen)
- Preisschwelle Batterieladung aus Netz (ct/kWh)
- Preisschwelle Batterieentladung (ct/kWh)
- Preisschwelle Fahrzeugladung (ct/kWh)

### 8. §14a EnWG / Netzentgelt-Zeitfenster
- Aktivierung §14a Modul 1+3
- Zeitfenster Start (Standard: 00:00)
- Zeitfenster Ende (Standard: 06:00)
- Netzentgelt-Reduktion in % (Standard: 90%)
- Netzbetreiber (Freitext, für Dokumentation)

### 9. PV Forecast (optional)
- IPS-Variable: Vorhersage Heute (kWh)
- IPS-Variable: Vorhersage Morgen (kWh)
- IPS-Variable: PVForecast JSON (stündliche Auflösung)
- Mindesterzeugung für PV-Eigenverbrauchsmodus (W)

### 10. Optimierungsparameter
- Gewichtung Eigenverbrauch vs. Arbitrage (0–100%)
- Hysterese Schaltentscheidungen (%, W)
- Mindestladezeit Wallbox (Minuten)
- Cooldown zwischen Schaltvorgängen (Sekunden)
- Logging-Level (Aus / Basis / Detailliert)

---

## Betriebsmodi

| Modus | Beschreibung | Goodwe EMS-Register |
|---|---|---|
| **Automatik** | EMS entscheidet nach Preis + SOC + Forecast | Modus 1 (Automatik) |
| **PV-Eigenverbrauch** | PV-Überschuss → Batterie → Autos | Modus 2 (Laden-Solar) |
| **Netz-Laden** | Günstiger Tibber-Preis → Batterie aus Netz laden | Modus 4 (AC-Import) |
| **Entladen** | Teurer Tibber-Preis → Batterie → Haus | Modus 3 (Entladen+Solar) |
| **Bereitschaft** | SOC halten, weder laden noch entladen | Modus 8 (Bereitschaft) |
| **Export** | Batterie → Netz (Preis > Einspeisevergütung) | Modus 5 (AC-Export) |
| **Notbetrieb** | Netzausfall erkannt, Backup aktiv | Modus 7 (Inselbetrieb) |

---

## Lastmanagement

Das EMS überwacht kontinuierlich die Netzleistung am NAP über den Goodwe SmartMeter und optional den Siemens PAC2200.

Die konfigurierte **SLS-Grenze** (Standard: 34.500 W bei 50A SLS) wird nie überschritten. Der Goodwe Wechselrichter übernimmt die physikalische Regelung über seinen eigenen SmartMeter — das EMS setzt lediglich den Sollwert (EMS Leistungseinstellung, Register ID 20610).

Ladepriorisierung bei mehreren gleichzeitigen Lasten:
1. Hausgrundlast (immer garantiert)
2. Wärmepumpe (läuft autonom)
3. Batterieladung (bis zum konfigurierten Ziel-SOC)
4. Wallbox 1 (nach Priorisierung)
5. Wallbox 2 (nach Priorisierung)

---

## Grid Rewards / Tibber Wallboxsteuerung

Wenn Tibber über Grid Rewards die Wallbox direkt steuert, muss verhindert werden dass der Goodwe die Fahrzeugladung aus der Batterie speist (was den Grid-Rewards-Effekt zunichte machen würde).

**Lösung**: Manueller Schalter "Grid Rewards Modus" in der EMS-Oberfläche.

| Schalter | Wallboxsteuerung | Batterieverhalten |
|---|---|---|
| Aus (Standard) | EMS steuert Wallbox | Normal — Arbitrage |
| Ein (Grid Rewards) | Tibber steuert Wallbox | Batterie hält SOC (Modus 8) |

---

## IPS-Variablen (vom Modul angelegt)

Das Modul legt folgende Statusvariablen automatisch an:

| Variable | Typ | Beschreibung |
|---|---|---|
| EMS_Active | Boolean | EMS aktiv/inaktiv |
| EMS_Mode | Integer | Aktueller Betriebsmodus |
| EMS_GridPower | Float | Aktuelle Netzleistung (W) |
| EMS_PVPower | Float | Aktuelle PV-Leistung (W) |
| EMS_BatPower | Float | Aktuelle Batterieleistung (W) |
| EMS_BatSOC | Float | Batterie-SOC gesamt (%) |
| EMS_HousePower | Float | Hausverbrauch berechnet (W) |
| EMS_WB1Power | Float | Wallbox 1 Ladeleistung (W) |
| EMS_WB2Power | Float | Wallbox 2 Ladeleistung (W) |
| EMS_TibberPrice | Float | Aktueller Tibber-Preis (ct/kWh) |
| EMS_GridRewards | Boolean | Grid Rewards Modus aktiv |
| EMS_LastAction | String | Letzte EMS-Aktion (Log) |
| EMS_Status | String | Statusmeldung |

---

## Sicherheitshinweise

- Das EMS schreibt Steuerbefehle direkt in den Goodwe Wechselrichter via Modbus-TCP. Eine fehlerhafte Konfiguration kann zu unerwünschtem Verhalten führen.
- Der SLS-Schutz ist eine Softwaregrenze — die physikalische Absicherung durch den SLS-Schalter bleibt unberührt und ist die primäre Schutzebene.
- Heishamon/Panasonic Aquarea: Das EMS steuert die Wärmepumpe **nicht aktiv**. Häufige Schreibzugriffe auf Heishamon können den EEPROM der Wärmepumpe schädigen und werden vermieden.
- Bei Kommunikationsausfall zu einem Gerät wechselt das EMS in den konfigurierten Fallback-Modus.

---

## GUIDs

| Komponente | GUID |
|---|---|
| Library | {90286A25-E6C9-4A66-BD4E-0CFB707C2C6C} |
| Modul | {31C61A7B-28C4-4F97-9651-1A64B3469E3C} |
| Instanz | {DC6F3120-07C3-4F2A-BE46-06BB33BC5FA3} |

---

## Lizenz

Dieses Modul steht unter der MIT-Lizenz zur freien Nutzung und Weiterentwicklung.

## Danksagung

Dieses Modul basiert auf der Arbeit der IP-Symcon Community und nutzt folgende externe Module:
- [GO-eCharger Modul](https://github.com/IPSCoyote/GO-eCharger) von IPSCoyote
- [TibberV2 Modul](https://github.com/da8ter/TibberV2) von da8ter
- [HeishaMon](https://github.com/heishamon/HeishaMon) Protokoll
