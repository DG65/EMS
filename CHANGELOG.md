# Changelog

## 0.27.1 (2026-09-01)
- **`EMS_GetCurrentDecision()`: Erklärung für Batterie-Entladung parallel zu
  Fahrzeug-Ladung via Tibber Grid Rewards** (Dietmars Rückmeldung über
  Dashboard). Sieht auf den ersten Blick widersprüchlich aus (Batterie
  entlädt fürs Haus, Auto lädt gleichzeitig aus dem Netz) — ist aber
  wirtschaftlich korrekt: die Grid-Reward-Einspeiseprämie ginge verloren,
  würde die Batterie stattdessen fürs Auto verwendet. `reason` bekommt bei
  DISCHARGE/EXPORT + aktiver Tibber-Fahrzeugladung jetzt automatisch einen
  erklärenden Zusatz mit dem aktuellen Prämiensatz.

## 0.27.0 (2026-09-01)
- **Neuer Vertrag `EMS_GetCurrentDecision()` 1.0** (Dashboard-Anfrage, für
  die Demo-Kachel und generell): zeigt lesend, WAS das EMS gerade schaltet
  und WARUM — `mode`/`modeCode`, `reason` (Klartext), `source`
  (`netzbetreiber`/`tibber`/`stromgedacht`/`tagesplan`/`nutzer`/`ems`, je
  nachdem welche Ebene der Prioritätshierarchie gerade entscheidet),
  `since` (Zeitpunkt des letzten echten Moduswechsels, nicht jedes
  Reassert), `active`. Dafür taggt jeder Entscheidungszweig in
  `optimize()`/`applyPlanSlot()` jetzt sein `source`-Feld.

## 0.26.4 (2026-08-31)
- **Fix: `GetSituation()` reagiert auf `TIBBERGR_GetActiveControls` Major-Bruch
  (contractVersion 1.0 → 2.0).** Tibber Grid Reward liefert `deviceId` jetzt
  als Tibbers eigene `vehicleId`/`batteryId` (string) statt hart `0` (int).
  EMS hatte das bisher direkt (und folgenlos, weil immer 0) als `instanceID`
  in `GetSituation()`s Tibber-Einträge übernommen — mit der echten ID wäre
  das ein stiller Typwechsel int→string auf `instanceID` gewesen, ein
  eigener stiller Vertragsbruch für `GetSituation()`-Konsumenten. Fix:
  `instanceID` bleibt `0` (EMS hat keine lokale Instanz für diesen
  Tibber-Eintrag), Tibbers ID steht jetzt additiv im neuen Feld
  `tibberDeviceId`.

## 0.26.3 (2026-08-31)
- **Fix: PHP-Standardwerte auf öffentlichen Vertragsmethoden entfernt**
  (`GetSpecialEvents`, `GetInvoiceCheck`, `StartBatteryBoost`, `BuildDayPlan`).
  OCPPHub hat live nachgewiesen (Reflection-Beweis), dass Symcons generierte
  globale `PREFIX_Methode()`-Funktion PHP-Defaults grundsätzlich ignoriert —
  jeder Parameter ist dort zwingend, auch wenn der Quellcode einen Default
  zeigt. Betraf EMS bislang nicht (alle internen Aufrufer übergaben schon
  explizite Argumente), war aber ein Risiko für künftige externe Aufrufer
  (z. B. ModbusSlave → `EMS_GetSpecialEvents`). Signaturen jetzt ohne
  Default, Semantik unverändert. Siehe SUITE.md, Aritäts-Abschnitt.

