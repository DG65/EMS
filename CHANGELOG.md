# Changelog

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
