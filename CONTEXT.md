# EMS Projekt-Kontext für Claude — Übergabedokument

## Projekt
IP-Symcon EMS-Modul (Energy Management System)
GitHub: https://github.com/DG65/EMS
Entwickler: DG65
IPS-Version: 9.0

## GUIDs
- Library: {90286A25-E6C9-4A66-BD4E-0CFB707C2C6C}
- Modul:   {31C61A7B-28C4-4F97-9651-1A64B3469E3C}

## Dateistruktur
```
EMS/
├── library.json   — Bibliotheks-Metadaten
├── module.json    — Modul-Metadaten (type 3, vendor DG65)
├── form.json      — Konfigurationsoberfläche (9 ExpansionPanels)
├── module.php     — Hauptlogik (895 Zeilen, PHP 7.x kompatibel)
└── README.md      — Dokumentation
```

## Hardware-Setup (Referenzanlage DG65)
- Wechselrichter: Goodwe GW29.9k-ET via Modbus-TCP
- Batterie: Goodwe Lynx Home D, 40 kWh (2 Strings à 20 kWh, BMS 1+2)
- PV: 9,18 kWp (36 Module à 255Wp, 3 MPPTs, Azimut 166°, Neigung 27°)
- Wallboxen: 2x go-e Charger V3 HW Rev v3, 22 kW (Autos: 2x Tesla Model Y SR, 11 kW OBC)
- Wärmepumpe: Panasonic Aquarea 9 kW + Heishamon (MQTT) — NUR Monitoring, keine Steuerung
- Stromtarif: Tibber, 15-min dynamisch (TibberV2-Modul von da8ter)
- NAP-Messung primär: Goodwe SmartMeter (Modbus)
- NAP-Messung sekundär: Siemens PAC2200 (Modbus, redundant)
- IMSys: Inexogy
- §14a EnWG Modul 1+3 über E-Werk Netze: 00:00–06:00 Uhr, 90% Netzentgelt-Reduktion
- Einspeisevergütung: 18,36 ct/kWh (Anlage seit 2012)
- SLS: 50A → 34.641 W, HAK: 63A

## Wichtige technische Details

### Goodwe Modbus
- EMS Leistungsmodus Register 47511 (IPS ID 49060):
  0=Stop, 1=Auto, 2=Laden-Solar, 3=Entladen+Solar, 4=AC-Import,
  5=AC-Export, 6=ECO, 7=Insel, 8=Bereitschaft, 9=Stromeinkauf,
  10=Stromverkauf, 11=Bat-Laden, 12=Bat-Entladen
- EMS Leistungseinstellung Register 47512 (IPS ID 20610): Watt
- Zeitfenster-Format: (Stunden << 8) | Minuten, z.B. 02:30 = 542
- Work Week Format: High Byte 0xFF = enable, Low Byte Bits 0-6 = So-Sa
  Ganze Woche: 0xFF7F = 65407
- Manufacturer Code 47505 (ID 57412): muss NICHT auf 2 gesetzt werden
  zum Steuern — Goodwe akzeptiert Schreibbefehle auch ohne EMS-Modus
- Netzausfall: wird vom Goodwe selbst behandelt, EMS greift NICHT ein
- ALLES läuft über Backup-Port (Backup=1 ist Dauerzustand, kein Fehler)

### Modbus read-only Variablen
Alle Goodwe/Modbus-Variablen sind read-only in IPS.
Schreiben erfolgt über writeVar() → prüft VariableAction > 0 → RequestAction()
statt SetValue() um den "Variable is read-only" Fehler zu vermeiden.

### Wallboxen
- go-e Charger Modul: GOeCharger_SetMode($instanz, $mode)
  0=selbst, 1=nicht laden, 2=laden
- GOeCharger_SetCurrentChargingWatt($instanz, $watt)
- Kabel-Leistungsfähigkeit > 0 = Auto angesteckt
- Cooldown zwischen Schaltvorgängen: 120s default

### Wärmepumpe
- Heishamon via MQTT in IPS eingebunden
- KEINE Steuerung durch EMS (EEPROM-Schutz, läuft autonom)
- Nur Monitoring: Leistungsaufnahme für Lastberechnung
- Nacht 00:00-06:00: WP läuft normal mit Netzstrom (§14a)

### Tibber
- TibberV2-Modul (da8ter): act_price, act_level, PT15M_T0_0..95,
  PT60M_T0_0..23, Ahead_Price_Data JSON
