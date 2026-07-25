# Changelog

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