## 0.26.2 (2026-08-29)
- **Fix: `EMS_Active=false`-Handoff (`enable=false`+`mode=1`+`power=0`) lief
  bisher jeden `Update()`-Zyklus erneut, nicht nur einmal beim Übergang.**
  Dietmars Einwand: bei ausgeschaltetem EMS will er die Anlage auch komplett
  händisch schalten können, ohne dass EMS seine manuellen Änderungen jeden
  Zyklus wieder überschreibt ("dann brauchst Du diese Werte nicht weiter zu
  verfolgen"). Neues Attribut `EmsInactiveHandoffDone` sorgt dafür, dass der
  Dreifach-Befehl nur einmalig beim Übergang aktiv→inaktiv gesendet wird;
  danach lässt EMS die Finger vom WR, bis es wieder aktiviert wird (Flag
  wird beim Übergang inaktiv→aktiv zurückgesetzt).

## 0.26.1 (2026-08-29)
- **Fix: Szenario B des Totmann-Fallbacks war falsch beschriftet/unwirksam.**
  Dietmars Korrektur, direkt nach 0.26.0: Die Annahme "`ctl_ems_enable=false`
  → WR ignoriert `ctl_ems_mode`/`-power`, fährt eigene Selbstverbrauchslogik"
  (Fund 25.07.2026) ist FALSCH — sauberer A/B-Test zeigt: bei `enable=false`
  wird der zuletzt kommandierte Modus dauerhaft AUSGEFÜHRT, ohne Heartbeat.
  `enable=false` allein friert also nur ein, was gerade lief, statt in
  Automatik zurückzufallen. `handleGoodweDeadman()`-Szenario B und der
  `EMS_Active=false`-Pfad senden jetzt explizit `enable=false` **+**
  `mode=1` (Automatik) **+** `power=0` zusammen, statt nur `enable=false`.
  SUITE.md/CLAUDE.md entsprechend zweifach korrigiert (auch die
  `ctl_ems_enable`-Grundsemantik selbst war missverständlich dokumentiert).

## 0.26.0 (2026-08-29)
- **Neu: WR-Totmann-Erkennung + -Reaktion, EMS ist ab sofort alleiniger
  Regler.** Architektur-Übergabe von InverterHub (0.75.0-beta.1): InverterHub
  ist jetzt reine Kommunikationsschicht ohne eigenen Reassert/Heartbeat/
  Fallback — EMS übernimmt die Politik. Auslöser: der zweitägige 255/STOPPED-
  Rückfall wurde als offizieller, beabsichtigter WR-Sicherheitsmechanismus
  identifiziert (ausbleibender Heartbeat bei `ctl_ems_enable=true` → WR
  parkt sich nach ~70-120s selbst).
  - Neue `handleGoodweDeadman()`: liest InverterHubs `ctl_ems_mode`-Variable
    (echter WR-Ist-Zustand, InverterHub liest zurück), flankengetriggert
    (nur beim Übergang nach 255, nicht bei jedem Zyklus).
  - Zwei Nutzer-Szenarien (`WATCHDOG_Deadman_Reaction`, Dietmars Vorgabe,
    beide gleichwertig): 0 = Sicherheits-Stopp respektieren (Default), 1 =
    Fallback in WR-Eigenregelung (`ctl_ems_enable=false`).
  - **Pendel-Bremse:** >3 Totmann-Ereignisse/Stunde → Fallback wird
    automatisch gestoppt (immer Szenario A), bis manuell über
    `WATCHDOG_Reset_Brake` zurückgesetzt — verhindert dauerhaftes Ein/Aus-
    Schalten des WR bei schlechter Verbindung statt echter Einzelvorfälle.
  - Läuft unabhängig von `EMS_Active` (auch Fremdeinfluss wie SEMS+-App
    kann 255 auslösen).
  - **Ist `EMS_Active=false`, wird `ctl_ems_enable` jetzt aktiv auf `false`
    gehalten** (jeden Zyklus reasserted) — kein "3rd party EMS"-Anspruch
    ohne tatsächlich aktive Steuerung.
- **Neu: Risiko-Bestätigung vor Formular-Freischaltung.** Dietmars Vorgabe:
  "EMS aktiv" und alle Steuerungsfelder (Batterie, Tibber, Wallboxen, §14a,
  Optimierung, Totmann-Reaktion) sind erst sichtbar, nachdem der Nutzer
  eine Warnung zu den Risiken aktiver WR-Steuerung explizit bestätigt hat
  (`EMS_Risk_Acknowledged`). Info-/Status-Panels (Doku, Verbund-Status,
  News, Benachrichtigungen) bleiben unabhängig davon sichtbar.

## 0.25.2 (2026-08-28)
- **Fix: Wallboxen blieben nach Aufhebung der §14a-Lastbegrenzung dauerhaft
  gesperrt (bei EMS_Active=false).** Zweiter Live-Testlauf sofort nach
  0.25.1 zeigte: die Zwangsabschaltung hatte keine Gegenseite — sobald
  `loadDimmActive` wieder auf `false` ging, passierte nichts, die
  Wallboxen blieben für immer aus, solange EMS deaktiviert ist. Neues
  Attribut `SteuerboxLoadLimitSetByUs` (analog zum bestehenden
  Einspeisereduktions-Flag): Wallboxen werden nur wieder freigegeben,
  wenn EMS sie selbst gesperrt hatte — eine vom Nutzer manuell gesetzte
  Ladefreigabe=Aus bleibt unangetastet.

## 0.25.1 (2026-08-28)
- **Fix: §14a-Lastbegrenzung bei EMS_Active=false schaltete die Wallboxen
  gar nicht ab.** Live-Test gegen SteuerboxHubs Testinstanz sofort nach dem
  0.25.0-Release: `setAllWallboxes()` bedient nur die alte direkte
  go-e-Property (`WB1_Instance`/`WB2_Instance`), die bei Dietmars Anlage
  beide 0 sind (Wallboxen laufen über ChargerHub). Der unconditional
  §14a-Pfad in `Update()` nutzt jetzt `controlWallbox(1/2, false)` (prüft
  zuerst ChargerHub, fällt erst danach auf die alte Property zurück) —
  derselbe Pfad, den `applyDecision()` im aktiven EMS-Betrieb ohnehin nutzt.

## 0.25.0 (2026-08-28)
- **Neu: §14a-Netzbetreiber-Dimmung (SteuerboxHub, `SBH_GetState`) wird
  konsumiert.** War architektonisch seit Wochen als "oberste Priorität"
  vorgesehen (siehe SUITE.md "§14a-Lastabwurf-Priorisierung"), aber nie
  tatsächlich gebaut — SteuerboxHub ist jetzt live an einer Testinstanz
  (Dummy-Kontakte), Auslöser für den Bau.
  - `loadDimmActive`: neue oberste Priorität in `optimize()`, noch vor Grid
    Rewards — schaltet alle Wallboxen ab, WR auf Automatik (kein aktiver
    Netzbezugs-Sollwert), damit EMS die Vorgabe nicht selbst konterkariert.
  - `feedInDimmActive`: neue `applySteuerboxFeedInLimit()`, nutzt die
    InverterHub-Einspeisegrenzen-Idents (`ctl_export_enable`/
    `ctl_export_limit`) — ein eigener Kanal, unabhängig von `ctl_ems_mode`/
    `-power`. Hebt die Grenze nur auf, wenn sie zuletzt von uns selbst
    gesetzt wurde (Attribut-Flag), um eine manuell vom Nutzer gesetzte
    Grenze nicht grundlos wegzunehmen.
  - **Beide laufen bewusst UNABHÄNGIG von `EMS_Active`** (wie schon
    `BuildDayPlan()`) — eine Gesetz-/Netzbetreiber-Vorgabe darf nicht davon
    abhängen, ob die EMS-Preisoptimierung gerade läuft.
  - `function_exists`-abgesichert, liefert `null` statt stillschweigend
    `false` vorzutäuschen, wenn SteuerboxHub nicht installiert ist.

## 0.24.2 (2026-08-25)
- **Fix: Sommer-/Winterzeit-Bug bei der Slot-zu-Uhrzeit-Zuordnung.**
  Verbundweite Prüfung, von Dashboard/Dietmar angestoßen. `GetDayPlan()`s
  Slot→Zeitstempel-Mapping und `getPvStartTomorrowTs()` gingen von "1 Tag =
  86400 Sekunden" aus (`$baseToday + $slot*900`) — an den zwei DST-
  Übergangstagen (23h im März, 25h im Oktober) hätte das die letzten Slots
  auf falsche Zeitstempel gelegt bzw. eine reale Stunde im Oktober gar nicht
  abgebildet. Betraf nur Anzeige (Dashboard-Diagramm)/PV-Start-Berechnung,
  nicht die Preis-Entscheidungslogik selbst (die filtert über
  Kalenderdatum-Strings, nicht über Sekundenarithmetik). Neue
  `slotTimestamp()`-Hilfsfunktion nutzt `mktime()` (echte Wanduhrzeit,
  DST-sicher) statt fester Sekundenaddition.

## 0.24.1 (2026-08-25)
- **Neu: Rechnungsprüfung, Ist-Seite.** Auslöser: Dietmar wollte eine
  Rechnungsprüfung, hatte dazu eine Original-Tibber-Rechnung hochgeladen —
  die Datei ging in einer früheren, zusammengefassten Sitzungsphase verloren
  (bei keinem Verbund-Modul eine Kopie gefunden), Dashboard hatte aber
  parallel 3 echte Rechnungen bekommen und den Aufbau mitgeteilt (Verbrauchs-
  kosten, Grundgebühr inkl. §14a-Reduktion, zwei Grid-Rewards-Zeilen,
  Kampagnen, periodenübergreifende Gutschrift-Verrechnung). Entscheidung
  (Dietmar, Option 3 von dreien): **kein PDF-Parsing** — zu fehleranfällig
  bei Layoutänderungen. Stattdessen drei neue WebFront-Eingabefelder
  (`INVOICE_Ist_Betrag`/`INVOICE_Ist_MwSt`/`INVOICE_Ist_Gutschrift`, direkt
  im WebFront nutzbar nach dem SUITE.md-Punkt-10-Muster von heute:
  `RegisterVariableFloat`+`EnableAction`, nicht Property+Konsolenformular).
  Neues `EMS_GetInvoiceCheck($year=0, $month=0)` (contractVersion 1.0) für
  Dashboard, liefert die eingetragenen Ist-Werte für den Monat.
  **Soll-Seite noch offen:** braucht historische Preiskomponenten von Tibber
  Grid Reward, die deren aktueller Vertrag (`TIBBERGR_GetPriceCurve`, nur
  heute+morgen) nicht liefert — separat anzufragen, bevor `soll`/`abweichung`
  additiv ergänzt werden können.

## 0.24.0 (2026-08-25)
- **Neu: Fahrzeug-Ladebedarf bei Heimkehr fließt in den Tagesplan ein.**
  Auslöser: Dietmar bat mich, den SOC von "Schneeflocke" bei Ankunft
  vorherzusagen — ich interpretierte dabei fälschlich das aktuelle
  Navigationsziel ("Philippsburg") als Heimfahrt. Tessie hat daraufhin
  `TESSIE_GetVehicleState` auf **contractVersion 1.4** erweitert
  (`distanceToHomeKm`, `headingHome`, `expectedHomeArrivalSocPercent` —
  Letzteres nur gesetzt, wenn tatsächlich Richtung Zuhause navigiert wird).
  Neue `computeVehicleReserveKwh()`: summiert über alle Tessie-Fahrzeuge
  den erwarteten Ladebedarf (Ladelimit − Ankunfts-SOC-Prognose) × Batterie-
  kapazität, aber nur für Fahrzeuge, die laut `headingHome` tatsächlich
  heimwärts fahren UND innerhalb des neuen konfigurierbaren Umkreises
  `VEH_Home_Radius_Km` (Default 200 km) liegen — reine EMS-Geschäftsregel
  ("gilt eine Rückfahrt heute noch als plausibel?"), bewusst nicht Teil des
  Tessie-Vertrags. Der Bedarf wird wie die Preis-Reserve behandelt (erhöht
  `effectiveTargetDay`, PV lädt bevorzugt statt zu exportieren) — ist damit
  automatisch Teil der Cache-Signatur, ein startender Heimweg löst also
  zuverlässig eine Neuberechnung aus.

## 0.23.7 (2026-08-25)
- **Fix: Preis-Entladen-Zweig blockierte die Entladung komplett statt sie zu
  erlauben.** Live-Fund über InverterHubs Rückfrage (`ctl_ems_mode=Entladen+
  Solar`, `ctl_ems_power=0W` beobachtet): Modus 3 (Entladen+Solar) nutzt
  `ctl_ems_power` als **Obergrenze der erlaubten Entladeleistung** (Xmax),
  nicht als additiven Zusatzwert. Der Preis-Entladen-Zweig in
  `simulateDaySlot()` schrieb dort bisher `power=0` — das kappte die
  Entladung auf null, statt sie freizugeben, obwohl "Eigenverbrauch aus
  Batterie" die erklärte Absicht war. Gegenbeweis im eigenen Code: der
  bereits korrekt funktionierende Batterie-Boost-Zweig nutzt für denselben
  Modus längst `EMS_Max_Power_W` als Wert. Jetzt: `$ctx['dischargeKw']`
  (reale, vom BMS gemeldete Entladeleistung, siehe 0.23.6) statt 0.

## 0.23.6 (2026-08-24)
- **Reale Batterie-Lade-/Entladeleistung statt fester 0,5C-Schätzung.**
  Dietmars Live-Angabe (Beladung C0,6, Entladung C1) und InverterHub-
  Nachfrage ergaben: InverterHub meldet die tatsächliche, vom BMS live
  berechnete maximale Lade-/Entladeleistung bereits (`bat_charge_max_w`/
  `bat_discharge_max_w`, Kategorie "Batterie (gemeinsam)") — SOC-/
  temperaturabhängig (bei SOC 96% live nur noch 5486W Ladeleistung
  statt der nominellen 24kW bei 0,6C, typisches CC/CV-Ladeverhalten).
  Neue `getBatteryPowerLimitsKw()` liest diese Werte direkt, fällt auf
  die alte 0,5C-Schätzung zurück, wenn kein WR gefunden wird oder die
  Idents fehlen (ältere InverterHub-Version). Gilt herstellerunabhängig
  für jeden InverterHub-Treiber, nicht nur GoodWe.
- Neues `$ctx['dischargeKw']` (noch nicht konsumiert, für künftige
  Entladeraten-Begrenzung in `simulateDaySlot()` vorbereitet).

## 0.23.5 (2026-08-22)
- **Fix: Morgen-Preise blieben dauerhaft `null`, obwohl Tibber sie längst
  veröffentlicht hatte.** Fund der Dashboard-Sitzung (live gegenübergestellt:
  `TIBBERGR_GetPriceCurve` liefert morgen vollständig, `EMS_GetDayPlan()`
  zeigt für morgen durchgehend `price:null`). Ursache: die Cache-Signatur aus
  0.23.2 hing NUR an den heutigen Preisen. Sobald die sich (früh am Tag)
  stabilisiert hatten, überspringt `BuildDayPlan()` die komplette Funktion —
  inklusive des Morgen-Blocks — auch wenn Tibber die Morgen-Preise erst
  Stunden später (typisch ca. 13-14 Uhr) veröffentlicht. Morgen-Preise werden
  jetzt VOR dem Signatur-Vergleich geholt und sind Teil der Signatur, ihr
  Erscheinen löst also zuverlässig eine Neuberechnung aus.

## 0.23.4 (2026-08-22)
- **Fix: Lastprognose für den "Morgen"-Tagesplan war die von HEUTE.**
  Diagnose zusammen mit Dietmar: LoadForecast liefert "Erwartung heute"
  12,62 kWh vs. "Erwartung morgen" 17,85 kWh (+41%) — `BuildDayPlan()`
  berechnete `$avgHouseW` bisher nur einmal aus dem Fenster "heute→morgen"
  (`LFC_GetEnergyWindow(..., strtotime('today'), strtotime('tomorrow'))`)
  und verwendete diesen Wert unveraendert auch für die komplette
  Tagesplan-Simulation von morgen weiter — sowohl für
  `computeExpensiveReserveKwh()` (Preis-Reserve) als auch für den
  PV-Überschuss-Vergleich in `simulateDaySlot()`. Neuer eigener
  `$avgHouseWTomorrow` aus dem Fenster "morgen→übermorgen", eigener
  `$ctxTomorrow` mit diesem Wert für den Morgen-Simulationslauf.
- Nebenfund (noch offen, nicht Teil dieses Fixes): `Discover()` zählt
  LoadForecast (LFC) nicht zu den überwachten Partnermodul-Typen — ein
  Ausfall des LFC-Vertrags würde nicht als "nicht erreichbar" gemeldet
  wie bei den anderen 6 Modultypen. `getLfcInstance()` selbst ist davon
  unabhängig und funktioniert korrekt.

## 0.23.3 (2026-08-22)
- **Fix: Preis-Reserve fehlte im Arbitrage-Export-Zweig** ("Es verändert
  sich nichts" trotz Build 61 live). Diagnose per Tooltip: Dietmars
  "Morgen"-Diagramm zeigte um 13:00 Uhr weiterhin den ALTEN kurzen Text
  "Bezug 18,01ct < Einspeisevergütung 18,36ct -- Batterie exportiert,
  Haus aus Netz" statt einer Preisbonus-Meldung — an diesem Slot gab es
  laut Prognose (PV 1,17kW < Last 1,50kW) gar keinen PV-Überschuss, der
  0.23.1/0.23.2-Preisbonus wirkt aber NUR im PV-Lade-Zweig, nicht in
  diesem separaten "Bezug < Einspeisevergütung"-Zweig (Dietmars eigene
  Vorgabe vom 19.08.2026). Der prüfte bisher nur die statische
  Sicherheitsmarge, nicht die noch kommenden teuren Stunden — dieselbe
  Kritik wie beim ursprünglichen Export-Bug, nur in einem zweiten,
  unabhängigen Zweig versteckt. Jetzt gilt `$priceBonusPct` (dieselbe
  aus der Lastprognose berechnete Reserve wie beim PV-Lade-Zweig) auch
  hier als zusätzliche Exportsperre.
- **Fix: `EMS_NEWS_VERSION`-Konstante war seit Monaten von der echten
  Modulversion entkoppelt** (fest auf "0.6.0" trotz inzwischen 0.23.2),
  dadurch zeigte das "Dokumentation & Hilfe"-Panel die verwirrende
  Kombination "EMS Version 0.6.0 (Build 61)". Neue `getOwnVersion()`
  liest die echte Version wie `getOwnBuild()` direkt aus `IPS_GetLibrary()`.
  Die "Neu in Version"-Anzeige/Dismiss-Logik nutzt bewusst weiterhin die
  separate `EMS_NEWS_VERSION`-Konstante (kuratierter Änderungstext, soll
  nicht bei jedem Mini-Build erneut aufpoppen).

## 0.23.2 (2026-08-21)
- **Fix: "Tagesplan-Berechnung dauert sehr lange"** — die Cache-Signatur
  (entscheidet, ob `BuildDayPlan()` bei jedem 30-Sekunden-Update()-Tick
  wirklich neu rechnet) beruhte auf dem ROHEN, unverarbeiteten JSON-Text
  von Tibber Grid Reward, nicht auf den tatsächlich relevanten Preisen.
  Nebenfelder wie `level_tibber`/`basis`/`netzentgelt` können sich
  ändern, ohne dass sich ein einziger Preis ändert — jede solche Änderung
  ließ die Signatur abweichen und erzwang eine komplette Neuberechnung
  (192 Slots, 96 Kalender-Schreibvorgänge) praktisch bei jedem Tick,
  statt nur wenn sich echte Preisdaten ändern. Signatur hängt jetzt am
  Hash der GEPARSTEN 96 Preiswerte — stabil gegen jede für die Planung
  irrelevante Formatierungs-/Nebenfeld-Änderung der Quelle.

## 0.23.1 (2026-08-21)
- **Preisbewusste Sicherheitsmarge auf echte Bedarfsrechnung umgestellt**
  (0.23.0 war zu schwach). Live-Beispiel von Dietmar: Preis 18ct jetzt,
  bis zu 46ct abends — der alte, auf +15 Prozentpunkte gedeckelte Bonus
  hob das Tagesziel nur von ~27% auf 42% an, obwohl bei diesem
  Preisgefälle deutlich mehr Reserve sinnvoll wäre. Der Deckel war
  ebenso willkürlich wie die kritisierte starre Zielprozentzahl selbst.
  Neue `computeExpensiveReserveKwh()`: rechnet direkt aus der
  Lastprognose (`NEG_Avg_House_Load_W`) und allen noch kommenden
  Viertelstunden mit Preis über der Entladen-Schwelle den ECHTEN
  kWh-Bedarf aus — der Bonus ist jetzt proportional zum tatsächlichen
  Bedarf, kein künstlicher Prozentpunkte-Deckel mehr (nur noch die
  100%-Grenze). Reason-Text nennt jetzt die konkrete kWh-Reserve statt
  nur einen Prozentsatz.

## 0.23.0 (2026-08-21)
- **Neu: preisbewusste Sicherheitsmarge für das Tagesziel.** Dietmars
  Einwand: die bisherige Zielberechnung (`getDynamicSocTargetDay()`) fragt
  nur "reicht die Energie bis morgen früh?", nicht "lohnt es sich, JETZT
  zu exportieren, wenn später am Tag noch eine teurere Stunde kommt?" —
  konkret beobachtet bei SOC 59 %, teuren Strompreisen, aber Export
  ("Akku am Ziel"), weil das rein energetische Ziel schon erreicht war.
  Neue Methode `computeSuffixMaxPrice()` ermittelt je Slot den teuersten
  noch bevorstehenden Preis im bekannten Preishorizont; ist der spürbar
  teurer als die Entladen-Schwelle, wird das Tagesziel für diesen Moment
  automatisch angehoben (gedeckelt auf max. +15 Prozentpunkte) — die
  Batterie hält dann mehr Reserve für die teure Stunde vor, statt den
  PV-Überschuss sofort zu exportieren. Reason-Text nennt den Bonus
  explizit ("+X% wegen teurer Stunde später"), damit die Entscheidung im
  Tagesplan nachvollziehbar bleibt.

## 0.22.6 (2026-08-20)
- **Kritischer Fix: wirtschaftlich rückwärtiger Export-Entscheid bei
  Spotpreisen zwischen Export- und Entladen-Schwelle entfernt.** Live-Fund
  über den Reason-Text im Dashboard-Diagramm ("Export: 28,45ct >
  Einspeisevergütung 18,36ct") — von Dietmar selbst als unplausibel
  erkannt: bei fester Einspeisevergütung bringt Export IMMER nur die feste
  Vergütung, unabhängig vom Spotpreis. Der entfernte Zweig wählte
  fälschlich "Export" statt "Entladen", sobald der Spotpreis über
  `TIB_Threshold_Export` (Standard 0,20 €) lag — und weil dieser
  Schwellwert NIEDRIGER als `TIB_Threshold_Discharge` (Standard 0,25 €)
  war, griff er JEDES Mal zuerst, der Entladen-Zweig kam gar nie zum Zug.
  Sobald der Spotpreis über der Einspeisevergütung liegt, ist Entladen
  (teuren Netzbezug vermeiden) wirtschaftlich mindestens gleichwertig,
  meist besser — der einzige korrekte Export-Auslöser bleibt der
  bestehende Zweig "Bezug < Einspeisevergütung". Die jetzt ungenutzte
  Property `TIB_Threshold_Export` wurde entfernt (kein Ersatz nötig).

## 0.22.5 (2026-08-20)
- Sicherheits-Hinweistext beim "🔄 Übernehmen erzwingen"-Button ergänzt
  (CometWiFi-Anregung): stellt klar, dass EMS' `ApplyChanges()` keine
  Befehle an Wechselrichter/Batterie sendet, nur den Tagesplan-Kalender
  anlegt/repariert und den Timer neu setzt — und dass noch nicht
  übernommene Formulareingaben dabei verloren gehen.

## 0.22.4 (2026-08-20)
- **Neuer Button "🔄 Übernehmen erzwingen (ohne Formularänderung)"** —
  Dietmars Frage: wie löst man `ApplyChanges()` aus, ohne ein Formularfeld
  ändern zu müssen (z. B. nach jedem Modul-Update, um einen Code-Fix
  sofort greifen zu lassen)? Ruft `IPS_ApplyChanges($id)` direkt auf, mit
  Popup-Bestätigung.

## 0.22.3 (2026-08-20)
- **Fix: `EMS_GetDayPlan()` lieferte nach dem 0.22.2-Fix ein zehnfach zu
  kleines `price`** — BuildDayPlan()/`simulateDaySlot()` rechnen intern
  seit 0.22.2 korrekt in €/kWh (passend zu EMS' eigenen Preisschwellen),
  aber `GetDayPlan()` gab diesen internen Wert unverändert nach außen
  weiter, statt ihn für den externen Vertrag zurück auf ct/kWh
  umzurechnen (Dashboards Erwartung, konsistent zu Tibber Grid Rewards
  Kurve). Jetzt: `GetDayPlan()` rechnet `price` explizit auf ct/kWh um
  (`× 100`), und der Vertrag liefert zusätzlich ein selbstdokumentierendes
  `priceUnit: "ct/kWh"`-Feld, damit diese Verwechslung nicht unbemerkt
  wieder auftreten kann.

## 0.22.2 (2026-08-20)
- **Kritischer Fix: Einheiten-Verwechslung ct/kWh vs. €/kWh in allen
  Preisvergleichen von `BuildDayPlan()`.** Aufgedeckt durch Dashboards
  neues Tagesplan-Diagramm (0.22.0-Feature): Tooltip zeigte "Strompreis:
  2848.00" statt eines plausiblen zweistelligen Cent-Betrags. Tibber Grid
  Reward bestätigte: `price` wird bewusst in **ct/kWh** geliefert
  (`round($total * 100, 2)`), während JEDE Preisschwelle in EMS selbst
  (`TIB_Threshold_Charge/Export/Discharge`, `VAR_TIB_Feed_Tariff`) als
  **€/kWh**-Dezimalzahl konfiguriert ist (z. B. 0.15, 0.1836). Ohne
  Umrechnung war "Preis (28.48) > Exportschwelle (0.20)" praktisch IMMER
  wahr — das erklärt die Dauer-Export-Planung an vielen echten
  Preis-Slots grundlegender als der zuvor gefixte Fehler mit fehlenden
  Slots (0.21.14, der betraf nur echte Datenlücken). `parsePT15M()`
  rechnet das `price`-Feld jetzt beim Einlesen von ct/kWh auf €/kWh um
  (÷100); das ältere `total`-Fallback-Format bleibt unverändert (war
  schon €/kWh).

## 0.22.1 (2026-08-20)
- **Fix nach Rückmeldung von Tibber Grid Reward: kein Tages-Offset-Parameter,
  falsches Zeitfeld angenommen.** `TIBBERGR_GetPriceCurve()` nimmt KEIN
  zweites Argument (PHP ignoriert überzählige Parameter stillschweigend —
  hätte für "morgen" unbemerkt immer denselben kombinierten Satz geliefert)
  und liefert immer heute+morgen in einer nach `start` sortierten Liste.
  Neue `getTibberCombinedCurveJson()` ruft die Kurve jetzt korrekt ohne
  Offset ab. `parsePT15M()` erkennt jetzt zusätzlich das echte Tibber-
  Grid-Reward-Feldformat (`start` als Unix-Timestamp, `price`) — vorher
  wurde nur `startsAt` (ISO-String) erkannt, das echte Feld hieß aber
  `start`, wäre also nie erfolgreich geparst worden (fataler
  Stille-Fehlschlag: hätte auf die falsche Positions-Fallback-Zuordnung
  zurückfallen können). `startsAt`/`total` bleiben als Fallback für andere
  Preisquellen bestehen.

## 0.22.0 (2026-08-20)
- **Neu: `EMS_GetDayPlan()` — öffentlicher Abruf für Dashboard-Visualisierung**
  (Dietmars Wunsch: "die Planung im Dashboard sehen, zusammen mit SOC und
  Preis"). Liefert heute (ab jetzt) + morgen als eine zusammenhängende Liste,
  je Viertelstunden-Slot mit Uhrzeit, geplanter Aktion, Preis und simuliertem
  SOC. `contractVersion` 1.0. Der native IPS-Wochenplan-Kalender bleibt
  bewusst auf "heute" begrenzt (architektonische Grenze des Kalender-Typs,
  siehe SUITE.md) — diese neue Schnittstelle ist für externe Visualisierung
  gedacht, die zwei Tage als eine durchgehende Linie zeichnen kann.
- Entscheidungslogik aus `BuildDayPlan()` in `simulateDaySlot()` ausgelagert,
  damit dieselbe Logik unverändert für heute UND die neue Morgen-Planung
  gilt (keine Code-Verdopplung, kein Risiko einer Logik-Abweichung zwischen
  beiden Tagen).
- Neue `getPT15MTomorrowJson()`: versucht automatisch
  `TIBBERGR_GetPriceCurve($id, 1)` (Tages-Offset — noch nicht gegen die
  echte Tibber-Grid-Reward-API verifiziert), fällt sonst auf die bereits
  vorhandene manuelle Fallback-Property `VAR_TIB_PT15M_Tomorrow` zurück.
- `parsePT15M()` jetzt mit optionalem Tages-Offset-Parameter, datumsbewusst
  für beliebige Tage (nicht mehr nur "heute").

## 0.21.14 (2026-08-20)
- **Fix: fehlende Preisdaten für einen Slot wurden als "0ct, extrem günstig"
  fehlinterpretiert und lösten fälschlich Export-Entscheidungen aus** —
  Dietmars Live-Fund: der Tagesplan zeigte heute Abend "Einspeisen", obwohl
  dafür überhaupt keine sinnvolle Preisgrundlage bestand. Ursache:
  `parsePT15M()` füllte Slots ohne echte Tibber-Preisangabe mit `0.0` auf —
  ein "Preis" von 0ct ist aber immer günstiger als jede Einspeisevergütung,
  was den entsprechenden Branch in `BuildDayPlan()` auslöste, obwohl gar
  keine Daten vorlagen. `parsePT15M()` liefert jetzt `null` statt `0.0` für
  Slots ohne echte Daten — klar unterscheidbar von einem tatsächlichen
  Preis von null. `BuildDayPlan()` erkennt `null`-Preise jetzt explizit und
  plant für diese Slots "Automatik" statt zu raten. Zusätzlich datumsbewusst
  gemacht: `startsAt`-Einträge für einen anderen Kalendertag als heute
  werden ignoriert, damit eventuell schon mitgelieferte Morgen-Preise nicht
  stillschweigend echte Heute-Preise am selben Uhrzeit-Slot überschreiben.

## 0.21.13 (2026-08-20)
- **Fix: `ApplyChanges()` schlug fehl mit "'Day' außerhalb des gültigen
  Bereichs"** — direkte Folge des 0.21.12-Fixes: `IPS_SetEventScheduleGroup()`
  lief jetzt tatsächlich (vorher durch andere Bugs nie erreicht), deckte
  dabei aber einen weiteren, alten Fehler auf. `$Days` ist laut Symcon-Doku
  eine 7-Bit-Wochentagsmaske (Bit0=Montag…Bit6=Sonntag, gültiger Bereich
  0–127), der Code übergab aber `65535` (16 Bit) für "alle Wochentage" —
  offenbar nie zuvor tatsächlich ausgeführt, sonst wäre das schon früher
  aufgefallen. Korrigiert auf `127` (alle 7 Bits gesetzt).

## 0.21.12 (2026-08-20)
- **Dritter, eigentlicher Grund für den leeren Tagesplan gefunden: die
  Wochenplan-Gruppe wurde nie (neu) angelegt.** Dank der in 0.21.10
  entfernten `@`-Fehlerunterdrückung zeigte der nächste Versuch endlich den
  echten Fehler: "Kann Gruppe mit ID 0 nicht finden", 96/96 Slots
  fehlgeschlagen. Ursache: `IPS_SetEventScheduleGroup($eventId, 0, 65535)`
  stand nur im EINMALIGEN Erstellungszweig von `ensureDayPlanEvent()` — bei
  Dietmars bereits existierendem Event (aus einem früheren, noch fehlerhaften
  Erstellungsversuch, siehe 0.21.1-Fund) wurde die Gruppe nie nachträglich
  angelegt, obwohl die Aktionen daneben schon "bei jedem Aufruf neu setzen"
  liefen. Fix: Gruppen-Definition läuft jetzt genauso wie die Aktionen bei
  JEDEM `ApplyChanges()` — heilt den kaputten Bestands-Event automatisch,
  ohne ihn manuell löschen zu müssen.

## 0.21.11 (2026-08-20)
- **Fix: "📅 Tagesplan neu berechnen"-Button gab keinerlei Rückmeldung** —
  Dietmars Live-Fund: "Ich drück Tagesplan neu berechnen und sehe ......
  keine Rückmeldung!" Gleicher Fehlertyp wie zuvor beim "Jetzt neu
  suchen"-Button (SUITE.md Stolperfalle 12), diesmal aber einfacher gelöst:
  `BuildDayPlan()` liefert jetzt einen menschenlesbaren Ergebnistext zurück
  (✅ Erfolg mit Slot-Anzahl, ⚠️ Teilerfolg mit Fehlschlag-Anzahl, ⛔ kein
  Tagesplan möglich mit Grund, ℹ️ unverändert), und der Button ruft das per
  `echo` auf (Muster: bestehender "Status anzeigen"-Button) — zeigt sofort
  ein Popup mit dem Ergebnis, statt eines `UpdateFormField()`-Umwegs.
  `writeDayPlanEvent()` gibt dafür jetzt `['ok'=>N,'failed'=>N]` zurück.

## 0.21.10 (2026-08-20)
- **Fehlerunterdrückung (`@`) in `writeDayPlanEvent()` entfernt** — Live-Fund:
  Dietmar meldete "Noch habe ich nichts im Tagesplan stehen!" **nachdem**
  der Timer-Fix (0.21.9) bereits griff und `BuildDayPlan()` laut Log
  erfolgreich lief ("Tagesplan neu berechnet (ab Slot 77/96, Preis-Signatur
  d7016597)"). `writeDayPlanEvent()` schreibt das Ergebnis per
  `IPS_SetEventScheduleGroupPoint()` in den sichtbaren Symcon-Wochenplan —
  aber mit `@` davor, das jeden Fehlschlag lautlos verschluckt hätte, egal
  aus welchem Grund (z. B. ein Limit an Schedule-Punkten). Jetzt: kein `@`
  mehr, Erfolge/Fehlschläge werden gezählt und bei mind. einem Fehlschlag
  explizit geloggt (mit den ersten betroffenen Slot-Nummern) — beim
  nächsten Lauf zeigt das Log konkret, WAS schiefläuft, statt weiter zu
  raten.

## 0.21.9 (2026-08-20)
- **Kritischer Fix: Tagesplan blieb bei deaktiviertem EMS dauerhaft leer** —
  Dietmars Live-Fund: "Noch habe ich nichts im Tagesplan stehen!" Ursache lag
  NICHT (mehr) an den PT15M-Preisen oder dem SOC (0.21.2/0.21.3 hatten die
  bereits richtig gefixt), sondern eine Ebene tiefer: `ApplyChanges()`
  schaltete den `EMS_UpdateTimer` komplett ab (`SetTimerInterval(...,  0)`),
  sobald `EMS_Active=false` war — und genau das ist seit dem Tagesplan-
  Umbau (19.08.2026) der Dauerzustand, weil `EMS_Active` bewusst erst nach
  mehrtägiger Beobachtung eingeschaltet werden soll. Ohne laufenden Timer
  wurde `Update()` nie periodisch aufgerufen, also lief auch `BuildDayPlan()`
  nie — trotz des eigenen Code-Kommentars dort, der ausdrücklich sagt, der
  Tagesplan solle UNABHÄNGIG von `EMS_Active` laufen. Der Timer widersprach
  damit der eigenen Absicht des Codes. Fix: Timer läuft jetzt immer
  (Intervall aus `EMS_Interval`), unabhängig von `EMS_Active` — nur die
  Statusmeldung/das Log unterscheiden noch, ob EMS aktiv ist.
- Nebenbei: fehlende Typangaben bei `BuildDayPlan()`/`GetSpecialEvents()`/
  `StartBatteryBoost()` ergänzt (PHPLibrary-Warnung beim letzten Modul-Update
  aufgefallen, rein kosmetisch, keine Funktionsänderung).

## 0.21.8 (2026-08-20)
- **Fix: Status-Zeile im Batteriespeicher-Panel stand nur über Batteriestring
  1, nicht über Batteriestring 2** — Dietmars Nachfrage bei zwei
  Batteriestrings: "wird Bat2 SOC nicht ausgelesen?" Tatsächlich liefert
  InverterHub bei `BAT_String_Count>=2` bereits EINEN über beide Strings
  aggregierten SOC-Wert (`getCurrentBatterySoc()`), Bat2 wird also genauso
  wenig gebraucht wie Bat1 — das stand aber nirgends, die Zeile fehlte
  komplett über Batteriestring 2. Jetzt: dieselbe Statuszeile erscheint über
  BEIDEN Feldern, und der Text bei zwei Strings nennt explizit, dass der
  aggregierte Wert auch für Batteriestring 2 gilt.

## 0.21.7 (2026-08-20)
- **Fix: "🔎 Jetzt neu suchen"-Button aktualisierte die neue Status-Kopfzeile
  nicht im bereits offenen Formular** — Dietmars Live-Fund: Klick auf den
  Button, Partnermodule/Verbund-Gesundheit wurden korrekt aktualisiert, aber
  die Kopfzeile blieb dauerhaft auf "ℹ️ Noch nicht gesucht" stehen. Ursache:
  `GetConfigurationForm()` wird nach einem `RequestAction`-Button NICHT vom
  WebFront automatisch neu ausgeführt — das betroffene `Label` war beim
  ersten Formular-Aufbau eingefroren. Fix: `Discover()` ruft jetzt
  `UpdateFormField()` für Kopfzeile, Verbund-Gesundheit und die
  Partnermodul-Details auf; `StartBatteryBoost()`/`StopBatteryBoost()`
  ebenso für die Boost-Statuszeile (gleicher Fehlertyp, gleich mitgefixt).
  Neue Stolperfalle 12 in SUITE.md — betrifft potenziell jedes Modul mit
  ähnlichen Status-Buttons.

## 0.21.6 (2026-08-20)
- **Status je Feld jetzt auch im "⚡ Wechselrichter & PV"-Panel** (gleiches
  Muster wie Netzmesspunkte/Tibber/Batteriespeicher zuvor). Drei Felder mit
  echtem Automatik-Pfad (Steuerregister via InverterHub `ctl_ems_*`,
  PV-Gesamtleistung, WR-Gesamtleistung).
- **Nebenfund beim Nachsehen:** `VAR_WR_Export_Enable`, `VAR_WR_Export_Limit`,
  `VAR_PV_Day_Energy`, `VAR_PV_MPPT1-3_Power`, `VAR_WR_Temp`,
  `VAR_WR_Temp_Cooler`, `VAR_WR_Diag_Status` werden vom Code **aktuell
  nirgends gelesen** — reine Karteileichen aus einer früheren Version. Die
  Statuszeile sagt das jetzt ehrlich ("🚫 wird nicht ausgewertet") statt sie
  als funktionierenden Fallback zu verkaufen. Ob diese Felder entfernt oder
  endlich verdrahtet werden (v. a. WR-Temperatur/Diagnose wären für
  Monitoring nützlich), ist eine offene Entscheidung — noch nicht getroffen.

## 0.21.5 (2026-08-20)
- **"🔗 Verbund-Status"-Panel neu aufgebaut** — Dietmar gefiel der bisherige
  technische Fließtext ("NRG-Stack Partnermodule: InverterHub=1 MeterHub=1
  ...") sichtbar am wenigsten im Vergleich zu einer knapperen Anzeige, die
  er in einem anderen Modul gesehen hat. Neue Kopfzeile im Muster
  `✅ N Partnermodul-Instanz(en) gefunden (zuletzt HH:MM:SS Uhr).` direkt
  unter dem "🔎 Jetzt neu suchen"-Button (vorher: Button nach dem Text, jetzt
  davor). Die technische Detailaufschlüsselung je Partnermodul-Typ bleibt
  erhalten, wandert aber in ein eingeklapptes Unter-Panel.
  Neues Attribut `LastDiscoveryTs`, bei jeder `Discover()`-Ausführung
  aktualisiert. Neue verbundweite SUITE.md-Konvention "Einheitliche
  Verbund-Status-Kopfzeile" — betrifft jedes Modul mit einer eigenen
  Geräte-/Partnersuche, nicht nur EMS.

## 0.21.4 (2026-08-20)
- **Status-Zeile jetzt wirklich JE FELD statt Pauschalhinweis** — Dietmars
  Präzisierung: nicht "schau oben im Verbund-Status-Panel nach", sondern
  direkt hinter jedem einzelnen betroffenen Auswahlfeld. Netzmesspunkte-
  Panel: der alte Pauschal-Verweistext ist raus, stattdessen bekommt jedes
  Feld (Gesamtleistung, L1-L3, Frequenz, Status) seine eigene, ehrliche
  Zeile — inklusive der Felder, für die es aktuell schlicht keinen
  Automatik-Pfad gibt (das wird jetzt auch so benannt, nicht verschwiegen).
- **Neu: rote Pflichtfeld-Kennzeichnung** für Fälle, in denen ein fehlendes
  Fallback-Feld nicht nur eine Funktion abschaltet, sondern EMS aktiv mit
  einem falschen Wert weiterrechnen lässt. Erste Anwendung: Batteriespeicher-
  Panel, Bat1-SOC-Feld — fehlt sowohl InverterHub-Automatik als auch die
  manuelle Verknüpfung, während `BAT_Active` an ist, erscheint jetzt ein
  roter ⛔-Hinweis statt eines neutralen Hinweistons.
- **Fix: `BuildDayPlan()` las den Batterie-SOC bislang nur manuell**, exakt
  derselbe Fehlertyp wie der PT15M-Preise-Fix in 0.21.2 — ein neues Feature
  (Tagesplan) nutzte die längst vorhandene InverterHub-Discovery nicht. Neue
  gemeinsame Methode `getCurrentBatterySoc()`, `readState()` bleibt
  unverändert (dort war es schon korrekt).
- Neue verbundweite SUITE.md-Konvention erweitert um die vierte Ampel-Stufe
  (⛔ Pflichtfeld) und die JE-FELD-Präzisierung.

## 0.21.3 (2026-08-20)
- **Neu: Status-Zeile über dem manuellen PT15M-Fallback-Feld** — zeigt jetzt
  direkt im Formular (nicht nur im "🔗 Verbund-Status"-Panel weiter oben),
  ob und womit `VAR_TIB_PT15M_Today` gerade automatisch überholt ist:
  ✅ automatisch verbunden (Instanz + Slot-Anzahl), ⚠️ Partnerinstanz
  gefunden aber ohne brauchbare Daten, oder ℹ️ keine Instanz gefunden.
  Auslöser: Dietmars Einwand, dass ein leeres Auswahlfeld allein nicht
  erkennen lässt, ob es überhaupt gebraucht wird. Neue Verbund-Konvention
  in SUITE.md ("Status neben manuellen Fallback-Feldern") — betrifft alle
  Module mit `SelectVariable`-Fallback-Feldern neben einer automatischen
  Partner-Discovery, nicht nur EMS/Tibber.

## 0.21.2 (2026-08-20)
- **Fix: Tagesplan blieb leer, obwohl Tibber-Preisoptimierung und
  Batteriespeicher aktiv waren** — `BuildDayPlan()` las die PT15M-Preise
  bisher ausschließlich aus der manuell zu verknüpfenden Property
  `VAR_TIB_PT15M_Today`, nicht über die automatische Partner-Erkennung, die
  `Discover()` für Tibber Grid Reward längst besitzt (dieselbe Instanz wird
  dort schon für `TIBBERGR_GetTariffConfig`/`GetActiveControls` automatisch
  gefunden). Fund von Dietmar live an seiner Anlage: Formular zeigte bei
  "15-Min-Preise Heute JSON" "Kein(e)" — kein manueller Fix nötig, sondern
  ein handwerklicher Fehler beim Tagesplan-Umbau (0.21.0), der den
  bestehenden Automatik-Anspruch nicht konsequent zu Ende gezogen hat.
  Neue private Methode `getPT15MTodayJson()`: ruft zuerst automatisch
  `TIBBERGR_GetPriceCurve()` von einer über `getTibberGridRewardInstance()`
  gefundenen Instanz ab; nur wenn keine Tibber-Grid-Reward-Instanz installiert
  ist oder der Aufruf nichts Brauchbares liefert, fällt es auf die manuelle
  Property zurück (jetzt klar als Fallback beschriftet, Formular-Hinweistext
  ergänzt). Kein Datenverlust für Installationen, die die manuelle
  Verknüpfung schon nutzen — reine Zusatz-Automatik.

## 0.21.1 (2026-08-19)
- **Fix: `ensureDayPlanEvent()` crashte beim ersten Sync mit
  `ArgumentCountError` für `IPS_SetEventScheduleAction()`** — die Funktion
  braucht 5 Parameter (inkl. `$ScriptContent`), nicht 4 wie ursprünglich
  angenommen (gegen die offizielle Symcon-Doku verifiziert, nicht mehr nur
  aus Erinnerung übernommen). 5. Parameter bleibt bewusst ein leerer String:
  der Tagesplan-Event dient nur der Anzeige, ein echter Skript-Inhalt würde
  IPS' eigenen internen Schedule-Trigger als zweiten, von `optimize()`
  unabhängigen Steuerpfad aktivieren — genau das Problem, das der Tagesplan
  beseitigen soll.
  Nebeneffekt des Fehlers: `ApplyChanges()` stürzte zweimal hintereinander
  ab, jedesmal bevor die Event-ID im Attribut gespeichert wurde → zwei
  verwaiste "EMS Tagesplan (automatisch)"-Events mit Ident-Kollision
  entstanden. Beide über die Symcon-Automatisierungs-Anbindung entfernt.
  `ensureDayPlanEvent()` speichert die ID jetzt sofort nach dem Anlegen
  (vor der Aktions-Konfiguration) und konfiguriert die Aktionen bei jedem
  Aufruf neu (laut Doku idempotent) — repariert einen unvollständig
  angelegten Event beim nächsten `ApplyChanges()` automatisch, statt ein
  Duplikat zu erzeugen.
- **Fix: `catch (Exception $e)` um `ensureDayPlanEvent()`/`BuildDayPlan()`
  auf `catch (Throwable $e)` geändert** — `ArgumentCountError` (wie oben)
  erbt von `Error`, nicht `Exception`, ein reines `Exception`-catch fängt
  solche PHP-Fehlerklassen nicht ab. Ohne diesen Fix hätte der Try/Catch
  den Absturz gar nicht abgefangen (wie tatsächlich geschehen).

## 0.21.0 (2026-08-19)
- **Tagesplan ersetzt die alten SetECOWindow()-Planer (PlanNightCharge/
  PlanNegativePriceExport) UND die rein reaktiven Preis-Branches 2-6 in
  optimize().** Auslöser: Dietmar konnte EMS' Verhalten nicht nachvollziehen
  ("wusste nie, was als nächstes passiert") — Ursache war, dass zwei komplett
  unabhängige Steuerpfade nebeneinander liefen: der live laufende, reaktive
  `optimize()`/`applyDecision()`-Kreislauf (schreibt über InverterHub
  `ctl_ems_*`) und die nur per Formular-Button auslösbaren, nie automatisch
  aufgerufenen `SetECOWindow()`-Funktionen (schrieben direkt in die
  Goodwe-eigenen ECO-Zeitfenster-Register) — letztere hatten gegen den
  laufenden `optimize()`-Kreislauf keine Chance, blieben aber ohne Hinweis
  im Formular stehen.
  Neue `BuildDayPlan()` berechnet bei jeder neuen Tibber-PT15M-Preislieferung
  (automatisch, in `Update()`, nicht mehr nur auf Klick) einen Plan für alle
  96 Viertelstunden des Tages aus PT15M-Preisen + PV-Prognose (PVF) +
  Lastschätzung (LFC-Tagesfenster falls verfügbar, sonst der bisherige feste
  Mittelwert `NEG_Avg_House_Load_W`), simuliert dabei den SOC-Verlauf vor und
  schreibt das Ergebnis sichtbar in einen echten Symcon-Wochenplan ("EMS
  Tagesplan (automatisch)", Kind der EMS-Instanz) — Vorbild: Dietmars eigenes
  Winterskript (IPS-Objekt #55729), das genau so schon rang-basiert die
  günstigsten Viertelstunden auswählte und in einen sichtbaren Wochenplan
  schrieb, nur eben manuell statt automatisch.
  `optimize()` fragt für den aktuellen Slot nur noch beim Plan nach
  (`applyPlanSlot()`) statt live gegen feste Schwellwerte zu entscheiden;
  §14a-Zwangsladefenster, Batterie-Boost, Grid Rewards und die (mangels
  Prognosedaten weiterhin rein reaktive) "Grünste Ladezeit"-Option bleiben
  als Override vor dem Plan bestehen — das sind harte Vorgaben bzw.
  Echtzeit-Befehle, keine Preis-Vermutungen, die man vorausplanen könnte.
  Sicherheitsnetz: weicht der echte SOC sichtbar von der Plan-Annahme ab
  (z. B. Batterie schon leer, obwohl der Plan noch entladen will), fällt
  `applyPlanSlot()` auf `null` zurück und `optimize()` landet in der
  bekannten Automatik-Fallback-Stufe.
- **Neue Export-Regel: Bezugspreis unter Einspeisevergütung → Batterie
  exportiert, Hausverbrauch aus dem Netz.** Dietmars Vorgabe (19.08.2026):
  wenn der aktuelle/geplante Bezugspreis niedriger ist als die
  Einspeisevergütung (typischer Mittags-Fall bei viel PV im Netz), ist der
  gespeicherte Strom mehr wert, wenn er exportiert wird, als wenn er den
  ohnehin schon billigen Netzbezug ersetzt — vorher deckte kein Branch
  diesen Fall ab, die Batterie wurde in dieser Situation für Eigenverbrauch
  entladen statt exportiert.
- **Negativpreis-Handling ist jetzt eine normale Regel im Tagesplan** (immer
  laden bei Preis < 0 EUR/kWh) statt einer separaten, nur manuell
  auslösbaren Funktion (`PlanNegativePriceExport`) — deckt denselben Fall
  ab wie die bisherige "Solarspitzengesetz"-Vorentladung, jetzt konsistent
  Teil derselben Tagesplanung statt eines zweiten Mechanismus.
- Entfernt: `SetECOWindow()`, `PlanNightCharge()`, `PlanNegativePriceExport()`,
  `findNegativePriceWindow()`, `parseForecastForSlots()`, `getPT15MWindow()`,
  `findCheapestBlock()`, Property `NEG_PRICE_Active`, Properties
  `VAR_WR_Time1-4_Start/End/Power/Week` (Goodwe-ECO-Zeitfenster-Register,
  Formular-Buttons "Nacht-Ladefenster planen"/"☀️⚡ Negativpreis-Vorentladung
  planen"). `NEG_Avg_House_Load_W` bleibt als allgemeiner
  Lastprognose-Fallback für den Tagesplan erhalten (umbenannte Rolle, gleiche
  Property-ID — keine Bestandsinstallation verliert dadurch ihren Wert).
- Neuer Button "📅 Tagesplan neu berechnen" (`EMS_BuildDayPlan($id, true)`).

## 0.20.0 (2026-08-15)
- **Branch 3b (PV-Vollernte): Zielwert kommt jetzt aus der PV-Prognose (PVF)
  statt einer festen, manuell eingetragenen Wp-Zahl.** Dietmars berechtigter
  Einwand (13.08.2026): eine feste Nennleistung als Xset-Ziel ist wetterblind
  — an einem bewölkten Tag würde der WR die "fehlende" Sonne trotzdem aus der
  Batterie holen, obwohl die Nennleistung an dem Tag ohnehin unrealistisch ist.
  Neuer Zielwert: aktueller 15-Min-Slot der bereits vorhandenen PVF-p50-
  Prognose (`getPvfSlotsWatt()`, existierte schon für andere Zwecke im Code) —
  wetterabhängig, keine manuelle Eingabe mehr nötig. `PV_Peak_Wp`-Property und
  ihr Formularfeld wieder entfernt (kein Parallelbetrieb zweier Mechanismen).
  Ohne installierte PVF-Instanz bleibt der Branch weiterhin bewusst inaktiv.
  Rest der Logik (Hysterese, SOC-Sicherheitsausstieg) unverändert aus 0.19.0.
  Diese Umstellung wurde diesmal VORHER als schriftlicher Plan mit Dietmar
  abgestimmt, nicht reaktiv nach einem Live-Vorfall nachgezogen.

## 0.19.0 (2026-08-12)
- **Branch 3b (PV-Vollernte bei vollem Akku) neu gebaut**, nach zwei live
  gefundenen Fehlern der ersten Fassung (0.15.0/0.15.1) und InverterHubs
  vollstaendiger Verifikation der offiziellen GoodWe-Registerdokumentation
  (siehe SUITE.md "GoodWe-Steuerregister"):
  1. `AC_EXPORT` (Modus 5) nutzt **Xset**, keine reine Ceiling — der WR erreicht
     den Zielwert notfalls durch Batterie-Entladung. `power=EMS_Max_Power_W`
     war faktisch der Befehl "entlade bis zu 34500W", nicht nur "hebe die
     Kappung auf". Fix: neue Property `PV_Peak_Wp` (reale PV-Spitzenleistung,
     Nutzereingabe, Default 0=inaktiv — keine geratene Anlagengroesse) deckelt
     den Zielwert jetzt auf die tatsaechliche Anlagenleistung, sodass die
     Batterie hoechstens die reale PV-Fehlmenge beisteuern kann.
  2. Die Bedingung oszillierte mit dem Automatik-Fallback (7), weil `$pvW` die
     eigene, gerade gedrosselte Messung war — kurze Unterschreitungen kippten
     sofort zurueck. Fix: echte Hysterese ueber neue Property
     `EXPORT_Min_Dwell_Minutes` (Default 10) + neues Attribut
     `Export3bEnteredTs`; Sicherheitsausstieg bei sichtbarem SOC-Abfall
     ignoriert die Wartezeit bewusst (echter Entladebeweis geht vor Dwell).
  Branch bleibt inaktiv (Verhalten wie vorher: faellt in Fallback 7), bis
  `PV_Peak_Wp` explizit gesetzt wird — kein automatischer Verhaltenswechsel
  fuer bestehende Installationen.

## 0.18.0 (2026-08-09)
- **GoodweET komplett aus EMS entfernt.** War laut SUITE.md bereits seit
  25.07.2026 als "Deprecated, abgeloest durch InverterHub" dokumentiert, EMS'
  eigener Code hat GoodweET trotzdem weiterhin als BEVORZUGTEN WR-Treiber
  behandelt (Dietmar musste am 09.08.2026 nachdruecklich darauf hinweisen).
  Entfernt: `GUID_GOODWEET`, alle `GWET_*`-Intent-Konstanten,
  `gwModeToGwetIntent()`, den `AttachController()`-Aufruf in `ApplyChanges()`,
  die GoodweET-Sonderbehandlung in `Discover()`/`getInverterEntry()`/
  `readState()`/`setGoodweMode()`, sowie alle "GoodweET"-Erwaehnungen in
  form.json und im News-Panel. **InverterHub ist jetzt der einzige
  WR-Treiberpfad** (alte manuelle Variablenverknuepfung bleibt als Fallback
  fuer Anlagen ohne InverterHub bestehen). Kein Verhaltensunterschied fuer
  Dietmars Anlage, da InverterHub dort ohnehin schon der tatsaechlich aktive
  Pfad war -- reine Codebereinigung.

## 0.17.1 (2026-08-09)
- **Vollstaendige Verbund-Ueberwachung** (Dietmar, 04.08.2026: "alle Verbindungen
  die wir generiert haben sehen und ueberwachen"): `GetFederationHealth()` zeigte
  bisher nur PVF zusaetzlich zu den 7 originalen Steuer-Partnern
  (GoodweET/InverterHub/MeterHub/ChargerHub/HeishaMon/Tessie/Tibber). Jetzt
  zusaetzlich `LFC` (LoadForecast) und `StromGedacht` als Health-Eintraege (analog
  PVF, weiterhin bewusst KEIN Teil von `GetPartners()`/`optimize()`). Die
  "installiert-aber-nicht-antwortend"-Erkennung deckte bisher nur 5 der 7
  Steuer-Partner ab (GoodweET und Tibber fehlten) -- jetzt alle 7. Da
  `checkFederationHealthAlarm()` (0.17.0) direkt auf `GetFederationHealth()`
  aufsetzt, gilt die aktive Ausfall-Benachrichtigung damit automatisch fuer
  ALLE hier gelisteten Verbindungen, nicht nur die urspruenglichen.

## 0.17.0 (2026-08-09)
- **Aktive Ausfall-Benachrichtigung** (Dietmar, 04.08.2026): Bisher zeigte
  `GetFederationHealth()` nur eine passive Statuszeile (`EMS_FederationHealth`),
  die niemand aktiv gemeldet bekam. Neu: `checkFederationHealthAlarm()` sendet bei
  Uebergang gesund->ungesund (und einmalig bei Erholung) eine WebFront-Push-
  Benachrichtigung ueber `WFC_PushNotification()`. Neue Property
  `NOTIFY_Visualization_ID` (Default 0 = deaktiviert) laesst den Nutzer die
  Ziel-Kachelseite explizit auswaehlen -- NIE hart hinterlegt, siehe neue
  `GetVisualizationInstances()` (listet installierte "Tile Visualization"-
  Instanzen dynamisch, Grundregel "keine eigene Anlage als Norm"). Der
  Gesundheitscheck+Meldeversuch laeuft jetzt in `Update()` UNABHAENGIG von
  `EMS_Active` (reiner Diagnosevorgang, keine Steuerentscheidung) -- wichtig
  z.B. waehrend einer Notabschaltung wie der aktuellen.
  **Gnadenfrist** (neue Property `NOTIFY_Grace_Minutes`, Default 15): manche
  Partner sind ABSICHTLICH temporaer nicht erreichbar (z.B. Tessie waehrend das
  Auto schlaeft, um API-Kontingent/Autobatterie zu schonen) -- generisch
  gehalten, nicht geraetespezifisch, da das theoretisch jeden Partner betreffen
  kann. Erst nach ununterbrochener Stoerung ueber diese Dauer wird wirklich
  alarmiert, neues Attribut `UnhealthySinceTs` trackt den Beginn.
  **Unverifiziert:** Die exakte Signatur/Icon-Werte von `WFC_PushNotification()`
  sind nicht offiziell bestaetigt (Reflection auf Dietmars Instanz lieferte keine
  brauchbaren Parameterinformationen fuer diese Kernel-Funktion). Vor Verlass auf
  dieses Feature bitte einmal real testen (z.B. gezielt einen Partner kurz
  deaktivieren) und Ergebnis rueckmelden.

## 0.16.0 (2026-08-07)
- **`GetFederationHealth()` zeigt jetzt zusaetzlich die Prognose-Instanz (PVF/PVPrognose)**,
  auf Wunsch von Dietmar/Dashboard fuer die NRGDashboardTopology-Visualisierung ("Verbund-
  Gesundheit"-Sterngrafik zeichnet ausschliesslich das nach, was hier gemeldet wird).
  Bewusst NICHT Teil von `GetPartners()`/`Discover()`/`PartnerCache` — die Prognose bleibt
  kein Steuer-/Situations-Vertrag, der Health-Eintrag ist rein additiv fuer die Anzeige,
  ohne Einfluss auf `optimize()`.
- **WICHTIG, kein Feature — Notabschaltung:** `EMS_Active` wurde manuell deaktiviert
  (03./04.08.2026), nachdem der in 0.15.0 eingefuehrte Branch 3b live zwischen sich selbst
  und dem Automatik-Fallback (7) oszillierte (Sollwert-Sprung `enable=false`↔`enable=true`
  bei jedem knappen Unter-/Ueberschreiten der Ueberschussschwelle) und dabei ueber zwei
  Stunden PV-Spitzenertrag gekostet hat. Branch 3b bleibt bis zu einer Hysterese-/
  Mindestverweildauer-Nachbesserung DEAKTIVIERT WARTEND, nicht in diesem Release behoben.

## 0.15.1 (2026-07-31)
- **Fix an Branch 3b (0.15.0), live getestet bei Dietmar:** Export funktionierte, aber
  nur teilweise ("exportiert mehr, aber nicht die volle mögliche Menge"). Ursache: der
  Export-Zielwert wurde als `min(PV−Hausverbrauch, EMS_Max_Power_W)` berechnet — dabei
  ist die gemessene `$pvW` in genau diesem Moment noch die GEDROSSELTE Leistung (das
  Symptom des Problems selbst), ein daraus abgeleiteter kleiner Zielwert schreibt die
  Drosselung nur fort statt sie aufzuheben. Deckt sich mit dem früheren Live-Befund, dass
  nur ein aggressiver, hoher Zielwert (z. B. 9000 W) den WR zum vollen Hochfahren der
  MPPT-Regelung bewegt. Jetzt: `gw_power_w` ist immer die volle `EMS_Max_Power_W` als
  Ceiling (wie bei Boost/Discharge bereits üblich), kein aus der Ist-Leistung abgeleiteter
  Wert mehr.

## 0.15.0 (2026-07-31)
- **Neuer Branch 3b in `optimize()`: PV-Vollernte bei vollem Akku.** Bisher fiel die
  Entscheidung, sobald `soc >= socTargetDay`, durch alle Branches ohne Treffer und landete
  im Automatik-Fallback (`ctl_ems_enable=false`) — dort kappte der WR die Erzeugung live
  bestätigt (InverterHub, 30./31.07.2026) auf Eigenverbrauchsniveau statt den PV-Überschuss
  zu exportieren, auch bei voller Sonne. Deckt sich mit einem von OpenEMS selbst offen
  markierten Randproblem (siehe SUITE.md "OpenEMS-Architekturanalyse": TODO in
  `ApplyPowerHandler.handleRemoteMode`, "PV curtail" bei SOC=100%+Überschuss, dort ebenfalls
  ungelöst). Neuer Branch prüft `soc >= socTargetDay-Hysterese && (PV-Hausverbrauch) >
  FORECAST_Min_Power_W` und setzt dann explizit `GW_MODE_AC_EXPORT` mit dem berechneten
  Überschusswert, statt auf den autonomen Fallback zu vertrauen.

## 0.14.0 (2026-07-30)
- **Architektur-Fix, live bestätigt in beide Richtungen (InverterHub, 30.07.2026)**:
  `ctl_ems_enable=true` versetzt den WR laut SEMS+-Portal explizit in einen "3rd party EMS"-
  Zustand — die GESAMTE Entscheidungshoheit geht an das externe EMS, auch im Modus
  "Automatik" (der WR wartet dann nur auf einen expliziten Sollwert, harvestet kaum, statt
  autonom zu entscheiden). `ctl_ems_enable=false` gibt die volle autonome Selbstverbrauchs-
  logik zurück. Bisher schrieb `setGoodweMode()` `ctl_ems_enable` immer als `true` fest.
  Jetzt: neuer `$enable`-Parameter, vom Aufrufer gesteuert. Automatik-Fallback (Branch 7) und
  `applyFallback()` setzen jetzt bewusst `enable=false` (WR soll wirklich autonom laufen),
  alle anderen Branches (aktive Preis-/Grün-/Boost-Entscheidungen) bleiben bei `enable=true`.
  Cooldown/Reassert-Logik berücksichtigt jetzt auch Enable-Wechsel, nicht nur Modus-Wechsel.

## 0.13.0 (2026-07-29)
- **Neu: Grünste Ladezeit** (Vorbild evcc, optional, Default AUS) — neue Properties
  `GREEN_Charge_Enabled`/`GREEN_GSI_Threshold` (Default 66, entspricht StromGedachts eigener
  "hoch/grün"-Einstufung). Lädt zusätzlich zur Preislogik aus dem Netz, wenn der aktuelle
  GrünstromIndex (StromGedacht, Corrently-API) über dem Schwellwert liegt. Neue Discovery
  für StromGedacht (`GUID_STROMGEDACHT`, GUID von StromGedacht selbst bestätigt, nicht
  geraten). **Bewusst v1, nur Momentaufnahme** (`SGW_GetState()`), keine mehrstündige
  Vorausschau — StromGedacht hat zwar parallel `SGW_GetForecast()` um source='gsi'/
  'energycharts' erweitert, ein Ausbau auf echte Vorausschau bleibt ein späterer,
  separater Schritt.

## 0.12.0 (2026-07-29)
- **Neu: Batterie-Boost** (Vorbild evcc) — manuell auslösbarer, zeitlich begrenzter Modus
  (`EMS_StartBatteryBoost($id, $minutes)`/`EMS_StopBatteryBoost($id)`, Buttons + Dauer-Feld im
  Formular): Batterie entlädt mit maximaler Leistung, alle Wallboxen werden freigegeben, für
  schnelles Nachladen kurz vor Abfahrt. Bricht automatisch ab, sobald SOC die
  Reserve-Grenze (`BAT_SOC_Min` + `BAT_SOC_Reserve_Backup`) erreicht — kann die Notreserve nicht
  leerfahren. Live-Statuszeile im "Verbund-Status"-Panel zeigt Restzeit.
- **Neu: Lastverteilung/Netzanschluss-Budget** (Vorbild evcc) — neue Property
  `SITE_Max_Grid_Import_W` (Default 0 = deaktiviert). Bei Überschreitung schaltet EMS die
  Wallbox mit der niedrigsten Priorität (`WB{n}_Priority`) ab, bis der projizierte Netzbezug
  wieder unter dem Limit liegt. Reine Enable/Disable-Entscheidung auf EMS-Ebene, ChargerHub
  bleibt für die Strombegrenzung je Ladepunkt zuständig.
- Beide Features standardmäßig folgenlos (Boost inaktiv, Budget deaktiviert) — kein Effekt für
  bestehende Installationen ohne bewusste Aktivierung.

## 0.11.0 (2026-07-29)
- **Neu, Dietmars Wunsch**: "Installiert, aber ohne Antwort" wird jetzt erkannt statt
  stillschweigend zu verschwinden — genau der Fall, der den discoverContract()-Bug (0.10.7)
  zwei Wochen unbemerkt gemacht hat. `Discover()` vergleicht pro Partnermodul die installierten
  Instanz-IDs (`IPS_GetInstanceListByModuleID`) mit den erfolgreich geparsten — Differenz wird
  persistent (`UnresponsiveInstances`) gespeichert. `EMS_GetFederationHealth()` liefert additiv
  `missingCount`/`missing` und nennt betroffene Instanzen jetzt auch im Klartext-Summary.
  **Bewusst NICHT umgesetzt** (zu speculative, Risiko von Fehlalarmen): historische Soll/Ist-
  Zähler-Verfolgung ("gestern 6, heute 4") zur Unterscheidung von absichtlichem Löschen vs.
  echtem Ausfall — dafür bräuchte es zusätzliche Heuristik, die vorerst zurückgestellt wird.

## 0.10.7 (2026-07-29)
- **Kritischer Discovery-Fix, gefunden von Dashboard**: `discoverContract()` prüfte nur
  `is_array($data)`, ohne vorher JSON-Strings zu dekodieren. `MHUB_GetFunctions()` und
  `TESSIE_GetVehicleState()` liefern aber einen JSON-String zurück, kein PHP-Array — dadurch
  wurden ALLE MeterHub-Instanzen (6 bei Dietmar) und alle Tessie-Fahrzeuge STILLSCHWEIGEND aus
  `GetPartners()`/`GetFederationHealth()` ausgeschlossen, ohne Fehler/Log. Fix: `is_string($data)`
  → `json_decode()` vor dem `is_array()`-Check. Betrifft nur `discoverContract()`, keine
  Vertragsänderung.

## 0.10.6 (2026-07-29)
- **Korrektur zu 0.10.5**: "NRGEMS" als Alias wieder entfernt — Dietmars Anweisung war, dass
  alte Bezeichnungen entfallen sollen, nicht nur ergänzt werden. Aliase jetzt nur noch
  "NRG-Stack EMS" und "Energy Management System".

## 0.10.5 (2026-07-29)
- **Namenskonvention**: Sichtbarer Anzeigename jetzt "NRG-Stack EMS" statt "NRGEMS"
  (`library.json`→`name`, `module.json`→`aliases`). GUID, `module.json`→`name` (PHP-
  Klassenname), Idents und Präfixe unverändert — bestehende Instanzen und künftige
  Git-Updates bleiben unberührt, nur der in Modulverwaltung/Instanzsuche sichtbare Name
  ändert sich. Alte Bezeichnung "NRGEMS" bleibt zusätzlich als Alias erhalten.

## 0.10.4 (2026-07-27)
- **Nachbesserung, direktes Nutzer-Feedback**: Der Hilfetext bei "Netzmesspunkte" verwies auf
  "NRG-Stack Partnermodule"/"Verbund-Gesundheit" "oben im Formular" — die standen aber nur als
  Statusvariablen im Objektbaum, nicht im Formular selbst. Neues, immer aufgeklapptes Panel
  "🔗 Verbund-Status" ganz oben im Formular zeigt beide Werte jetzt tatsächlich live an, inkl.
  Button "Jetzt neu suchen". Hilfetext entsprechend korrigiert.

## 0.10.3 (2026-07-27)
- **Weitere Nachbesserung, systematisch gesucht statt nur an gemeldeten Stellen**: 20 Feld-
  Captions im "Wechselrichter & PV"-Panel enthielten Dietmars eigene, konkrete Symcon-
  Variablen-IDs (z. B. "Startzeit 1 (ID 53840)") — Entwicklungs-Reste von seiner eigenen
  Anlage, für jeden anderen Nutzer bedeutungslos bzw. verwirrend. Entfernt (Einheiten wie
  "(W)" dabei erhalten). Gehört zum selben Muster wie der GoodWe/PAC2200-Fund: eigene,
  konkrete Anlagendetails dürfen nicht als allgemeingültig im Formular stehen bleiben.

## 0.10.2 (2026-07-27)
- **Nachbesserung nach direktem Nutzer-Feedback zu 0.10.1**: Der neue PopupButton-Hilfetext
  erklärte Modul-Historie ("ursprüngliche Bezeichnung...") statt die eigentliche Nutzerfrage zu
  beantworten. Jetzt direkt: "Sind meine Zähler verbunden?" verweist auf die Statusvariablen
  "NRG-Stack Partnermodule"/"Verbund-Gesundheit". Zusätzlich denselben Fehler beim
  "Sekundärmessung: Siemens PAC2200"-Abschnitt behoben — auch das war ein Spezialprodukt fest
  im Formular verankert, obwohl es ein rein optionaler, herstellerunabhängiger Kontroll-Zähler
  ist. Alle Feld-Captions von "PAC2200 ..." auf "Kontroll-Zähler ..." umbenannt (Property-Namen
  intern unverändert, kein Breaking Change).

## 0.10.1 (2026-07-27)
- **UX-Fix, direktes Nutzer-Feedback**: Die Formular-Panels "Netzmesspunkte", "Wechselrichter &
  PV", "Batteriespeicher" (Fallback-Teil), "Wallboxen" (Fallback-Teil) und "Wärmepumpe" waren
  reine Relikte aus der Zeit vor der automatischen NRG-Stack-Discovery — ohne jede Erklärung,
  ob/wann sie überhaupt ausgefüllt werden müssen. "Primärmessung: Goodwe SmartMeter (Pflicht)"
  war zudem sachlich falsch: nicht jeder Nutzer hat einen GoodWe-Wechselrichter (SMA, Fronius
  etc. laufen über MeterHub genauso). Alle betroffenen Panels haben jetzt: (a) Panel-Titel mit
  "(optionaler manueller Fallback)"-Hinweis, (b) einen erklärenden Label-Text direkt am
  Panel-/Abschnittsanfang, (c) bei "Netzmesspunkte" zusätzlich einen PopupButton mit
  ausführlicherer Erklärung (erste Anwendung der eigenen PopupButton-Konvention im EMS-Formular
  selbst).

## 0.10.0 (2026-07-27)
- **Architektur-Umbau**: `applyDecision()` schreibt den GoodWe-Modus/-Leistung jetzt JEDEN
  Zyklus neu (kontinuierliche Regelschleife nach OpenEMS-Vorbild — `ControllerEssBalancing`/
  `TimeOfUseTariff` rechnen und schreiben ebenfalls laufend, nicht nur bei Entscheidungs-
  änderung). Grund: GoodWes eigener "SMART"-Modus fällt bei einem nur einmalig geschriebenen
  Wert auf den Sentinel 255 zurück (siehe "GoodWe-Steuerregister" in SUITE.md). Der Cooldown
  (`OPT_Cooldown_Sec`) gilt weiterhin, aber nur noch für den eigentlichen Moduswechsel (verhindert
  Thrashing); während der Cooldown-Phase wird der zuletzt aktive Modus trotzdem laufend
  reasserted, statt komplett zu pausieren.

## 0.9.0 (2026-07-27)
- **Neu**: Dynamisches, energiebasiertes Batterie-Tagesziel. Dietmars Vorgabe: "die enthaltene
  Energie muss nur bis zum nächsten Tag reichen, nicht ein fester SOC%". Neue Methode
  `getDynamicSocTargetDay()` nutzt `LFC_GetEnergyWindow()` (Prognose) für den erwarteten
  Verbrauch bis zum PV-Produktionsstart morgen (`getPvStartTomorrowTs()`, Schwellwert-Formel
  von Prognose übernommen: 10W absolut oder 2% des Tagesmaximums), rechnet das in einen
  benötigten SOC% + Sicherheitsmarge um (neue Property `BAT_SOC_Safety_Margin_Pct`, Default
  10%). Fällt auf den bisherigen statischen `BAT_SOC_Target_Day`-Wert zurück, wenn LFC/PVF
  fehlen oder `coverage < 1.0` ist. Per Property `BAT_SOC_Dynamic_Target` (Default an)
  abschaltbar. Neue Konstante `GUID_LFC`.

## 0.8.0 (2026-07-27)
- **Nachgeholt, dringend**: `EMS_GetSpecialEvents($fromTs, $toTs)` — Vertrag existierte noch
  nicht im Code, obwohl Prognose (LFC) ihn laut eigener Aussage bereits seit Build 51 "blind"
  konsumiert hatte. Liefert jetzt Zeitfenster externer Regeleingriffe (aktuell: Tibber Grid
  Rewards), die lernende Module vom Training ausschließen sollten. Neue private Methode
  `trackSpecialEvents()` pflegt ein persistentes, auf 500 Einträge gedeckeltes Ereignisprotokoll
  (`SpecialEventsLog`-Attribut), aufgerufen bei jedem `Update()`-Zyklus. `contractVersion` 1.0.

## 0.7.3 (2026-07-27)
- **Neu**: `EMS_GetControlledVariables()` — liefert alle Variablen-IDs, die EMS aktuell aktiv
  steuert (WR-Steuervariablen `ctl_work_mode`/`ctl_ems_mode`/`ctl_ems_enable`/`ctl_ems_power`,
  Wallbox-Freigaben). Gedacht für externe Kollisions-Erkennung, konkret angefragt von
  StromGedacht: deren Wenn→Dann-Automations-Engine könnte sonst versehentlich dieselbe
  Stellgröße wie EMS schreiben ("Ein Regler pro Stellgröße"-Regel, bisher nur Nutzerdisziplin,
  keine technische Absicherung). Rein lesend, löst keine Discovery aus.

## 0.7.2 (2026-07-27)
- **Echter Root-Cause-Fix** (Revision von 0.7.1): InverterHub hat anhand der offiziellen
  GoodWe-Modbus-Registerdoku (ARM205-HV Tab. 8-16) geklärt, dass `ctl_ems_power` in
  `GW_MODE_CHARGE_PV` eine Netzbezugs-OBERGRENZE ist (Batterie-Ziel = ctl_ems_power(Netz) +
  PV), kein additiver Zusatzwert — dokumentiertes, beabsichtigtes WR-Verhalten. Der
  tatsächliche Fehler lag in unserem eigenen `setGoodweMode()`: `if ($powerW > 0)` schrieb
  `ctl_ems_power` nie explizit auf 0, sodass ein alter Wert (bei Dietmar: 3000) stehen blieb
  und ungewollten Netzbezug verursachte (3,4kW bei 5,1kW PV). Fix: `ctl_ems_power` wird jetzt
  IMMER geschrieben, auch 0. `optimize()`-Branch 3 nutzt wieder `GW_MODE_CHARGE_PV` (der
  AUTO-Workaround aus 0.7.1 war nur eine Notlösung, keine echte Ursachenbehebung).

## 0.7.1 (2026-07-27)
- **Kritischer Fix**: `optimize()`-Branch 3 ("PV-Ueberschuss → Eigenverbrauch") nutzte
  `GW_MODE_CHARGE_PV`, das bei Dietmars WR trotz Namens NICHT nur aus PV-Ueberschuss laedt —
  live beobachtet: 3,4kW Netzbezug bei 5,1kW PV, obwohl EMS keine Leistungsvorgabe gemacht
  hatte (`gw_power_w=0`). Nach manueller Umschaltung auf `GW_MODE_AUTO` fiel der Netzbezug
  auf 0W. Branch 3 nutzt jetzt bewusst `GW_MODE_AUTO` statt `GW_MODE_CHARGE_PV`, bis
  InverterHub/GoodweET das CHARGE_PV-Verhalten korrigiert haben.

## 0.7.0 (2026-07-27)
- **Neu**: `EMS_GetFederationHealth()` — verbundweite Statusaggregation über alle bei
  `Discover()` gefundenen Partnerinstanzen (InverterHub/GoodweET, MeterHub, ChargerHub,
  HeishaMon, Tessie, Tibber). Neue Statusvariable `EMS_FederationHealth` fasst zusammen,
  wie viele Partnerinstanzen gesund sind (`InstanceStatus === 102`) und benennt auffällige
  Instanzen mit Klartext-Status. Wird automatisch bei jedem `Discover()`-Lauf mit
  aktualisiert. Hintergrund: ein manueller Rundruf über alle 12 Verbund-Sessions am
  27.07.2026 hat gezeigt, dass es bisher keine zentrale Übersicht gab, ob der gesamte
  Stack läuft — jedes Modul hatte höchstens seine eigene Ampel.

## 0.6.2 (2026-07-25)
- **Kritischer Fix**: `setGoodweMode()` setzte nie `ctl_ems_enable` (Register 47505, der
  EMS-Hauptschalter am WR) — der Goodwe ignorierte dadurch jede `ctl_ems_mode`/
  `ctl_ems_power`-Vorgabe und fuhr weiter seine eigene Selbstverbrauchs-Logik. Live
  beobachtet: EMS entschied korrekt "Tibber teuer → Entladen", `ctl_ems_mode` wurde auch
  korrekt auf 3 gesetzt, Batterie entlud aber nicht, weil `ctl_ems_enable=false` blieb.
  Jetzt wird `ctl_ems_enable=true` vor jedem Moduswechsel mitgesendet.

## 0.6.1 (2026-07-25)
- **Kritischer Fix Schreibpfad**: `IHUB_RequestAction`/`CHUB_RequestAction` existieren als
  Funktionsnamen nie — `RequestAction()` ist ein IPSModule-Kernel-Methodenname, IP-Symcon
  generiert dafür KEINE `Prefix_RequestAction()`-Wrapperfunktion (anders als bei normalen
  öffentlichen Methoden). Der korrekte Einstiegspunkt ist `IPS_RequestAction($InstanceID,
  $Ident, $Value)`. Betraf `setGoodweMode()` und `controlWallboxViaChargerHub()` — durch die
  defensiven `function_exists()`-Prüfungen bisher ohne Fehler, aber auch ohne jede echte
  Wirkung: Seit der heutigen Migration wurde nie tatsächlich geschrieben. Fund/Bestätigung:
  ChargerHub-Session, 25.07.2026 (Commit 7b86242, zusätzlich fehlende
  `VariableAction`-Verknüpfung dort behoben).

## 0.6.0 (2026-07-25)
- **PV-Prognose-Migration**: `parseForecastNextHours()`/`parseForecastForSlots()` (genutzt
  von `optimize()` und `PlanNegativePriceExport()`) sprechen jetzt bevorzugt die echte
  PV-Erzeugungsprognose-Instanz direkt an (`PVF_GetForecast($instanceID, $offset)`,
  96 Slots à 15 Min, p50-Median in Watt, offset 0=heute/1=morgen) statt die alte, nie
  befüllte `VAR_FC_JSON`-Property zu lesen — diese bleibt nur noch als Fallback. Neuer
  Helper `getPvfSlotsWatt()` baut daraus ein durchgehendes 192-Slot-Array (heute+morgen),
  passend zum bestehenden Tibber-PT15M-Slot-Schema.

## 0.5.1 (2026-07-25)
- **Einheiten-Fix Tibber-Preis**: `TIB_Threshold_*`/`OPT_Hysteresis_Price`/`tib_feed`-Fallback
  waren fälschlich in ct/kWh kalibriert, obwohl TibberGridRewards' `CurrentPrice`-Vertrag
  EUR/kWh liefert (z.B. `0.1743`). Umgestellt auf EUR/kWh durchgängig (Property-Defaults,
  Formular-Beschriftungen/Spinner-Ranges, Statusvariable `EMS_TibberPrice`). Ohne diesen Fix
  hätte jede Preisschwelle nie gegriffen (0.17 < 15 "ct" wäre immer als günstig gegolten).

## 0.5.0 (2026-07-25)
- **Steuer-Migration Phase 1**: `setGoodweMode()`/`readState()` sprechen jetzt bevorzugt den
  tatsächlich live laufenden WR-Treiber **GoodweET** (`GWET_GetChannels`/`GWET_ApplySetpoint`)
  direkt an, statt über die alten, nie befüllten `SelectVariable`-Felder ("Wechselrichter & PV")
  zu gehen — das war der Grund, warum das EMS trotz aktivierter Anlage nie wirklich mit WR1
  verbunden war. `controlWallbox()` ebenso auf ChargerHub (`CHUB_RequestAction`, nur wenn
  `managedBy` dem EMS die Hoheit einräumt — Situation A). InverterHub (`IHUB_*`) bleibt als
  zweite Priorität im Code, falls auf einem anderen Verbund-System InverterHub statt GoodweET
  installiert ist — auf diesem System laufen aktuell keine InverterHub-Instanzen. Die alten
  manuellen Felder bleiben nur noch als letzter Fallback bestehen.
- `Update()` ruft vor jedem Zyklus `Discover()` auf, damit der PartnerCache nie veraltet ist;
  `ApplyChanges()` registriert die EMS-Instanz bei Aktivierung per `GWET_AttachController()`
  als steuernde Instanz (reine Buchführung, GoodweET erzwingt das nicht).
- Bekannte Lücke, bewusst nicht Teil dieser Version: die Goodwe-ECO-Zeitfenster
  (`SetECOWindow`/`PlanNightCharge`/`PlanNegativePriceExport`) haben weder in GoodweET noch in
  InverterHub einen Schreibkanal (nur `ctl_ems_mode`/`ApplySetpoint`-Intents, keine
  Zeitfenster-Register) — dafür bleibt vorerst nur die alte manuelle Variablenverknüpfung
  nutzbar, bis GoodweET das ergänzt.

## 0.4.1 (2026-07-25)
- Eigene Formular-Konvention nachgerüstet (war bisher übersehen worden, obwohl an alle
  anderen Module verteilt): "🆕 Was ist Neu" (aufgeklappt, pro Version dismissible),
  "📖 Dokumentation & Hilfe" (eingeklappt, mit Versionsnummer), Symcon-Forum-Hinweis
  (dismissible, Platzhalter bis der Forum-Post existiert).

## 0.4.0 (2026-07-25)
- `EMS_PlanNegativePriceExport()`: Solarspitzengesetz-Strategie — schafft vor einem
  erwarteten Negativpreis-Fenster (aus der Tibber-PT15M-Preiskurve) rechtzeitig genug
  freien Speicherplatz (Vorentladung), damit der PV-Überschuss während der Negativpreis-
  Phase in den Speicher statt unvergütet ins Netz geht. Nutzt die PV-Prognose (VAR_FC_JSON)
  für die Bedarfsschätzung im Fenster. Spiegelbild zu `PlanNightCharge()`.
- Neues Formular-Panel "☀️⚡ Solarspitzengesetz" + Button zum manuellen Auslösen.
- Bewusste Vereinfachung (dokumentiert im Code): Hausverbrauch im Fenster wird über einen
  konfigurierbaren Mittelwert geschätzt, keine echte Lastprognose-Anbindung (LFC) — als
  nächster Ausbauschritt vorgemerkt.

## 0.3.0 (2026-07-25)
- `EMS_GetSituation()`: wertet die Discovery-Daten nach der Situation-A/B-Prioritäts-
  hierarchie aus (EMS besitzt Schreibkanal vs. externer Akteur besitzt Schreibkanal) —
  InverterHub (`controlAuthority`), ChargerHub (`managedBy`), HeishaMon (bewusst nie
  schreibend), Tessie (Grid-Rewards-Erkennung), Tibber (`GetActiveControls`).
- Neue Statusvariable `EMS_Situation` (Kurzfassung, wird bei jedem `Discover()`
  automatisch mit aktualisiert).

## 0.2.0 (2026-07-25)
- Erste NRG-Stack-Discovery-Schicht: `EMS_Discover()` findet automatisch installierte
  Partnermodule (InverterHub, MeterHub, ChargerHub, HeishaMon, Tessie, TibberGridRewards)
  und ruft deren `*_GetFunctions`/`GetVehicleState`/`GetTariffConfig`/`GetActiveControls`-
  Verträge ab — additiv, ersetzt die bestehende manuelle Variablenverknüpfung noch nicht.
  Ergebnis liegt im Attribut `PartnerCache` und in der neuen Statusvariable `EMS_Partners`.
- Neuer Button "🔎 NRG-Stack-Partnermodule suchen" im Formular.
- `library.json` auf die 8 store-zulässigen Felder bereinigt (description/modules entfernt).

## 0.1
- Erstes lauffähiges Gerüst gegen Goodwe/IPSCoyote-GO-eCharger/da8ter-TibberV2 (nicht Teil
  des NRG-Stack-Vertragssystems, historischer Stand vor der Verbund-Konsolidierung).
