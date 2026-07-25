# Changelog

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
