# Changelog

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
