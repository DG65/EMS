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
  `ctl_ems_mode` fällt auf 255 zurück → Sollwert periodisch neu schreiben.

## Repo-Struktur

- `EMS/` — das Symcon-Modul (module.php, form.json, module.json)
- `SUITE.md` — Verbund-Manifest (Quelle, wird in alle Repos synchronisiert)
- `.github/workflows/check-style.yml` — `php -l` über alle PHP-Dateien
- `.github/workflows/sync-suite.yml` — SUITE.md-Verteilung in die Modul-Repos

## Branch-Modell

Arbeitsbranch ist `ems-integration` (verbundweit identischer Name) — solange
die EMS-Integrationsphase läuft, geht ALLES dorthin, auch scheinbar sichere
Fixes. Merge nach `beta`/`main` erst nach Bewährung an Dietmars Live-Anlage.

## Bekannte Altlasten

- `README.md` hinkt teils hinter SUITE.md her (Lizenzabschnitt nennt noch MIT
  — verbindlich ist PolyForm Noncommercial 1.0.0 per SUITE.md; Install-URL
  enthält einen Platzhalter; Geräteliste nennt noch das go-e-Fremdmodul statt
  ChargerHub). Bei Gelegenheit angleichen; bei Widerspruch gilt SUITE.md.

## Arbeitsregeln (kondensiert, Details in SUITE.md)

1. Nutzersichtbares deutsch, `Translate()`-Quellstrings englisch.
2. Keine eigene Anlage als Norm annehmen — Neuinstallations-Simulation vor
   jedem beta→main-Wechsel (Punkt 12 der Store-Review-Checkliste).
3. Öffentliche `PREFIX_`-Funktionen: neuer Parameter = Breaking Change, auch
   mit PHP-Default (feste Arität des Kernel-Wrappers).
4. Push auf GitHub wirkt nicht automatisch — Dietmar zieht Modulstände manuell
   über die Modulverwaltung nach; nie selbst per API forcieren.
5. Eigene NRG-Stack-Module nie zusätzlich über den Symcon Store buchen.
