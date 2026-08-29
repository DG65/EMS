# EMS — Hinweise für die Arbeit an diesem Repository

## Zuerst lesen: SUITE.md (liegt hier im Repo)

Dieses Repo ist die **Koordinationszentrale des NRG-Stack**. `SUITE.md` im
Repo-Root ist das verbindliche Verbund-Manifest: Zielbild, Vertragsmodell
(`contractVersion`), `NRG.*`-Profile, Formular-Konvention, Store-Review-
Checkliste, IPS-Stolperfallen, GoodWe-Register, Manifest-Tabelle aller
Modulstände. **Jede Session hier liest SUITE.md, bevor sie etwas ändert.**

Änderungen an Verbund-Konventionen werden AUSSCHLIESSLICH in SUITE.md hier
gepflegt. Der Workflow `.github/workflows/sync-suite.yml` verteilt bei jedem
Push (Branches `ems-integration`/`main`) automatisch eine Read-only-Kopie in
alle Modul-Repos — die Kopien dort nie direkt editieren.

## Rolle des EMS-Moduls

Das EMS ist die **einzige koordinierende Instanz** des Verbunds:

- Konsumiert alle Datenverträge (`MHUB_/CHUB_/IHUB_/HEISHA_/WPHUB_GetFunctions`,
  `TIBBERGR_GetPriceCurve/GetTariffConfig`, `SGW_GetState`, `SBH_GetState`,
  `PVF_/LFC_Get*`, `TESSIE_GetVehicleState`) — Suchrichtung immer vom EMS aus,
  nie umgekehrt. Kein Modul darf ein anderes voraussetzen.
- Einziger zulässiger Konsument der Steuer-Verträge; „ein Regler pro
  Stellgröße". Prioritätshierarchie:
  `Gesetz/Netzbetreiber > Vermarkter > EMS-Optimierung > Komfort`.
- Wärmepumpe: nur Monitoring, keine aktive Steuerung (EEPROM-Schonung;
  Sentinel -5 bei `SetZ1HeatRequestTemperature` gehört HeishaMons Taktschutz).
- GoodWe-Ansteuerung via InverterHub-Idents
  (`IPS_RequestAction($InstanceID, 'ctl_*', $Value)` — Instanz-ID, nicht
  Variablen-ID!). Xmax-/Xset-Semantik der `ctl_ems_mode`-Tabelle in SUITE.md
  beachten: Xset-Modi (4/5/9/10/11/12) sind aktive Ziele, die die Batterie
  anzapfen können — nie `maxW` als Xset übergeben (Branch-3b-Vorfall).
  `ctl_ems_mode` fällt bei `ctl_ems_enable=true` ohne Heartbeat (< 60-70s)
  auf 255 zurück (siehe SUITE.md GoodWe-Steuerregister, 29.08.2026, zweifach
  korrigiert). **Bei `ctl_ems_enable=false` hält ein einmal gesendeter Modus
  dagegen dauerhaft, ohne Reassert** — für einmalige/seltene Befehle
  `enable=false` bevorzugen, `enable=true` nur mit laufendem Reassert-Zyklus
  (EMS' eigener 30s-`applyDecision()`-Zyklus reicht dafür). **Wichtig:**
  `enable=false` allein fällt NICHT von selbst auf Automatik zurück, es
  friert nur den zuletzt kommandierten Modus ein — für echte Automatik
  immer `enable=false` + `mode=1` + `power=0` zusammen senden.

## Repo-Struktur

- `EMS/` — das Symcon-Modul (module.php, form.json, module.json)
- `SUITE.md` — Verbund-Manifest (Quelle, wird in alle Repos synchronisiert)
- `.github/workflows/check-style.yml` — `php -l` über alle PHP-Dateien
- `.github/workflows/sync-suite.yml` — SUITE.md-Verteilung in die Modul-Repos

## Branch-Modell

Arbeitsbranch ist `ems-integration` (verbundweit identischer Name) — solange
die EMS-Integrationsphase läuft, geht ALLES dorthin, auch scheinbar sichere
Fixes. Merge nach `beta`/`main` erst nach Bewährung an Dietmars Live-Anlage.

## Aktueller Stand: Tagesplan (19.08.2026, ersetzt SetECOWindow-Planer)

Auslöser: Dietmar konnte EMS' Verhalten nicht nachvollziehen ("wusste nie,
was als nächstes passiert", musste EMS nach jeder Aktivierung wieder
abschalten). Ursache: zwei unabhängige Steuerpfade liefen nebeneinander —
der live laufende, reaktive `optimize()`/`applyDecision()`-Kreislauf (schreibt
über InverterHub `ctl_ems_*`) und die nur per Formular-Button auslösbaren,
nie automatisch aufgerufenen `SetECOWindow()`-Funktionen (`PlanNightCharge`/
`PlanNegativePriceExport`, schrieben direkt in die Goodwe-eigenen
ECO-Zeitfenster-Register) — letztere hatten gegen den laufenden `optimize()`
keine Chance, standen aber ohne Hinweis im Formular.