- Einspeisevergütung: ID 13124 (18,36 ct/kWh)
- Grid Rewards: kein API-Wert verfügbar → manueller Schalter in EMS
- §14a: Effektiver Preis = Tibber-Preis × (1 - 0.90) zwischen 00:00-06:00

### PV Forecast
- open-meteo iconD2, stündliche Auflösung
- JSON in Variable 33019: {ts, tiso, day, hour, pv_estimate (kWh), temp, ...}
- Tageswerte: ID 54260 (heute), 38172 (morgen), etc.

## Modul-Architektur (module.php)

### Grundprinzip: Optionale Geräteblöcke
Jeder Block (Batterie, Wallbox, WP, Tibber, Forecast) hat CheckBox-Aktivierung.
Inaktive Blöcke werden nicht ausgewertet → Modul läuft auch mit Minimalausstattung.

### 5 Layer
1. readState(): Alle Messwerte einlesen, Hausverbrauch berechnen,
   effektiven Tibber-Preis nach §14a berechnen
2. updateStatusVars(): EMS-eigene Statusvariablen aktualisieren
3. checkProtection(): SLS-Phasenstrom, Gesamtleistung, WR-Temp, Bat-Temp,
   Zellspannungen → bei Auslösung: Wallboxen aus, Goodwe Bereitschaft
4. optimize(): Entscheidungsbaum:
   Grid Rewards → §14a Nacht → Tibber günstig → PV Eigenverbrauch →
   Tibber teuer entladen → Export → Automatik
5. applyDecision(): Goodwe schreiben, Wallboxen steuern, Cooldown prüfen

### Grid Rewards Logik
- Tibber steuert Wallbox direkt → EMS sperrt eigene WB-Steuerung
- Goodwe: GW_MODE_AC_IMPORT mit Limit = Hausverbrauch + WB-Last
- Batterie bleibt passiv (keine Ladung, keine Entladung)
- Leistungseinstellung wird JEDEN Zyklus aktualisiert (WB-Last ändert sich)

### SLS-Berechnung
- ApplyChanges() berechnet automatisch: A × 400V × 1.7321 = W
- Bei Änderung: IPS_SetProperty + IPS_ApplyChanges (rekursiv, einmalig)

### Logging
- emsLog(EMS_LOG_BASIC/VERBOSE, $message)
- SendDebug() → Instanz-Debug-Fenster
- IPS_LogMessage() → Systemlog (nur BASIC)

## Offene Punkte / TODO

### Prio 1 — Funktional
1. form.json: PAC2200-Felder bei deaktiviertem Block ausblenden
   (IPS visible-Kondition: {"type":"PropertyCondition","property":"PAC2200_Active","value":true})
2. PlanNightCharge(): Tibber PT15M JSON auswerten für optimales Fenster
   statt nur Start/Ende aus §14a-Config
3. PV Forecast JSON auswerten: stündliche pv_estimate für Vorausplanung

### Prio 2 — Erweiterungen
4. Dashboard WebFront: Energiefluss-Visualisierung der EMS-Statusvariablen
5. Tages-/Monatsauswertung: Kosten, Eigenverbrauchsquote, Autarkiegrad
6. Grid Rewards Auto-Erkennung via Tibber API (separates Modul)

### Prio 3 — Separate Projekte
7. Heishamon IPS-Modul: alle MQTT-Topics automatisch anlegen,
   typsichere PHP-Funktionen, EEPROM-Schutz eingebaut

## Bekannte Fixes (bereits in Code)
- "Variable is read-only": writeVar() mit RequestAction() statt SetValue()
- "Backup aktiv / Netzausfall": Backup-Erkennung komplett entfernt,
  Goodwe handelt Netzausfall selbst
- declare(strict_types=1): entfernt (IPS-Kompatibilität)
- match(): ersetzt durch array() + isset()
- Typed parameters: entfernt
- log(): umbenannt zu emsLog() (Konflikt mit PHP-Builtin)
- EMS_Active Variable: umbenannt zu EMS_Active_State (Konflikt mit Property)
- SLS-Limit_W: wird jetzt automatisch berechnet, nicht mehr manuell

## PHP-Kompatibilitätsregeln für IPS 9.0
- KEIN declare(strict_types=1)
- KEINE Typ-Deklarationen in Methoden-Signaturen (keine ": void", ": int" etc.)
- KEINE match()-Ausdrücke → array() + isset()
- KEIN mixed Type-Hint
- array() statt [] für maximale Kompatibilität
- for() statt range() in foreach
- Methodennamen nicht mit PHP-Builtins kollidieren