**Was jetzt anders ist:**
- `BuildDayPlan()` (neu) berechnet bei jeder neuen Tibber-PT15M-Preislieferung
  automatisch (in `Update()`, nicht mehr nur auf Klick) einen Plan für alle
  96 Viertelstunden des Tages aus PT15M-Preisen + PV-Prognose (PVF) +
  Lastschätzung, simuliert dabei den SOC-Verlauf vor und schreibt das Ergebnis
  sichtbar in einen echten Symcon-Wochenplan ("EMS Tagesplan (automatisch)",
  Kind der EMS-Instanz) — Vorbild: Dietmars eigenes Winterskript (IPS-Objekt
  #55729), das genau so schon rang-basiert die günstigsten Viertelstunden
  auswählte und in einen sichtbaren Wochenplan schrieb, nur manuell statt
  automatisch.
- `optimize()` fragt für den aktuellen Slot nur noch beim Plan nach
  (`applyPlanSlot()`) statt live gegen feste Schwellwerte zu entscheiden.
  §14a-Zwangsladefenster, Batterie-Boost, Grid Rewards und die (mangels
  Prognosedaten weiterhin reaktive) "Grünste Ladezeit"-Option bleiben als
  Override vor dem Plan bestehen — harte Vorgaben/Echtzeit-Befehle, keine
  Preis-Vermutungen.
- Neue Regel: Bezugspreis < Einspeisevergütung → Batterie exportiert statt
  Eigenverbrauch, Hausverbrauch aus dem Netz (Dietmars Vorgabe, deckt den
  Mittags-Fall bei niedrigen Spotpreisen ab).
- Entfernt: `SetECOWindow()`, `PlanNightCharge()`, `PlanNegativePriceExport()`,
  Property `NEG_PRICE_Active`, `VAR_WR_Time1-4_*`-Properties. `NEG_Avg_House_
  Load_W` bleibt als Lastprognose-Fallback erhalten (umbenannte Rolle, gleiche
  Property-ID).

**Live-Fund beim ersten Sync (behoben, Commit `c1a7c39`):**
`IPS_SetEventScheduleAction()` braucht 5 Parameter (inkl. `$ScriptContent`),
nicht 4 — gegen die offizielle Symcon-Doku verifiziert, nicht mehr nur aus
Erinnerung übernehmen. Der 5. Parameter bleibt bewusst leer: dieser Event
dient nur der Anzeige, echter Skript-Inhalt würde IPS' eigenen internen
Schedule-Trigger als zweiten, von `optimize()` unabhängigen Steuerpfad
aktivieren — genau das Problem, das der Tagesplan beseitigen soll. Nebenfund:
`catch (Exception $e)` fängt `ArgumentCountError` NICHT ab (erbt von `Error`),
jetzt `catch (Throwable $e)` um `ensureDayPlanEvent()`/`BuildDayPlan()`.

**Status (20.08.2026):** Alle Commits sind auf `origin/ems-integration`
gepusht, am Live-System aber **noch nicht mehrtägig verifiziert**.

**Live-Fund 20.08.2026:** Der Tagesplan blieb auf Dietmars Anlage leer,
obwohl Tibber-Preisoptimierung + Batteriespeicher aktiv waren — Ursache:
`BuildDayPlan()` las die PT15M-Preise nur ueber eine manuelle Property statt
ueber die laengst vorhandene automatische Tibber-Grid-Reward-Discovery.
Gefixt in 0.21.2 (`getPT15MTodayJson()`, siehe CHANGELOG + SUITE.md-Muster
"neue Konsumenten-Features muessen den bestehenden Discovery-Mechanismus
nutzen"). Vor einem Merge nach `beta`: `EMS_Active` weiterhin aus lassen,
den Tagesplan über "📅 Tagesplan neu berechnen" ein paar Tage nur
beobachten (jetzt mit tatsächlich befülltem Plan), dann erst aktivieren.

## Arbeitsregeln (kondensiert, Details in SUITE.md)

1. Nutzersichtbares deutsch, `Translate()`-Quellstrings englisch.
2. Keine eigene Anlage als Norm annehmen — Neuinstallations-Simulation vor
   jedem beta→main-Wechsel (Punkt 12 der Store-Review-Checkliste).
3. Öffentliche `PREFIX_`-Funktionen: neuer Parameter = Breaking Change, auch
   mit PHP-Default (feste Arität des Kernel-Wrappers).
4. Push auf GitHub wirkt nicht automatisch — Dietmar zieht Modulstände manuell
   über die Modulverwaltung nach; nie selbst per API forcieren.
5. Eigene NRG-Stack-Module nie zusätzlich über den Symcon Store buchen.
