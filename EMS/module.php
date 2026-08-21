<?php

// ============================================================
//  EMS — Energy Management System für IP-Symcon
//  Autor   : DG65
//  Version : 0.1
//  GUID    : {31C61A7B-28C4-4F97-9651-1A64B3469E3C}
// ============================================================

// Goodwe EMS Leistungsmodi (Register 47511)
define('GW_MODE_STOP',        0);
define('GW_MODE_AUTO',        1);
define('GW_MODE_CHARGE_PV',   2);
define('GW_MODE_DISCHARGE',   3);
define('GW_MODE_AC_IMPORT',   4);
define('GW_MODE_AC_EXPORT',   5);
define('GW_MODE_ECO',         6);
define('GW_MODE_ISLAND',      7);
define('GW_MODE_STANDBY',     8);
define('GW_MODE_BUY',         9);
define('GW_MODE_SELL',       10);
define('GW_MODE_BAT_CHARGE', 11);
define('GW_MODE_BAT_DISCH',  12);

// Interne EMS Betriebsmodi
define('EMS_OP_AUTO',         0);
define('EMS_OP_PV_SELFUSE',   1);
define('EMS_OP_NET_CHARGE',   2);
define('EMS_OP_DISCHARGE',    3);
define('EMS_OP_STANDBY',      4);
define('EMS_OP_EXPORT',       5);
define('EMS_OP_BACKUP',       6);
define('EMS_OP_GRIDREWARDS',  7);

// Logging-Level
define('EMS_LOG_OFF',         0);
define('EMS_LOG_BASIC',       1);
define('EMS_LOG_VERBOSE',     2);

// Formular-Konvention (siehe EMS/SUITE.md "Einheitliche Formular-Optik"):
// Was-ist-Neu-Panel ist versionsscharf dismissible, Referenzmuster InverterHub.
define('EMS_NEWS_VERSION', '0.6.0');

// NRG-Stack Partnermodul-GUIDs (fuer automatische Discovery, siehe discoverPartners())
define('GUID_CHARGERHUB',    '{9256C34E-5CFD-4F37-8BFE-E65390EBB37C}');
define('GUID_METERHUB',      '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}');
define('GUID_INVERTERHUB',   '{BBE2C593-1A91-426D-A714-29A9C7E87589}');
define('GUID_HEISHAMON',     '{1919151A-3C0F-4C09-B906-291638EC1469}');
define('GUID_TESSIEVEHICLE', '{3F1F7E31-8BA0-4B8F-9B62-47DAD7A0B6C9}');
define('GUID_TIBBERGRIDREWARD', '{E92F62F4-88A6-4C6E-9F0D-E76C3B1C9A01}');

// StromGedacht (GSI/Energy-Charts-Signal fuer "gruenste Ladezeit", siehe
// getCurrentGreenScore()/optimize()-Branch 2b). GUID von StromGedacht
// selbst bestaetigt, nicht geraten (29.07.2026).
define('GUID_STROMGEDACHT', '{D5A8C3A1-2222-4A55-8888-123456789003}');

// PV-Erzeugungsprognose (PVF_GetForecast-Vertrag: 96 15-Min-Slots p10/p50/p90
// in Watt, offset 0=heute/1=morgen) -- ersetzt die alte VAR_FC_JSON-Property,
// siehe parseForecastNextHours()/BuildDayPlan().
define('GUID_PVFORECAST', '{257DD4E8-9705-462E-89FC-56D0A1038353}');

// LoadForecast (LFC_GetEnergyWindow-Vertrag: erwarteter Verbrauch in kWh
// fuer ein beliebiges Zeitfenster) -- fuer das dynamische, energiebasierte
// Batterie-Tagesziel, siehe getDynamicSocTargetDay().
define('GUID_LFC', '{DC5AD508-507F-40EA-8630-0959AED83050}');

// Tile Visualization (WebFront-Kachelseite) -- fuer WFC_PushNotification()
// Ziel-InstanceID. NIEMALS hart hinterlegen (Grundregel "keine eigene Anlage
// als Norm"): jeder Nutzer hat eine andere Instanz-ID/andere Kachelseiten.
// Property NOTIFY_Visualization_ID laesst den Nutzer explizit auswaehlen,
// siehe GetVisualizationInstances()/checkFederationHealthAlarm().
define('GUID_TILEVISUALIZATION', '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}');

class EMS extends IPSModule
{
    // ----------------------------------------------------------------
    //  Modul-Lebenszyklus
    // ----------------------------------------------------------------

    public function Create()
    {
        parent::Create();

        // ── Allgemein & Schutz ──────────────────────────────────────
        $this->RegisterPropertyBoolean('EMS_Active',           false);
        $this->RegisterPropertyInteger('EMS_Interval',         30);
        $this->RegisterPropertyInteger('EMS_Max_Power_W',      34500);
        $this->RegisterPropertyInteger('EMS_Fallback_Mode',    GW_MODE_AUTO);
        $this->RegisterPropertyInteger('EMS_Fallback_Timeout', 60);
        $this->RegisterPropertyInteger('EMS_Log_Level',        EMS_LOG_BASIC);

        // PV-Vollernte bei vollem Akku (Branch 3b, siehe optimize()): AC_EXPORT
        // nutzt Xset (aktiver Zielwert, zapft die Batterie an) statt einer reinen
        // Ceiling -- der Zielwert wird daher aus der PV-Prognose (PVF, aktueller
        // Slot) abgeleitet, nicht aus einer festen Nutzereingabe (die das Wetter
        // ignorieren wuerde). Ohne installierte PVF-Instanz bleibt der Branch
        // bewusst inaktiv (Grundregel "keine eigene Anlage als Norm").
        // Mindestverweildauer in Branch 3b gegen Oszillation mit dem Automatik-
        // Fallback (7), siehe optimize()-Kommentar. Sicherheitsausstieg bei
        // sichtbarem SOC-Abfall ignoriert diese Dauer bewusst.
        $this->RegisterPropertyInteger('EXPORT_Min_Dwell_Minutes', 10);
        $this->RegisterAttributeInteger('Export3bEnteredTs', 0);

        // Aktive Ausfall-Benachrichtigung (Dietmar, 04.08.2026): passive Statuszeile
        // reicht nicht, notwendige Verbindungsabbrueche muessen aktiv gemeldet werden.
        // 0 = deaktiviert (Default -- kein Nutzer wird ungefragt mit Push zugespammt).
        // Laeuft UNABHAENGIG von EMS_Active, siehe checkFederationHealthAlarm().
        $this->RegisterPropertyInteger('NOTIFY_Visualization_ID', 0);
        // Gnadenfrist, bevor ueberhaupt gemeldet wird (Dietmar, 04.08.2026):
        // manche Verbindungen sind ABSICHTLICH temporaer weg (z.B. Tessie waehrend
        // das Auto schlaeft, um API-Kontingent/Autobatterie zu schonen) -- generisch
        // gehalten, nicht Tessie-spezifisch, da das theoretisch bei jedem Geraet
        // vorkommen kann. Erst nach dieser Dauer ununterbrochener Stoerung wird
        // wirklich alarmiert, siehe checkFederationHealthAlarm().
        $this->RegisterPropertyInteger('NOTIFY_Grace_Minutes', 15);
        $this->RegisterAttributeInteger('UnhealthySinceTs', 0);
        $this->RegisterAttributeBoolean('LastHealthAlarmActive', false);

        // ── Netzmesspunkte ──────────────────────────────────────────
        $this->RegisterPropertyInteger('VAR_SM_L1_Power',      0);
        $this->RegisterPropertyInteger('VAR_SM_L2_Power',      0);
        $this->RegisterPropertyInteger('VAR_SM_L3_Power',      0);
        $this->RegisterPropertyInteger('VAR_SM_Total_Power',   0);
        $this->RegisterPropertyInteger('VAR_SM_Frequency',     0);
        $this->RegisterPropertyInteger('VAR_SM_Status',        0);
        $this->RegisterPropertyBoolean('PAC2200_Active',       false);
        $this->RegisterPropertyInteger('VAR_PAC_L1_Power',     0);
        $this->RegisterPropertyInteger('VAR_PAC_L2_Power',     0);
        $this->RegisterPropertyInteger('VAR_PAC_L3_Power',     0);
        $this->RegisterPropertyInteger('VAR_PAC_L1_Current',   0);
        $this->RegisterPropertyInteger('VAR_PAC_L2_Current',   0);
        $this->RegisterPropertyInteger('VAR_PAC_L3_Current',   0);
        $this->RegisterPropertyInteger('VAR_PAC_Energy_Import',0);
        $this->RegisterPropertyInteger('VAR_PAC_Energy_Export',0);

        // ── Wechselrichter & PV ─────────────────────────────────────
        $this->RegisterPropertyInteger('VAR_WR_EMS_Mode',      0);
        $this->RegisterPropertyInteger('VAR_WR_EMS_Power',     0);
        $this->RegisterPropertyInteger('VAR_WR_Export_Enable', 0);
        $this->RegisterPropertyInteger('VAR_WR_Export_Limit',  0);
        $this->RegisterPropertyInteger('VAR_PV_Total_Power',   0);
        $this->RegisterPropertyInteger('VAR_PV_Day_Energy',    0);
        $this->RegisterPropertyBoolean('PV_MPPT_Active',       false);
        $this->RegisterPropertyInteger('VAR_PV_MPPT1_Power',   0);
        $this->RegisterPropertyInteger('VAR_PV_MPPT2_Power',   0);
        $this->RegisterPropertyInteger('VAR_PV_MPPT3_Power',   0);
        $this->RegisterPropertyInteger('VAR_WR_Total_Power',   0);
        $this->RegisterPropertyInteger('VAR_WR_Temp',          0);
        $this->RegisterPropertyInteger('VAR_WR_Temp_Cooler',   0);
        $this->RegisterPropertyInteger('VAR_WR_Diag_Status',   0);

        // ── Batteriespeicher ────────────────────────────────────────
        $this->RegisterPropertyBoolean('BAT_Active',               false);
        $this->RegisterPropertyInteger('BAT_String_Count',         1);
        $this->RegisterPropertyFloat(  'BAT_Capacity_kWh',         10.0);
        $this->RegisterPropertyInteger('BAT_SOC_Min',              10);
        $this->RegisterPropertyInteger('BAT_SOC_Target_Night',     100);
        $this->RegisterPropertyInteger('BAT_SOC_Target_Day',       80);
        $this->RegisterPropertyBoolean('BAT_SOC_Dynamic_Target',   true);
        $this->RegisterPropertyInteger('BAT_SOC_Safety_Margin_Pct',10);
        $this->RegisterPropertyInteger('BAT_SOC_Reserve_Backup',   10);
        for ($i = 1; $i <= 2; $i++) {
            $this->RegisterPropertyInteger('VAR_BAT' . $i . '_SOC',            0);
            $this->RegisterPropertyInteger('VAR_BAT' . $i . '_Power',          0);
            $this->RegisterPropertyInteger('VAR_BAT' . $i . '_Mode',           0);
            $this->RegisterPropertyInteger('VAR_BAT' . $i . '_SOH',            0);
            $this->RegisterPropertyInteger('VAR_BAT' . $i . '_Min_SOC_Online', 0);
            $this->RegisterPropertyInteger('VAR_BAT' . $i . '_Min_SOC_Offline',0);
        }

        // ── Wallboxen ───────────────────────────────────────────────
        $this->RegisterPropertyBoolean('WB_Active',            false);
        $this->RegisterPropertyInteger('WB_Count',             1);
        $this->RegisterPropertyBoolean('WB_GridRewards_Active',false);
        $this->RegisterPropertyInteger('WB_Cooldown_Sec',      120);
        $this->RegisterPropertyInteger('WB_Min_Charge_Min',    5);
        $this->RegisterPropertyInteger('BOOST_Duration_Min',   30);
        $this->RegisterPropertyInteger('SITE_Max_Grid_Import_W', 0);

        // ── Gruenste Ladezeit (optional, Vorbild evcc) ──────────────
        $this->RegisterPropertyBoolean('GREEN_Charge_Enabled',  false);
        $this->RegisterPropertyInteger('GREEN_GSI_Threshold',   66);
        for ($i = 1; $i <= 2; $i++) {
            $this->RegisterPropertyInteger('WB' . $i . '_Instance',   0);
            $this->RegisterPropertyInteger('WB' . $i . '_Max_Power_W',11000);
            $this->RegisterPropertyInteger('WB' . $i . '_Min_Power_W',1380);
            $this->RegisterPropertyInteger('WB' . $i . '_Priority',   $i);
            $this->RegisterPropertyInteger('VAR_WB' . $i . '_Status', 0);
            $this->RegisterPropertyInteger('VAR_WB' . $i . '_Power',  0);
            $this->RegisterPropertyInteger('VAR_WB' . $i . '_Active', 0);
            $this->RegisterPropertyInteger('VAR_WB' . $i . '_Cable',  0);
            $this->RegisterPropertyInteger('VAR_WB' . $i . '_Phases', 0);
            $this->RegisterPropertyInteger('VAR_WB' . $i . '_Error',  0);
        }

        // ── Wärmepumpe ──────────────────────────────────────────────
        $this->RegisterPropertyBoolean('HP_Active',            false);
        $this->RegisterPropertyInteger('VAR_HP_Power',         0);
        $this->RegisterPropertyInteger('VAR_HP_State',         0);
        $this->RegisterPropertyInteger('VAR_HP_Outside_Temp',  0);
        $this->RegisterPropertyInteger('VAR_HP_Mode',          0);
        $this->RegisterPropertyInteger('VAR_HP_COP_Heat',      0);
        $this->RegisterPropertyInteger('VAR_HP_DHW_Temp',      0);

        // ── Tibber ──────────────────────────────────────────────────
        $this->RegisterPropertyBoolean('TIBBER_Active',            false);
        $this->RegisterPropertyInteger('VAR_TIB_Price',            0);
        $this->RegisterPropertyInteger('VAR_TIB_Level',            0);
        $this->RegisterPropertyInteger('VAR_TIB_Feed_Tariff',      0);
        $this->RegisterPropertyInteger('VAR_TIB_PT15M_Today',      0);
        $this->RegisterPropertyInteger('VAR_TIB_PT15M_Tomorrow',   0);
        $this->RegisterPropertyInteger('VAR_TIB_PT60M_Today',      0);
        $this->RegisterPropertyInteger('VAR_TIB_Ahead_15M',        0);
        // Einheit EUR/kWh (nicht ct/kWh) -- passend zu Tibbers CurrentPrice-Vertrag,
        // siehe readState()/tib_feed-Fallback und SUITE.md-Historie 25.07.2026.
        $this->RegisterPropertyFloat(  'TIB_Threshold_Charge',     0.15);
        $this->RegisterPropertyFloat(  'TIB_Threshold_Discharge',  0.25);
        $this->RegisterPropertyFloat(  'TIB_Threshold_WB',         0.20);
        // TIB_Threshold_Export ENTFERNT 20.08.2026 -- war ein wirtschaftlich
        // rueckwaertiger Export-Ausloeser bei fester Einspeiseverguetung,
        // siehe Kommentar in simulateDaySlot(). Kein Ersatz noetig: der
        // korrekte Export-Ausloeser ("Bezug < Einspeiseverguetung") braucht
        // keine eigene Schwelle, er vergleicht direkt gegen VAR_TIB_Feed_Tariff.

        // ── Tagesplan: Lastprognose-Fallback ────────────────────────
        // Frueher nur fuer die separate Solarspitzengesetz-Funktion genutzt
        // (entfernt 19.08.2026, siehe BuildDayPlan()) -- jetzt der generelle
        // Fallback-Mittelwert fuer die Hausverbrauchs-Schaetzung je Slot,
        // solange LFC keine belastbare Tagesprognose liefert.
        $this->RegisterPropertyInteger('NEG_Avg_House_Load_W',   500);

        // ── §14a EnWG ───────────────────────────────────────────────
        $this->RegisterPropertyBoolean('ENWG14A_Active',           false);
        $this->RegisterPropertyString( 'ENWG14A_Provider',         '');
        $this->RegisterPropertyInteger('ENWG14A_Start_Hour',       0);
        $this->RegisterPropertyInteger('ENWG14A_End_Hour',         6);
        $this->RegisterPropertyInteger('ENWG14A_Reduction_Pct',    90);

        // ── PV Forecast ─────────────────────────────────────────────
        $this->RegisterPropertyBoolean('FORECAST_Active',          false);
        $this->RegisterPropertyInteger('VAR_FC_Today',             0);
        $this->RegisterPropertyInteger('VAR_FC_Tomorrow',          0);
        $this->RegisterPropertyInteger('VAR_FC_JSON',              0);
        $this->RegisterPropertyInteger('FORECAST_Min_Power_W',     200);
        $this->RegisterPropertyInteger('FORECAST_Confidence',      50);

        // ── Optimierungsparameter ───────────────────────────────────
        $this->RegisterPropertyInteger('OPT_Weight_Selfuse',       70);
        $this->RegisterPropertyInteger('OPT_Hysteresis_SOC',       3);
        $this->RegisterPropertyInteger('OPT_Hysteresis_Power',     200);
        $this->RegisterPropertyFloat(  'OPT_Hysteresis_Price',     0.01);
        $this->RegisterPropertyInteger('OPT_Cooldown_Sec',         60);
        $this->RegisterPropertyInteger('OPT_Planning_Horizon_H',   24);

        // ── Statusvariablen ─────────────────────────────────────────
        $this->RegisterVariableBoolean('EMS_Active_State', 'EMS aktiv',             '', 10);
        $this->RegisterVariableInteger('EMS_Mode',         'Betriebsmodus',          '', 20);
        $this->RegisterVariableFloat(  'EMS_GridPower',    'Netzleistung (W)',        '', 30);
        $this->RegisterVariableFloat(  'EMS_PVPower',      'PV-Leistung (W)',         '', 40);
        $this->RegisterVariableFloat(  'EMS_BatPower',     'Batterieleistung (W)',    '', 50);
        $this->RegisterVariableFloat(  'EMS_BatSOC',       'Batterie SOC (%)',        '', 60);
        $this->RegisterVariableFloat(  'EMS_HousePower',   'Hausverbrauch (W)',       '', 70);
        $this->RegisterVariableFloat(  'EMS_WB1Power',     'Wallbox 1 Leistung (W)', '', 80);
        $this->RegisterVariableFloat(  'EMS_WB2Power',     'Wallbox 2 Leistung (W)', '', 90);
        $this->RegisterVariableFloat(  'EMS_TibberPrice',  'Tibber Preis (EUR/kWh)', '',100);
        $this->RegisterVariableBoolean('EMS_GridRewards',  'Grid Rewards aktiv',     '',110);
        $this->RegisterVariableString( 'EMS_LastAction',   'Letzte Aktion',          '',120);
        $this->RegisterVariableString( 'EMS_Status',       'Status',                 '',130);

        $this->EnableAction('EMS_GridRewards');

        // ── Timer ───────────────────────────────────────────────────
        $this->RegisterTimer('EMS_UpdateTimer', 0, 'EMS_Update($_IPS[\'TARGET\']);');

        // ── Interne Attribute ───────────────────────────────────────
        $this->RegisterAttributeInteger('LastGoodweMode',    GW_MODE_AUTO);
        $this->RegisterAttributeBoolean('LastGoodweEnable',  true);
        $this->RegisterAttributeInteger('LastWB1Switch',     0);
        $this->RegisterAttributeInteger('LastWB2Switch',     0);
        $this->RegisterAttributeInteger('LastDecision',      0);
        $this->RegisterAttributeInteger('ConsecutiveErrors', 0);
        $this->RegisterAttributeInteger('BatteryBoostUntil', 0);
        $this->RegisterAttributeInteger('LastDiscoveryTs',   0);

        // ── Tagesplan (siehe BuildDayPlan()/ensureDayPlanEvent()) ────
        $this->RegisterAttributeString('DayPlan',          '[]');
        $this->RegisterAttributeString('DayPlanTomorrow',  '[]');
        $this->RegisterAttributeString('DayPlanSignature', '');
        $this->RegisterAttributeInteger('DayPlanEventId',  0);

        // ── Sondereffekt-Ereignisliste (externe Regeleingriffe, siehe
        // EMS_GetSpecialEvents()/trackSpecialEvents() -- lernende Module
        // wie LFC/PVF schliessen diese Fenster vom Training aus) ───────
        $this->RegisterAttributeString('SpecialEventsLog', '[]');

        // ── NRG-Stack Discovery (additiv, siehe discoverPartners()) ──
        $this->RegisterAttributeString('PartnerCache', '{}');
        $this->RegisterAttributeString('UnresponsiveInstances', '{}');
        $this->RegisterVariableString('EMS_Partners', 'NRG-Stack Partnermodule', '', 5);
        $this->RegisterVariableString('EMS_Situation', 'Steuerhoheit (Situation A/B)', '', 6);
        $this->RegisterVariableString('EMS_FederationHealth', 'Verbund-Gesundheit', '', 7);

        // ── Formular-Optik (Verbund-Konvention, siehe SUITE.md) ──────
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('ForumHintDismissed', false);
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Idempotent: legt den sichtbaren Tagesplan-Wochenplan nur an, wenn
        // er noch fehlt (Muster: NRG.*-Variablenprofile).
        try {
            $this->ensureDayPlanEvent();
        } catch (Throwable $e) {
            // Throwable statt Exception: PHP-Fehlerklassen wie ArgumentCountError
            // erben von Error, nicht Exception -- ein reines catch(Exception)
            // haette den live gefundenen IPS_SetEventScheduleAction()-Bug
            // (19.08.2026) NICHT abgefangen, ApplyChanges() waere trotzdem
            // abgestuerzt.
            $this->emsLog(EMS_LOG_BASIC, 'Tagesplan-Event konnte nicht angelegt werden: ' . $e->getMessage());
        }

        $active   = $this->ReadPropertyBoolean('EMS_Active');
        $interval = $this->ReadPropertyInteger('EMS_Interval');

        // Timer laeuft IMMER, auch wenn EMS_Active=false -- Update() macht
        // die eigentliche Steuerentscheidung (optimize()/applyDecision())
        // selbst schon vom EMS_Active-Flag abhaengig (siehe dort), aber der
        // Gesundheitscheck UND der Tagesplan (BuildDayPlan()) sollen laut
        // eigenem Kommentar in Update() bewusst auch bei deaktiviertem EMS
        // laufen, damit Dietmar den Plan vor der Aktivierung beobachten kann.
        // Bug gefunden 20.08.2026 (Live-Fund: Tagesplan blieb dauerhaft leer,
        // weil der Timer hier komplett abgeschaltet wurde, sobald EMS aus
        // war -- Update() wurde dadurch nie periodisch aufgerufen, ganz
        // unabhaengig davon, dass BuildDayPlan() selbst schon EMS_Active-
        // unabhaengig geschrieben war).
        $this->SetTimerInterval('EMS_UpdateTimer', $interval * 1000);
        if ($active) {
            $this->SetStatus(102);
            $this->emsLog(EMS_LOG_BASIC, 'EMS gestartet, Intervall: ' . $interval . 's');
        } else {
            $this->SetStatus(104);
            $this->emsLog(EMS_LOG_BASIC, 'EMS deaktiviert (Gesundheitscheck/Tagesplan laufen weiter)');
        }

        $this->SetValue('EMS_Active_State', $active);
    }

    // ----------------------------------------------------------------
    //  Formular-Optik (Verbund-Konvention, siehe SUITE.md
    //  "Einheitliche Formular-Optik"; Referenzimplementierung InverterHub)
    // ----------------------------------------------------------------

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // 2. "Dokumentation & Hilfe" — eingeklappt, Versionsnummer hier.
        // Zuerst eingefuegt, damit sie nach dem (optionalen) News-Panel-
        // unshift direkt an Position 2 landet.
        array_unshift($form['elements'], array(
            'type'     => 'ExpansionPanel',
            'caption'  => '📖 Dokumentation & Hilfe',
            'expanded' => false,
            'items'    => array(
                array(
                    'type'    => 'Label',
                    'caption' => 'ℹ️ EMS Version ' . EMS_NEWS_VERSION . ' (Build ' . $this->getOwnBuild() . ')'
                ),
                array(
                    'type'    => 'Label',
                    'caption' => 'Koordiniert alle NRG-Stack-Module (InverterHub, MeterHub, ChargerHub, HeishaMon, Tessie, TibberGridRewards, StromGedacht, SteuerboxHub) über deren *_GetFunctions-Verträge. Details: https://github.com/DG65/NRGEMS'
                ),
            )
        ));

        // 3. "Verbund-Status" — live Statusdaten direkt im Formular, nicht nur
        // als Statusvariable im Objektbaum (Nutzer-Feedback 27.07.2026: Text
        // verwies auf "oben im Formular", aber die Werte standen bisher nur
        // im Objektbaum, nicht im Formular selbst).
        array_unshift($form['elements'], array(
            'type'     => 'ExpansionPanel',
            'caption'  => '🔗 Verbund-Status',
            'expanded' => true,
            'items'    => array(
                array(
                    'type'    => 'Button',
                    'caption' => '🔎 Jetzt neu suchen',
                    'onClick' => 'EMS_Discover($id);'
                ),
                array(
                    'type'    => 'Label',
                    'name'    => 'DiscoverySummaryLabel',
                    'caption' => $this->getDiscoverySummaryLine(),
                ),
                array(
                    'type'    => 'Label',
                    'name'    => 'FederationHealthLabel',
                    'caption' => 'Verbund-Gesundheit: ' . $this->GetValue('EMS_FederationHealth')
                ),
                array(
                    'type'    => 'Label',
                    'name'    => 'BoostStatusLabel',
                    'caption' => $this->getBoostStatusLine(),
                ),
                array(
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Details je Partnermodul-Typ',
                    'expanded' => false,
                    'items'   => array(
                        array(
                            'type'    => 'Label',
                            'name'    => 'PartnerDetailLabel',
                            'caption' => 'NRG-Stack Partnermodule: ' . $this->GetValue('EMS_Partners')
                        ),
                    )
                ),
            )
        ));

        // 1. "Was ist Neu" — aufgeklappt, pro Version dismissible
        if ($this->ReadAttributeString('SeenNews') !== EMS_NEWS_VERSION) {
            array_unshift($form['elements'], array(
                'type'     => 'ExpansionPanel',
                'name'     => 'NewsPanel',
                'caption'  => '🆕 Neu in Version ' . EMS_NEWS_VERSION,
                'expanded' => true,
                'items'    => array(
                    array(
                        'type'    => 'Label',
                        'caption' => '• NEU: Sichtbarer Tagesplan (Wochenplan-Event "EMS Tagesplan (automatisch)" unter dieser Instanz) — zeigt je Viertelstunde, was das EMS vorhat und warum, statt nur reaktiv auf den Momentanpreis zu reagieren. Nutzt PT15M-Preise + PV-Prognose (PVF) + Lastschätzung.'
                    ),
                    array(
                        'type'    => 'Label',
                        'caption' => '• NEU: Export-Entscheidung berücksichtigt jetzt auch, wenn der aktuelle Bezugspreis unter der Einspeisevergütung liegt (typisch mittags) — dann exportiert die Batterie lieber, statt für Eigenverbrauch entladen zu werden, und der Hausverbrauch wird günstig aus dem Netz gedeckt.'
                    ),
                    array(
                        'type'    => 'Label',
                        'caption' => '• Entfernt: die alten Buttons "Nacht-Ladefenster planen"/"Negativpreis-Vorentladung planen" (schrieben in die Goodwe-eigenen ECO-Zeitfenster-Register, liefen nie automatisch und konnten dem laufenden EMS widersprechen). Beide Aufgaben übernimmt jetzt der Tagesplan, ausgeführt über denselben Weg wie der Rest von EMS (InverterHub ctl_ems_*).'
                    ),
                    array(
                        'type'    => 'Label',
                        'caption' => '• Automatische NRG-Stack-Partnermodul-Erkennung (EMS_Discover) — kein manuelles Verknüpfen von Variablen-IDs mehr nötig.'
                    ),
                    array(
                        'type'    => 'Label',
                        'caption' => '• Steuerhoheit je Gerät (Situation A/B): EMS erkennt automatisch, wo es schreiben darf und wo ein externer Akteur (Tibber, go-e Controller) bereits regelt.'
                    ),
                    array(
                        'type'    => 'Label',
                        'caption' => '• Steuerung läuft jetzt über die automatisch gefundenen Partnermodule (InverterHub, ChargerHub) statt über die alten, manuell zu verknüpfenden Felder unten in "Wechselrichter & PV"/"Wallboxen" — diese bleiben nur noch als Fallback bestehen, wenn kein Partnermodul gefunden wird.'
                    ),
                    array(
                        'type'    => 'Button',
                        'caption' => 'Verstanden – nicht mehr anzeigen',
                        'onClick' => 'EMS_AckNews($id);'
                    ),
                )
            ));
        }

        // 3b. Status-Zeile ueber dem manuellen PT15M-Fallback-Feld einfuegen —
        // zeigt live, ob/wie das Feld gerade automatisch ueberholt ist
        // (Dietmars Einwand 20.08.2026, siehe SUITE.md "Formular-Konvention:
        // Status neben manuellen Fallback-Feldern"). Sucht das Tibber-Panel
        // per Caption und splict die Zeile direkt vor VAR_TIB_PT15M_Today ein.
        foreach ($form['elements'] as &$element) {
            if (($element['type'] ?? '') === 'ExpansionPanel' && ($element['caption'] ?? '') === '💰 Tibber & Tarif') {
                foreach ($element['items'] as $idx => $item) {
                    if (($item['name'] ?? '') === 'VAR_TIB_PT15M_Today') {
                        array_splice($element['items'], $idx, 0, array($this->statusLabel($this->getPT15MStatusLine())));
                        break;
                    }
                }
                break;
            }
        }
        unset($element);

        // 3c. Netzmesspunkte-Panel: pauschalen "schau oben"-Hinweis durch eine
        // Status-Zeile JE FELD ersetzen (Dietmars Praezisierung 20.08.2026:
        // nicht ein Panel-weiter Verweis, sondern direkt hinter jedem
        // relevanten Auswahlfeld einzeln).
        $gridFieldMap = array(
            'VAR_SM_L1_Power'    => 'l1',
            'VAR_SM_L2_Power'    => 'l2',
            'VAR_SM_L3_Power'    => 'l3',
            'VAR_SM_Total_Power' => 'total',
            'VAR_SM_Frequency'   => 'frequency',
            'VAR_SM_Status'      => 'status',
        );
        foreach ($form['elements'] as &$element) {
            if (($element['type'] ?? '') === 'ExpansionPanel' && strpos($element['caption'] ?? '', '📡 Netzmesspunkte') === 0) {
                $newItems = array();
                foreach ($element['items'] as $item) {
                    $name = $item['name'] ?? '';
                    if (isset($gridFieldMap[$name])) {
                        $newItems[] = $this->statusLabel($this->getGridFieldStatusLine($gridFieldMap[$name]));
                    }
                    // Den alten Pauschal-Verweis-Text ("Schau ganz oben...") aus
                    // der RowLayout entfernen -- die Status-Zeilen je Feld machen
                    // ihn ueberfluessig -- die PopupButton-Erklaerung daneben
                    // (wann brauche ich das ueberhaupt) bleibt erhalten.
                    if (($item['type'] ?? '') === 'RowLayout' && isset($item['items'][0]['caption'])
                        && strpos($item['items'][0]['caption'], 'Schau ganz oben im Panel') !== false) {
                        $item['items'] = array_values(array_filter($item['items'], function ($sub) {
                            return !(isset($sub['caption']) && strpos($sub['caption'], 'Schau ganz oben im Panel') !== false);
                        }));
                    }
                    $newItems[] = $item;
                }
                $element['items'] = $newItems;
                break;
            }
        }
        unset($element);

        // 3d. Batteriespeicher-Panel: Status-Zeile vor dem Bat1-SOC-Feld
        // (20.08.2026, gleiches Muster wie Tibber/Netzmesspunkte oben) --
        // dieses Feld ist das einzige der Batteriestring-Felder mit einer
        // roten Pflichtfeld-Kennzeichnung, weil ohne SOC-Wert (weder
        // automatisch noch manuell) Tagesplan/optimize() faelschlich mit
        // SOC=0% rechnen.
        foreach ($form['elements'] as &$element) {
            if (($element['type'] ?? '') === 'ExpansionPanel' && ($element['caption'] ?? '') === '🔋 Batteriespeicher') {
                $newItems = array();
                foreach ($element['items'] as $item) {
                    $name = $item['name'] ?? '';
                    // Vor Bat1-SOC UND vor Bat2-SOC dieselbe Zeile einfuegen
                    // (20.08.2026, Dietmars Nachfrage: die Zeile stand nur ueber
                    // Batteriestring 1, obwohl InverterHub bei zwei Strings
                    // einen bereits aggregierten Wert liefert, der GENAUSO fuer
                    // Batteriestring 2 gilt -- sonst sieht das aus wie eine
                    // offene Frage bei Bat2, obwohl auch dort alles geklaert ist).
                    if ($name === 'VAR_BAT1_SOC' || $name === 'VAR_BAT2_SOC') {
                        $newItems[] = $this->statusLabel($this->getBatterySocStatusLine());
                    }
                    $newItems[] = $item;
                }
                $element['items'] = $newItems;
                break;
            }
        }
        unset($element);

        // 3e. "Wechselrichter & PV"-Panel: Status-Zeile je Feld (20.08.2026,
        // Dietmars Folgefrage). Drei echte Automatik-Faelle (Steuerregister,
        // PV-Gesamtleistung, WR-Gesamtleistung) + mehrere Felder, die vom
        // Code aktuell gar nicht gelesen werden (ehrlich als 🚫 markiert,
        // statt sie als Fallback zu verkaufen).
        $inverterFieldMap = array(
            'VAR_WR_EMS_Mode'      => 'control',
            'VAR_WR_EMS_Power'     => null, // Statuszeile nur einmal vor Mode, nicht doppelt vor Power
            'VAR_WR_Export_Enable' => 'unused',
            'VAR_WR_Export_Limit'  => 'unused',
            'VAR_PV_Total_Power'   => 'pv_total',
            'VAR_PV_Day_Energy'    => 'unused',
            'VAR_PV_MPPT1_Power'   => 'unused',
            'VAR_PV_MPPT2_Power'   => null, // eine Zeile fuer alle drei MPPT-Felder reicht
            'VAR_PV_MPPT3_Power'   => null,
            'VAR_WR_Total_Power'   => 'wr_total',
            'VAR_WR_Temp'          => 'unused',
            'VAR_WR_Temp_Cooler'   => null, // eine Zeile fuer beide Temperaturfelder reicht
            'VAR_WR_Diag_Status'   => 'unused',
        );
        foreach ($form['elements'] as &$element) {
            if (($element['type'] ?? '') === 'ExpansionPanel' && strpos($element['caption'] ?? '', '⚡ Wechselrichter & PV') === 0) {
                $newItems = array();
                foreach ($element['items'] as $item) {
                    $name = $item['name'] ?? '';
                    if (array_key_exists($name, $inverterFieldMap) && $inverterFieldMap[$name] !== null) {
                        $newItems[] = $this->statusLabel($this->getInverterFieldStatusLine($inverterFieldMap[$name]));
                    }
                    // Alten Pauschal-Hinweis ganz oben im Panel entfernen -- die
                    // Zeilen je Feld machen ihn ueberfluessig.
                    if (($item['type'] ?? '') === 'Label' && isset($item['caption'])
                        && strpos($item['caption'], 'Nur nötig, falls kein Partnermodul') !== false) {
                        continue;
                    }
                    $newItems[] = $item;
                }
                $element['items'] = $newItems;
                break;
            }
        }
        unset($element);

        // 4. Symcon-Forum-Hinweis — nach den Haupteinstellungen, einmalig dismissible
        if (!$this->ReadAttributeBoolean('ForumHintDismissed')) {
            $form['elements'][] = array(
                'type'  => 'RowLayout',
                'name'  => 'ForumHint',
                'items' => array(
                    array(
                        'type'    => 'Label',
                        'caption' => '✏️ EMS ist Beta — Rückmeldungen im Symcon-Forum willkommen: (Thread folgt, sobald veröffentlicht)'
                    ),
                    array(
                        'type'    => 'Button',
                        'caption' => 'Nicht mehr anzeigen',
                        'onClick' => 'EMS_DismissForumHint($id);'
                    ),
                )
            );
        }

        // 5. "Benachrichtigungen" — aktive Ausfall-Meldung (Dietmar, 04.08.2026).
        // Auswahlliste dynamisch aus GetVisualizationInstances(), NIE eine
        // Instanz-ID hart hinterlegen -- jeder Nutzer hat andere Kachelseiten.
        $form['elements'][] = array(
            'type'     => 'ExpansionPanel',
            'caption'  => '🔔 Benachrichtigungen',
            'expanded' => false,
            'items'    => array(
                array(
                    'type'    => 'Label',
                    'caption' => 'Meldet aktiv per WebFront-Push, sobald ein notwendiger NRG-Stack-Partner ausfaellt oder nicht mehr antwortet (nicht nur passive Statuszeile oben). Laeuft unabhaengig davon, ob EMS gerade aktiv ist.'
                ),
                array(
                    'type'    => 'Select',
                    'name'    => 'NOTIFY_Visualization_ID',
                    'caption' => 'Kachelseite für Push-Benachrichtigung',
                    'options' => $this->GetVisualizationInstances(),
                ),
                array(
                    'type'    => 'NumberSpinner',
                    'name'    => 'NOTIFY_Grace_Minutes',
                    'caption' => 'Gnadenfrist vor Meldung (Minuten) — verhindert Fehlalarm bei kurzen/erwarteten Unterbrechungen (z. B. Auto im Schlafmodus)',
                    'minimum' => 0,
                    'maximum' => 180,
                    'suffix'  => ' min'
                ),
            )
        );

        return json_encode($form);
    }

    public function AckNews()
    {
        $this->WriteAttributeString('SeenNews', EMS_NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function DismissForumHint()
    {
        $this->WriteAttributeBoolean('ForumHintDismissed', true);
        $this->UpdateFormField('ForumHint', 'visible', false);
    }

    private function getOwnBuild()
    {
        $lib = @IPS_GetLibrary('{90286A25-E6C9-4A66-BD4E-0CFB707C2C6C}');
        return $lib['Build'] ?? '?';
    }

    // ----------------------------------------------------------------
    //  Oeffentliche Funktionen
    // ----------------------------------------------------------------

    public function Update()
    {
        // Verbund-Gesundheitscheck + Ausfall-Meldung laeuft IMMER, auch wenn
        // EMS_Active=false (z.B. waehrend einer Notabschaltung) -- ein reiner
        // Diagnose-/Melde-Vorgang, keine Steuerentscheidung, siehe
        // checkFederationHealthAlarm(). Fehler hier duerfen NICHT den
        // ConsecutiveErrors-Fallback-Mechanismus fuer echte Steuerfehler ausloesen.
        try {
            $this->Discover(); // ruft GetFederationHealth() intern auf
            $this->checkFederationHealthAlarm();
        } catch (Exception $e) {
            $this->emsLog(EMS_LOG_BASIC, 'Health-Check-Fehler (Meldung übersprungen): ' . $e->getMessage());
        }

        // Tagesplan bei neuen Preisen/Prognosen automatisch aktualisieren --
        // laeuft bewusst UNABHAENGIG von EMS_Active, damit der Plan auch bei
        // deaktiviertem EMS sichtbar bleibt (Dietmar kann ihn pruefen, bevor
        // er EMS ueberhaupt aktiviert -- siehe SUITE.md-Historie 19.08.2026).
        try {
            $this->BuildDayPlan(false);
        } catch (Throwable $e) {
            $this->emsLog(EMS_LOG_BASIC, 'Tagesplan-Fehler (Ausfuehrung laeuft unbeeinflusst weiter): ' . $e->getMessage());
        }

        if (!$this->ReadPropertyBoolean('EMS_Active')) {
            return;
        }

        try {
            $state      = $this->readState();
            $this->updateStatusVars($state);
            $decision = $this->optimize($state);
            $this->applyDecision($decision, $state);
            $this->trackSpecialEvents($state);
            $this->WriteAttributeInteger('ConsecutiveErrors', 0);
            $this->SetStatus(102);

        } catch (Exception $e) {
            $errors    = $this->ReadAttributeInteger('ConsecutiveErrors') + 1;
            $this->WriteAttributeInteger('ConsecutiveErrors', $errors);
            $this->emsLog(EMS_LOG_BASIC, 'Fehler: ' . $e->getMessage() . ' (#' . $errors . ')');
            $timeout   = $this->ReadPropertyInteger('EMS_Fallback_Timeout');
            $interval  = $this->ReadPropertyInteger('EMS_Interval');
            $threshold = max(1, (int)($timeout / max(1, $interval)));
            if ($errors >= $threshold) {
                $this->applyFallback();
            }
        }
    }

    // ----------------------------------------------------------------
    //  NRG-Stack Discovery — automatische Partnermodul-Erkennung
    //  (additiv, ersetzt vorerst NICHT die manuelle Variablenverknuepfung
    //  oben — Migrationsschritt in mehreren Etappen, siehe SUITE.md)
    // ----------------------------------------------------------------

    /**
     * Sucht alle installierten Instanzen eines Partnermoduls und ruft,
     * sofern vorhanden, dessen *_GetFunctions()/GetState()-Vertrag ab.
     * Nie eine harte Abhaengigkeit: fehlt ein Modul, liefert die Suche
     * einfach eine leere Liste (Verbund-Grundregel, function_exists-Guard).
     */
    public function Discover()
    {
        $partners = array();

        $partners['inverterhub'] = $this->discoverContract(GUID_INVERTERHUB, 'IHUB_GetFunctions');
        $partners['meterhub']    = $this->discoverContract(GUID_METERHUB,    'MHUB_GetFunctions');
        $partners['chargerhub']  = $this->discoverContract(GUID_CHARGERHUB,  'CHUB_GetFunctions');
        $partners['heishamon']   = $this->discoverContract(GUID_HEISHAMON,   'HEISHA_GetFunctions');
        $partners['tessie']      = $this->discoverContract(GUID_TESSIEVEHICLE, 'TESSIE_GetVehicleState');

        // Tibber liefert keine *_GetFunctions-Liste, sondern eigene Getter
        // pro Instanz (Preiskurve/Tarif/aktive Fremdsteuerung).
        $tibberInstances = array();
        foreach (IPS_GetInstanceListByModuleID(GUID_TIBBERGRIDREWARD) as $id) {
            $entry = array('instanceID' => $id, 'label' => IPS_GetName($id));
            if (function_exists('TIBBERGR_GetTariffConfig')) {
                $entry['tariffConfig'] = TIBBERGR_GetTariffConfig($id);
            }
            if (function_exists('TIBBERGR_GetActiveControls')) {
                $entry['activeControls'] = TIBBERGR_GetActiveControls($id);
            }
            $tibberInstances[] = $entry;
        }
        $partners['tibber'] = $tibberInstances;

        // Installiert-aber-nicht-geantwortet erkennen (Dietmars Wunsch 29.07.2026,
        // ausgeloest durch den discoverContract()-JSON-String-Bug: eine Instanz kann
        // installiert sein, aber ihr Vertragsaufruf schlaegt fehl/wirft/liefert
        // Unerwartetes -- das darf nicht mehr stillschweigend verschwinden. Vergleicht
        // pro Modul die installierten Instanz-IDs (IPS_GetInstanceListByModuleID) mit
        // den tatsaechlich erfolgreich geparsten (instanceID in $results). Erweitert
        // 04.08.2026 um tibber (Dietmars Wunsch: ALLE Verbindungen ueberwachen,
        // nicht nur die urspruenglichen 5) -- deshalb hinter die Tibber-Erkennung
        // verschoben, da $partners['tibber'] erst dort entsteht. GoodweET
        // (eigenstaendiges Modul) existiert nicht mehr, siehe Git-History fuer die
        // alte, jetzt entfernte Sonderbehandlung.
        $moduleGuids = array(
            'inverterhub' => GUID_INVERTERHUB,
            'meterhub'    => GUID_METERHUB,
            'chargerhub'  => GUID_CHARGERHUB,
            'heishamon'   => GUID_HEISHAMON,
            'tessie'      => GUID_TESSIEVEHICLE,
            'tibber'      => GUID_TIBBERGRIDREWARD,
        );
        $unresponsive = array();
        foreach ($moduleGuids as $key => $guid) {
            $installed = IPS_GetInstanceListByModuleID($guid);
            if (empty($installed)) { continue; }
            $foundIds = array_column((array)$partners[$key], 'instanceID');
            $missing  = array_diff($installed, $foundIds);
            if (!empty($missing)) {
                $unresponsive[$key] = array_values(array_map('intval', $missing));
            }
        }
        $this->WriteAttributeString('UnresponsiveInstances', json_encode($unresponsive));

        $this->WriteAttributeString('PartnerCache', json_encode($partners));
        $this->WriteAttributeInteger('LastDiscoveryTs', time());

        $summary = sprintf(
            'InverterHub=%d MeterHub=%d ChargerHub=%d HeishaMon=%d Tessie=%d Tibber=%d',
            count($partners['inverterhub']), count($partners['meterhub']),
            count($partners['chargerhub']),  count($partners['heishamon']),
            count($partners['tessie']),      count($partners['tibber'])
        );
        $this->SetValue('EMS_Partners', $summary);
        $this->emsLog(EMS_LOG_BASIC, 'Discover: ' . $summary);

        // Situation A/B direkt mit aktualisieren, damit EMS_Situation nie
        // veraltete Daten zu einer frischeren PartnerCache zeigt.
        $situation = $this->GetSituation();
        $countB = 0;
        foreach ($situation as $s) {
            if ($s['situation'] === 'B') { $countB++; }
        }
        $situationSummary = sprintf(
            '%d Geräte gesamt, davon %d in Situation B (Fremdsteuerung, EMS beobachtet nur)',
            count($situation), $countB
        );
        $this->SetValue('EMS_Situation', $situationSummary);

        $this->GetFederationHealth();

        // Formular live nachziehen, falls es gerade offen ist (20.08.2026,
        // Live-Fund: der Button aktualisiert das PHP-seitig, aber
        // GetConfigurationForm() wird nach einem RequestAction NICHT vom
        // WebFront automatisch neu angefragt -- ohne UpdateFormField() blieb
        // z.B. die Kopfzeile dauerhaft auf "Noch nicht gesucht" stehen, obwohl
        // die Suche laengst gelaufen war und Partnermodule/Verbund-Gesundheit
        // korrekt aktualisiert wurden. No-op, wenn kein Formular offen ist.
        $this->UpdateFormField('DiscoverySummaryLabel', 'caption', $this->getDiscoverySummaryLine());
        $this->UpdateFormField('FederationHealthLabel', 'caption', 'Verbund-Gesundheit: ' . $this->GetValue('EMS_FederationHealth'));
        $this->UpdateFormField('PartnerDetailLabel', 'caption', 'NRG-Stack Partnermodule: ' . $summary);

        return $partners;
    }

    /**
     * Fuer ein gegebenes Partnermodul (GUID) alle installierten Instanzen
     * finden und deren Vertragsfunktion abrufen — mit function_exists-Guard,
     * damit ein fehlendes Modul die Discovery nie zum Absturz bringt.
     */
    private function discoverContract($moduleGUID, $function)
    {
        $results = array();
        if (!function_exists($function)) {
            return $results; // Partnermodul nicht installiert
        }
        foreach (IPS_GetInstanceListByModuleID($moduleGUID) as $id) {
            $data = call_user_func($function, $id);
            // Manche Vertraege (MHUB_GetFunctions, TESSIE_GetVehicleState) liefern
            // einen JSON-STRING statt eines PHP-Arrays -- ohne diesen Dekodierschritt
            // schlug is_array() hier bisher STILL fehl (kein Fehler, kein Log), die
            // Instanz wurde einfach uebersprungen. Gefunden von Dashboard 29.07.2026.
            if (is_string($data)) {
                $data = json_decode($data, true);
            }
            if (is_array($data)) {
                // GetFunctions-Verträge liefern Listen, GetVehicleState/GetState
                // liefern ein einzelnes Objekt — beides normalisiert als Liste
                // von Eintraegen mit instanceID, damit der Konsument einheitlich
                // iterieren kann.
                if ($this->isListContract($data)) {
                    foreach ($data as $entry) {
                        $entry['instanceID'] = $id;
                        $results[] = $entry;
                    }
                } else {
                    $data['instanceID'] = $id;
                    $results[] = $data;
                }
            }
        }
        return $results;
    }

    /**
     * Unterscheidet Listen-Vertraege (GetFunctions: numerisch indizierte
     * Liste von Eintraegen, z.B. mehrere Ladepunkte) von Objekt-Vertraegen
     * (GetVehicleState: ein einzelnes assoziatives Objekt) — siehe
     * SUITE.md "Platzierung von contractVersion". Ein Listen-Eintrag hat
     * fortlaufende Integer-Schluessel ab 0 UND sein erstes Element ist
     * selbst ein Array; ein Objekt-Vertrag hat String-Schluessel.
     */
    private function isListContract($data)
    {
        if (empty($data)) {
            return true; // leere Liste ist ein gueltiger Listen-Vertrag
        }
        $firstKey = array_key_first($data);
        return is_int($firstKey) && is_array($data[$firstKey]);
    }

    /**
     * Im letzten Discover()-Lauf gefundene Partnerdaten, ohne erneut
     * abzufragen (fuer Konsumenten, die nur den zuletzt bekannten Stand
     * brauchen, z.B. das Formular oder eine Kachel).
     */
    public function GetPartners()
    {
        $json = $this->ReadAttributeString('PartnerCache');
        $data = json_decode($json, true);
        return is_array($data) ? $data : array();
    }

    /**
     * Verbundweite Statusaggregation ueber alle bei Discover() gefundenen
     * Partnerinstanzen (deckt genau die tatsaechlich vernetzten,
     * kontrollrelevanten NRG-Stack-Module ab -- nicht jedes im Verbund
     * existierende Modul, sondern die, mit denen EMS aktiv Vertraege
     * austauscht). Liest je Instanz nur den nativen IP-Symcon-Status
     * (IPS_GetInstance()['InstanceStatus']), keinen modul-eigenen Status-
     * text -- das haelt die Methode robust gegen unterschiedliche
     * *_GetFunctions-Vertragsformen. 102 gilt als gesund, alles andere
     * (inkl. fehlender Instanz) als auffaellig.
     */
    public function GetFederationHealth()
    {
        $partners = $this->GetPartners();
        $entries  = array();

        foreach ($partners as $module => $list) {
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $entry) {
                $id = isset($entry['instanceID']) ? (int)$entry['instanceID'] : 0;
                if ($id <= 0) {
                    continue;
                }
                $status  = IPS_InstanceExists($id) ? IPS_GetInstance($id)['InstanceStatus'] : 0;
                $healthy = ($status === 102);
                $entries[] = array(
                    'module'     => $module,
                    'instanceID' => $id,
                    'label'      => IPS_InstanceExists($id) ? IPS_GetName($id) : '(geloescht)',
                    'status'     => $status,
                    'healthy'    => $healthy,
                );
            }
        }

        // Optionale "weiche" Abhaengigkeiten zusaetzlich als Health-Eintrag,
        // OBWOHL sie bewusst kein Steuer-/Situations-Partner sind (siehe
        // getPvfInstance()) -- Dietmars Wunsch 04.08.2026: er will ALLE
        // Verbindungen sehen/ueberwachen koennen, nicht nur die, die optimize()
        // direkt fuer Entscheidungen braucht. Getrennt von $partners/
        // GetPartners() gehalten, damit diese Erweiterung NICHT versehentlich
        // zur Entscheidungslogik wird.
        $softDependencies = array(
            'PVF'          => $this->getPvfInstance(),
            'LFC'          => $this->getLfcInstance(),
            'StromGedacht' => $this->getStromGedachtInstance(),
        );
        foreach ($softDependencies as $module => $id) {
            if ($id <= 0) {
                continue;
            }
            $status  = IPS_InstanceExists($id) ? IPS_GetInstance($id)['InstanceStatus'] : 0;
            $healthy = ($status === 102);
            $entries[] = array(
                'module'     => $module,
                'instanceID' => $id,
                'label'      => IPS_InstanceExists($id) ? IPS_GetName($id) : '(geloescht)',
                'status'     => $status,
                'healthy'    => $healthy,
            );
        }

        $unhealthy = array_values(array_filter($entries, function ($e) { return !$e['healthy']; }));

        // Installiert, aber nicht (mehr) antwortend -- siehe Discover()/UnresponsiveInstances.
        // Getrennt von $unhealthy, weil diese Instanzen gar nicht erst in GetPartners()
        // auftauchen (ihr Vertragsaufruf ist fehlgeschlagen), also auch keinen eigenen
        // InstanceStatus-Eintrag oben durchlaufen haben.
        $unresponsiveRaw = json_decode($this->ReadAttributeString('UnresponsiveInstances'), true);
        if (!is_array($unresponsiveRaw)) { $unresponsiveRaw = array(); }
        $missing = array();
        foreach ($unresponsiveRaw as $module => $ids) {
            foreach ((array)$ids as $id) {
                $missing[] = array(
                    'module'     => $module,
                    'instanceID' => (int)$id,
                    'label'      => IPS_InstanceExists($id) ? IPS_GetName($id) : '(geloescht)',
                );
            }
        }

        $summary = sprintf(
            '%d/%d Partnerinstanzen gesund',
            count($entries) - count($unhealthy), count($entries)
        );
        if (!empty($unhealthy)) {
            $labels = array_map(function ($e) {
                return $e['label'] . ' (Status ' . $e['status'] . ')';
            }, $unhealthy);
            $summary .= ' -- auffaellig: ' . implode(', ', $labels);
        }
        if (!empty($missing)) {
            $labels = array_map(function ($m) {
                return $m['label'] . ' (' . $m['module'] . ')';
            }, $missing);
            $summary .= ' -- installiert, aber ohne Antwort: ' . implode(', ', $labels);
        }

        $this->SetValue('EMS_FederationHealth', $summary);

        return array(
            'summary'      => $summary,
            'total'        => count($entries),
            'healthyCount' => count($entries) - count($unhealthy),
            'unhealthy'    => $unhealthy,
            'missingCount' => count($missing),
            'missing'      => $missing,
            'entries'      => $entries,
        );
    }

    /**
     * Findet alle installierten "Tile Visualization"-Instanzen (WebFront-
     * Kachelseiten) fuer die Formular-Auswahlliste von NOTIFY_Visualization_ID.
     * Oeffentlich, damit form.json sie live per Select-Value-Callback anzeigen
     * kann -- NIE eine Instanz-ID hart hinterlegen (Grundregel "keine eigene
     * Anlage als Norm"), jeder Nutzer hat andere IDs/andere Kachelseiten.
     */
    public function GetVisualizationInstances()
    {
        $out = array(array('caption' => '(deaktiviert)', 'value' => 0));
        foreach (IPS_GetInstanceListByModuleID(GUID_TILEVISUALIZATION) as $id) {
            $out[] = array(
                'caption' => IPS_GetName($id) . ' (#' . $id . ')',
                'value'   => $id,
            );
        }
        return $out;
    }

    /**
     * Aktive Ausfall-Meldung (Dietmar, 04.08.2026): die passive Statuszeile in
     * EMS_FederationHealth reicht nicht -- notwendige Verbindungsabbrueche
     * muessen aktiv gemeldet werden, nicht nur irgendwo anzeigbar sein.
     * Gnadenfrist (NOTIFY_Grace_Minutes) VOR dem ersten Alarm: manche
     * Verbindungen sind ABSICHTLICH temporaer weg (z.B. Tessie waehrend das
     * Auto schlaeft) -- generisch gehalten, nicht geraetespezifisch, das kann
     * theoretisch jeden Partner betreffen. Erst danach + Debounce ueber
     * LastHealthAlarmActive: meldet nur beim UEBERGANG gesund->ungesund (und
     * einmalig bei der Erholung), nicht bei jedem Zyklus erneut.
     */
    private function checkFederationHealthAlarm()
    {
        $visID = $this->ReadPropertyInteger('NOTIFY_Visualization_ID');
        if ($visID <= 0 || !function_exists('WFC_PushNotification')) {
            return; // Feature deaktiviert oder WebFront-Push nicht verfuegbar
        }

        $health      = $this->GetFederationHealth();
        $isUnhealthy = ($health['healthyCount'] < $health['total']) || ($health['missingCount'] > 0);
        $wasAlarmed  = $this->ReadAttributeBoolean('LastHealthAlarmActive');
        $since       = $this->ReadAttributeInteger('UnhealthySinceTs');
        $graceSec    = max(0, $this->ReadPropertyInteger('NOTIFY_Grace_Minutes')) * 60;

        if (!$isUnhealthy) {
            if ($wasAlarmed) {
                @WFC_PushNotification(
                    $visID,
                    '✅ NRG-Stack Verbund wieder normal',
                    $health['summary'],
                    'info',
                    0
                );
                $this->WriteAttributeBoolean('LastHealthAlarmActive', false);
            }
            if ($since !== 0) {
                $this->WriteAttributeInteger('UnhealthySinceTs', 0);
            }
            return;
        }

        // Ungesund -- Gnadenfrist starten, falls noch nicht laufend.
        if ($since === 0) {
            $this->WriteAttributeInteger('UnhealthySinceTs', time());
            return; // erster Zyklus mit Stoerung: noch nicht alarmieren
        }

        if (!$wasAlarmed && (time() - $since) >= $graceSec) {
            @WFC_PushNotification(
                $visID,
                '⚠️ NRG-Stack Verbund gestoert',
                $health['summary'] . sprintf(' (seit %d Min. anhaltend)', (int)round((time() - $since) / 60)),
                'alert',
                0
            );
            $this->WriteAttributeBoolean('LastHealthAlarmActive', true);
        }
    }

    /**
     * Liefert den zuletzt gefundenen InverterHub-Discovery-Eintrag (aktuell
     * genau ein WR im Verbund, WR1). Wird sowohl fuers Lesen (readState())
     * als auch fuers Schreiben (setGoodweMode()) genutzt — ersetzt die alte
     * manuelle Verknuepfung ueber VAR_SM_, VAR_PV_, VAR_BAT und VAR_WR_EMS_.
     */
    private function getInverterEntry()
    {
        $partners = $this->GetPartners();

        $ihub = (array)($partners['inverterhub'] ?? array());
        if (!empty($ihub)) {
            $i = $ihub[0];
            return array(
                'source'           => 'inverterhub',
                'instanceID'       => $i['instanceID'],
                'controlAuthority' => $i['controlAuthority'] ?? 'none',
                'controllable'     => $i['controllable']     ?? false,
                'pvPowerID'        => $i['pvPowerID']         ?? 0,
                'gridPowerID'      => $i['gridPowerID']       ?? 0,
                'acPowerID'        => $i['acPowerID']         ?? 0,
                'batPowerID'       => $i['batPowerID']        ?? 0,
                'socID'            => $i['socID']             ?? 0,
            );
        }

        return null;
    }

    /**
     * Alle ChargerHub-Instanzen, die das EMS ansteuern darf (managedBy
     * none/ems -> Situation A). Ersetzt die alte manuelle WB{n}_Instance-
     * Verknuepfung auf die dritte-Partei-GO-eCharger-Instanz.
     */
    /**
     * Liefert alle Variablen-IDs, die EMS aktuell aktiv steuert (WR-Steuer-
     * variablen + Wallbox-Freigaben) -- fuer externe Kollisions-Erkennung
     * (z.B. StromGedachts Wenn->Dann-Regeln, die versehentlich dieselbe
     * Stellgroesse schreiben koennten, siehe SUITE.md "Ein Regler pro
     * Stellgroesse"). Nur lesend, loest selbst keine Discovery aus.
     */
    public function GetControlledVariables()
    {
        $result = array();

        $inv = $this->getInverterEntry();
        if ($inv !== null && $inv['source'] === 'inverterhub'
            && ($inv['controlAuthority'] ?? 'none') === 'ems' && ($inv['controllable'] ?? false)) {
            foreach (array('ctl_work_mode', 'ctl_ems_mode', 'ctl_ems_enable', 'ctl_ems_power') as $ident) {
                $vid = $this->findChildVariableIdByIdent($inv['instanceID'], $ident);
                if ($vid > 0) {
                    $result[] = array(
                        'variableID' => $vid,
                        'instanceID' => $inv['instanceID'],
                        'ident'      => $ident,
                        'purpose'    => 'wr_control',
                    );
                }
            }
        }

        foreach ($this->getWritableChargers() as $c) {
            $vid = $c['chargeEnableID'] ?? 0;
            if ($vid > 0) {
                $result[] = array(
                    'variableID' => $vid,
                    'instanceID' => $c['instanceID'] ?? 0,
                    'ident'      => 'ctl_enable',
                    'purpose'    => 'wallbox_control',
                );
            }
        }

        return $result;
    }

    /**
     * Rekursive Ident-Suche ueber Kategorien hinweg -- IPS_GetObjectIDByIdent()
     * findet nur direkte Kinder, Steuervariablen koennen aber in einer
     * Unterkategorie liegen (siehe SUITE.md-Stolperstein 2/7).
     */
    private function findChildVariableIdByIdent($parentId, $ident)
    {
        foreach (@IPS_GetChildrenIDs($parentId) ?: array() as $childId) {
            $obj = IPS_GetObject($childId);
            if ($obj['ObjectIdent'] === $ident) {
                return $childId;
            }
            if ($obj['ObjectType'] == 0) { // Kategorie
                $found = $this->findChildVariableIdByIdent($childId, $ident);
                if ($found > 0) { return $found; }
            }
        }
        return 0;
    }

    private function getWritableChargers()
    {
        $partners = $this->GetPartners();
        $result = array();
        foreach ((array)($partners['chargerhub'] ?? array()) as $chg) {
            $managedBy = $chg['managedBy'] ?? 'none';
            if (in_array($managedBy, array('none', 'ems'), true)) {
                $result[] = $chg;
            }
        }
        return $result;
    }

    /**
     * Pflegt SpecialEventsLog: offene/geschlossene Zeitfenster fuer externe
     * Regeleingriffe, die den Normalbetrieb ueberschreiben (aktuell erkannt:
     * Tibber Grid Rewards -- Tibber uebernimmt die Wallbox-Steuerung, EMS
     * weicht in seine Grid-Rewards-Sonderlogik aus). Wird bei jedem Update()
     * aufgerufen: verlaengert ein bereits offenes Ereignis (setzt 'to' auf
     * jetzt), oder eroeffnet ein neues, wenn die Bedingung neu eintritt.
     * Absichtlich konservativ erweiterbar -- ein zusaetzlicher Ereignistyp
     * braucht nur einen weiteren Eintrag in $conditions.
     */
    private function trackSpecialEvents($s)
    {
        $conditions = array(
            'grid_rewards' => !empty($s['grid_rewards']),
        );

        $log = json_decode($this->ReadAttributeString('SpecialEventsLog'), true);
        if (!is_array($log)) { $log = array(); }
        $now = time();

        foreach ($conditions as $type => $active) {
            $openIdx = null;
            foreach ($log as $i => $ev) {
                if (($ev['type'] ?? '') === $type && ($ev['to'] ?? null) === null) {
                    $openIdx = $i;
                    break;
                }
            }
            if ($active) {
                if ($openIdx !== null) {
                    $log[$openIdx]['to'] = null; // bleibt offen, nur Existenz bestaetigt
                } else {
                    $log[] = array('from' => $now, 'to' => null, 'type' => $type, 'reason' => $type);
                }
            } elseif ($openIdx !== null) {
                $log[$openIdx]['to'] = $now; // Ereignis endet jetzt
            }
        }

        // Deckel bei 500 Eintraegen, aelteste zuerst raus
        if (count($log) > 500) {
            $log = array_slice($log, count($log) - 500);
        }

        $this->WriteAttributeString('SpecialEventsLog', json_encode($log));
    }

    /**
     * Vertrag fuer lernende Module (LFC/PVF): Zeitfenster, in denen ein
     * externer Regeleingriff den Normalbetrieb ueberschrieben hat -- diese
     * Fenster sollten vom Trainingsdatensatz ausgeschlossen werden, da sie
     * kein normales Last-/Erzeugungsverhalten widerspiegeln.
     */
    public function GetSpecialEvents(int $fromTs = 0, int $toTs = 0)
    {
        $log = json_decode($this->ReadAttributeString('SpecialEventsLog'), true);
        if (!is_array($log)) { $log = array(); }

        $events = array();
        foreach ($log as $ev) {
            $evTo = $ev['to'] ?? time(); // noch offenes Ereignis reicht bis "jetzt"
            if ($fromTs > 0 && $evTo < $fromTs) { continue; }
            if ($toTs > 0 && ($ev['from'] ?? 0) > $toTs) { continue; }
            $events[] = array(
                'from'   => $ev['from'] ?? 0,
                'to'     => $ev['to'] ?? null,
                'type'   => $ev['type'] ?? 'unknown',
                'reason' => $ev['reason'] ?? '',
            );
        }

        return array(
            'contractVersion' => '1.0',
            'events'          => $events,
        );
    }

    /**
     * Liest eine Discovery-Variablen-ID sicher aus (analog readVar(), aber
     * ohne Property-Indirektion — die ID kommt direkt aus dem PartnerCache).
     */
    /**
     * Erste installierte PVF-Prognoseinstanz (fuer PVF_GetForecast). Bewusst
     * nicht Teil des PartnerCache/Discover() -- die Prognose ist kein
     * Steuer-/Situations-Vertrag, wird aber genauso "einfach das erste
     * gefundene Modul" gehandhabt wie getInverterEntry().
     */
    private function getPvfInstance()
    {
        if (!function_exists('PVF_GetForecast')) { return 0; }
        $list = IPS_GetInstanceListByModuleID(GUID_PVFORECAST);
        return !empty($list) ? $list[0] : 0;
    }

    private function getStromGedachtInstance()
    {
        if (!function_exists('SGW_GetState')) { return 0; }
        $list = IPS_GetInstanceListByModuleID(GUID_STROMGEDACHT);
        return !empty($list) ? $list[0] : 0;
    }

    /**
     * Aktueller Gruenstrom-Score (0-100, hoeher=gruener), aus StromGedachts
     * GSI-Momentaufnahme (SGW_GetState()['gsi']). Bewusst NUR der Ist-Wert
     * (v1, 29.07.2026) -- StromGedachts SGW_GetForecast() liefert seit heute
     * zwar auch source='gsi' als Zeitfenster-Vorschau, aber genau wie unsere
     * bestehende Preis-Schwellwertlogik (kein volles Preis-Optimierungsmodell)
     * reicht fuer den Anfang ein einfacher Schwellwert auf den Ist-Zustand.
     * Ausbau auf echte Vorausschau (analog PVF/LFC) waere ein spaeterer,
     * separater Schritt. Liefert null, wenn kein StromGedacht installiert
     * ist oder gsi nicht verfuegbar ist (Quelle deaktiviert o.ae.).
     */
    private function getCurrentGreenScore()
    {
        $sgwId = $this->getStromGedachtInstance();
        if ($sgwId <= 0) { return null; }
        $state = SGW_GetState($sgwId);
        if (!is_array($state) || !isset($state['gsi']) || $state['gsi'] === null) {
            return null;
        }
        return (float)$state['gsi'];
    }

    /**
     * Baut ein zusammenhaengendes 192-Slot-Array (heute+morgen, wie bei den
     * Tibber-PT15M-Preisen) aus PVF_GetForecast's p50-Median-Schaetzung in Watt.
     */
    private function getPvfSlotsWatt()
    {
        $pvfId = $this->getPvfInstance();
        if ($pvfId <= 0) { return array(); }
        $today    = PVF_GetForecast($pvfId, 0);
        $tomorrow = PVF_GetForecast($pvfId, 1);
        $todayP50    = $today['p50']    ?? array_fill(0, 96, 0.0);
        $tomorrowP50 = $tomorrow['p50'] ?? array_fill(0, 96, 0.0);
        return array_merge($todayP50, $tomorrowP50); // Index 0-191
    }

    private function getLfcInstance()
    {
        if (!function_exists('LFC_GetEnergyWindow')) { return 0; }
        $list = IPS_GetInstanceListByModuleID(GUID_LFC);
        return !empty($list) ? $list[0] : 0;
    }

    private function getTibberGridRewardInstance()
    {
        if (!function_exists('TIBBERGR_GetPriceCurve')) { return 0; }
        $list = IPS_GetInstanceListByModuleID(GUID_TIBBERGRIDREWARD);
        return !empty($list) ? $list[0] : 0;
    }

    /**
     * Liefert die kombinierte Tibber-Preiskurve (heute+morgen, sobald von
     * Tibber veroeffentlicht) als JSON, EINMAL abgerufen -- korrigiert
     * 20.08.2026 nach Rueckmeldung von Tibber Grid Reward: es gibt KEINEN
     * Tages-Offset-Parameter (anders als urspruenglich angenommen);
     * `TIBBERGR_GetPriceCurve($id)` liefert immer schon beide Tage in einer
     * nach `start` sortierten Liste, ein zweites Argument wuerde von PHP
     * einfach stillschweigend ignoriert (kein Fehler, aber wirkungslos --
     * haette unbemerkt IMMER denselben kombinierten Satz zurueckgeliefert,
     * unabhaengig vom angeblichen Offset). parsePT15M() filtert die
     * kombinierte Liste anschliessend selbst nach Kalendertag.
     */
    private function getTibberCombinedCurveJson()
    {
        $tibberId = $this->getTibberGridRewardInstance();
        if ($tibberId > 0) {
            try {
                $curve = TIBBERGR_GetPriceCurve($tibberId);
                if (is_array($curve) && !empty($curve)) {
                    return json_encode($curve);
                }
            } catch (Throwable $e) {
                $this->emsLog(EMS_LOG_BASIC, 'TIBBERGR_GetPriceCurve fehlgeschlagen: ' . $e->getMessage());
            }
        }
        return '';
    }

    /**
     * Liefert die heutigen PT15M-Preise als JSON, fuer BuildDayPlan().
     * Bevorzugt: automatisch ueber die kombinierte Tibber-Grid-Reward-Kurve
     * (siehe getTibberCombinedCurveJson()) -- das ist der gleiche Automatik-
     * Anspruch, den EMS fuer Batterie/PV via InverterHub schon einloest
     * (20.08.2026, Dietmars berechtigter Einwand: "warum ein Verbund, wenn
     * ich Luecken selbst manuell verknuepfen muss"). Faellt nur zurueck auf
     * die manuelle Property VAR_TIB_PT15M_Today, wenn keine Tibber-Grid-
     * Reward-Instanz installiert ist oder der Aufruf nichts Brauchbares
     * liefert (z.B. andere Tibber-Anbindung, eigene Quelle).
     */
    private function getPT15MTodayJson()
    {
        $combined = $this->getTibberCombinedCurveJson();
        if (!empty($combined)) { return $combined; }
        return (string)$this->readVar('VAR_TIB_PT15M_Today', '');
    }

    /**
     * Pro-Feld-Status fuer die "Netzmesspunkte"-Fallback-Felder (20.08.2026,
     * Dietmars Praezisierung: nicht ein Pauschalhinweis "schau oben", sondern
     * direkt hinter jedem einzelnen Auswahlfeld). Ehrlich nach dem, was der
     * Code tatsaechlich tut, nicht pauschal "automatisch" behauptet:
     * - Netz Gesamtleistung hat einen echten Automatik-Pfad ueber InverterHub
     *   (readState(), gridPowerID) -- hier ehrlich ✅/⚠️/ℹ️ je nach Fund.
     * - Phasenwerte (L1-L3), Frequenz, SmartMeter-Status haben AKTUELL KEINEN
     *   Automatik-Pfad im Code -- das waere gelogen als "automatisch
     *   verbunden" zu zeigen. Klar als "keine Automatik vorgesehen" markiert,
     *   statt es zu verschweigen.
     */
    /**
     * Aktueller Batterie-SOC (%), bevorzugt automatisch ueber InverterHub
     * (socID), sonst manuelle Bat1/Bat2-Fallback-Felder -- derselbe Automatik-
     * Pfad wie in readState(), aber als eigenstaendige Methode, weil
     * BuildDayPlan() bislang direkt an den manuellen Feldern vorbeigebaut
     * hatte (20.08.2026, gleicher Fehlertyp wie die PT15M-Preise: neues
     * Feature nutzte die vorhandene Discovery nicht). readState() bleibt
     * unveraendert (dort schon korrekt, inkl. Leistungswerten).
     */
    private function getCurrentBatterySoc()
    {
        $inv = $this->getInverterEntry();
        if ($inv !== null && ($inv['socID'] ?? 0) > 0) {
            return (float)$this->readDiscoveredVar($inv['socID'], 0);
        }
        $soc = (float)$this->readVar('VAR_BAT1_SOC', 0);
        if ($this->ReadPropertyInteger('BAT_String_Count') >= 2) {
            $soc = ($soc + (float)$this->readVar('VAR_BAT2_SOC', 0)) / 2.0;
        }
        return $soc;
    }

    /**
     * Liefert die morgigen PT15M-Preise als JSON, analog getPT15MTodayJson()
     * (20.08.2026, fuer die erweiterte Zwei-Tage-Planung). Nutzt dieselbe
     * kombinierte Kurve wie "heute" (ein Aufruf deckt beide Tage ab, siehe
     * getTibberCombinedCurveJson()) -- parsePT15M() filtert anschliessend
     * selbst nach Kalendertag (Tages-Offset-Parameter). Faellt bei fehlendem
     * Erfolg auf die manuelle Fallback-Property VAR_TIB_PT15M_Tomorrow
     * zurueck (bereits im Formular vorhanden). Morgen-Preise sind i. d. R.
     * erst ab ca. 13-14 Uhr von Tibber veroeffentlicht -- vorher liefert
     * parsePT15M() fuer alle Slots `null` (korrekt: "keine Daten").
     */
    private function getPT15MTomorrowJson()
    {
        $combined = $this->getTibberCombinedCurveJson();
        if (!empty($combined)) { return $combined; }
        return (string)$this->readVar('VAR_TIB_PT15M_Tomorrow', '');
    }

    /**
     * Kernentscheidung fuer EINEN Viertelstunden-Slot -- ausgelagert aus
     * BuildDayPlan() (20.08.2026), damit dieselbe Logik unveraendert sowohl
     * fuer HEUTE (ab dem aktuellen Slot) als auch fuer die erweiterte
     * MORGEN-Planung (Dashboard-Visualisierung, siehe SUITE.md) wiederverwendet
     * werden kann, ohne den Entscheidungscode zu duplizieren. $price darf
     * `null` sein (keine Daten -- siehe SUITE.md Stolperfalle 15), dann wird
     * IMMER Automatik geplant. $ctx buendelt alle Konfigurationswerte, die
     * fuer jeden Slot gleich bleiben (siehe Aufrufer fuer den Aufbau).
     * Liefert ['plan' => [...], 'soc' => $neuerSoc].
     */
    private function simulateDaySlot($slot, $price, $pvW, $soc, array $cheapRank, array $ctx, $futureMaxPrice = null)
    {
        $hourOfSlot = (int)($slot / 4);

        if ($ctx['enwgActive'] && $this->slotInEnwgWindow($hourOfSlot, $ctx['enwgStartH'], $ctx['enwgEndH'])) {
            // §14a wird zur Laufzeit reaktiv erzwungen (optimize()-Branch
            // 1, Netzbetreiber-Pflicht, kein Preis-Vorschlag) -- hier nur
            // informativ, damit die Plan-Anzeige nicht widerspricht.
            $missingKwh = max(0.0, ($ctx['socTargetNight'] - $soc) / 100.0 * $ctx['capKwh']);
            $soc = min(100.0, $soc + (min($missingKwh, $ctx['chargeKw'] * 0.25) / max(0.001, $ctx['capKwh']) * 100.0));
            return array('plan' => array('op' => EMS_OP_NET_CHARGE, 'gw' => GW_MODE_AC_IMPORT, 'power' => (int)$ctx['maxW'],
                'reason' => '§14a-Fenster (Vorrang vor Plan)', 'price' => $price, 'soc' => round($soc, 1)), 'soc' => $soc);
        }

        $loadW    = $ctx['avgHouseW'];
        $surplusW = $pvW - $loadW;

        if ($price !== null && $price < 0 && $soc < 99.5) {
            // Negativpreis: immer laden, man wird dafuer bezahlt -- ersetzt
            // die alte separate PlanNegativePriceExport()-Funktion, ist
            // jetzt einfach eine weitere Regel im selben Tagesplan.
            $missingKwh = max(0.0, (100.0 - $soc) / 100.0 * $ctx['capKwh']);
            $soc = min(100.0, $soc + (min($missingKwh, $ctx['chargeKw'] * 0.25) / max(0.001, $ctx['capKwh']) * 100.0));
            return array('plan' => array('op' => EMS_OP_NET_CHARGE, 'gw' => GW_MODE_AC_IMPORT, 'power' => (int)$ctx['maxW'],
                'reason' => sprintf('Negativpreis %.2fct -- immer laden', $price * 100), 'price' => $price, 'soc' => round($soc, 1)), 'soc' => $soc);
        }

        // Preisbewusste Sicherheitsmarge (20.08.2026, Dietmars Einwand: das
        // reine energetische Tagesziel fragt nur "reicht es bis morgen?",
        // nicht "lohnt es sich, JETZT zu exportieren, wenn spaeter am Tag
        // noch eine teurere Stunde kommt?"). Steht im verbleibenden
        // Preishorizont eine Stunde an, die spuerbar teurer ist als die
        // Entladen-Schwelle, wird das Tagesziel fuer DIESEN Slot temporaer
        // angehoben -- der zusaetzliche Puffer ist gedeckelt (max. 15
        // Prozentpunkte), damit daraus kein "nie mehr exportieren" wird.
        // $futureMaxPrice ist der teuerste bekannte Preis von diesem Slot an
        // bis zum Ende des Preishorizonts (siehe BuildDayPlan()).
        $priceBonus = 0.0;
        if ($futureMaxPrice !== null && $futureMaxPrice > $ctx['thDischarge']) {
            $priceBonus = min(15.0, ($futureMaxPrice - $ctx['thDischarge']) * 100.0);
        }
        $effectiveTargetDay = min(100.0, $ctx['socTargetDay'] + $priceBonus);

        if ($surplusW > $ctx['fcMinPower']) {
            if ($soc < ($effectiveTargetDay - $ctx['hystSoc'])) {
                $gainKwh = $surplusW / 1000.0 * 0.25;
                $soc = min(100.0, $soc + ($gainKwh / max(0.001, $ctx['capKwh']) * 100.0));
                $reason = $priceBonus > 0.5
                    ? sprintf('PV-Ueberschuss %.0fW -> Batterie (Ziel %.0f%%, +%.0f%% wegen teurer Stunde später)', $surplusW, $ctx['socTargetDay'], $priceBonus)
                    : sprintf('PV-Ueberschuss %.0fW -> Batterie (Ziel %.0f%%)', $surplusW, $ctx['socTargetDay']);
                return array('plan' => array('op' => EMS_OP_PV_SELFUSE, 'gw' => GW_MODE_CHARGE_PV, 'power' => 0,
                    'reason' => $reason, 'price' => $price, 'soc' => round($soc, 1)), 'soc' => $soc);
            }
            return array('plan' => array('op' => EMS_OP_EXPORT, 'gw' => GW_MODE_AC_EXPORT, 'power' => (int)$pvW,
                'reason' => sprintf('Akku am Ziel (SOC=%.0f%%), PV-Vollernte %.0fW exportieren', $soc, $pvW),
                'price' => $price, 'soc' => round($soc, 1)), 'soc' => $soc);
        }

        // Keine Preisdaten fuer diesen Slot -- ab hier braucht JEDE
        // Entscheidung einen echten Preis (Negativpreis-/Export-/
        // Ladeschwellen). Live-Fund 20.08.2026: frueher fuellte
        // parsePT15M() fehlende Slots mit 0.0 -- das wurde hier als
        // "Bezug 0ct, guenstiger als jede Einspeiseverguetung"
        // fehlinterpretiert und plante faelschlich "Export" fuer
        // Abendstunden ohne Tibber-Daten. Ohne echten Preis ist Automatik
        // die einzig ehrliche Entscheidung -- der WR entscheidet dann
        // selbst, kein geratener Preis-Vorschlag.
        if ($price === null) {
            return array('plan' => array('op' => EMS_OP_AUTO, 'gw' => GW_MODE_AUTO, 'power' => 0,
                'reason' => 'Keine Preisdaten fuer diesen Slot -- Automatik', 'price' => null, 'soc' => round($soc, 1)), 'soc' => $soc);
        }

        // Kein PV-Ueberschuss -- Batterie vs. Netz.
        if ($ctx['feedTariff'] > ($price + 0.001) && $soc > ($ctx['socMin'] + $ctx['socReserve'] + $ctx['hystSoc'])) {
            // Bezug ist JETZT billiger als die Einspeiseverguetung -- die
            // gespeicherte Energie ist mehr wert, wenn sie exportiert
            // wird, als wenn sie den (billigeren) Netzbezug ersetzt.
            // Hausverbrauch wird stattdessen guenstig aus dem Netz
            // gedeckt (Dietmars Vorgabe, 19.08.2026 -- typischer
            // Mittags-Fall bei niedrigen Spotpreisen).
            $lossKwh = $loadW / 1000.0 * 0.25;
            $soc = max(0.0, $soc - ($lossKwh / max(0.001, $ctx['capKwh']) * 100.0));
            return array('plan' => array('op' => EMS_OP_EXPORT, 'gw' => GW_MODE_AC_EXPORT, 'power' => 0,
                'reason' => sprintf('Bezug %.2fct < Einspeiseverguetung %.2fct -- Batterie exportiert, Haus aus Netz', $price * 100, $ctx['feedTariff'] * 100),
                'price' => $price, 'soc' => round($soc, 1)), 'soc' => $soc);
        }

        $missingKwh  = max(0.0, ($ctx['socTargetNight'] - $soc) / 100.0 * $ctx['capKwh']);
        $neededSlots = ($ctx['chargeKw'] > 0) ? (int)ceil($missingKwh / ($ctx['chargeKw'] * 0.25)) : 0;
        $rank        = $cheapRank[$slot] ?? PHP_INT_MAX;
        if ($neededSlots > 0 && $rank < $neededSlots && $price < ($ctx['thCharge'] + 0.05)) {
            $soc = min(100.0, $soc + (min($missingKwh, $ctx['chargeKw'] * 0.25) / max(0.001, $ctx['capKwh']) * 100.0));
            return array('plan' => array('op' => EMS_OP_NET_CHARGE, 'gw' => GW_MODE_AC_IMPORT, 'power' => (int)$ctx['maxW'],
                'reason' => sprintf('Rang %d der guenstigsten Slots (%.2fct), Nachtziel %.0f%% noch offen', $rank + 1, $price * 100, $ctx['socTargetNight']),
                'price' => $price, 'soc' => round($soc, 1)), 'soc' => $soc);
        }

        // ENTFERNT 20.08.2026 (Live-Fund via Dashboard-Diagramm-Reason-Text,
        // von Dietmar selbst entdeckt): es gab hier frueher einen zweiten
        // Export-Zweig ("Export: Preis > thExport UND Preis > Einspeise-
        // verguetung"), der bei Spotpreisen zwischen thExport (Default 0.20)
        // und thDischarge (Default 0.25) faelschlich EXPORT statt ENTLADEN
        // waehlte -- und das, obwohl thExport < thDischarge ist, er also
        // JEDES Mal VOR dem Entladen-Zweig griff. Bei fester Einspeise-
        // verguetung (wie bei Dietmars Anlage, 18.36ct) bringt Export IMMER
        // nur die feste Verguetung, unabhaengig vom Spotpreis -- ein hoher
        // Spotpreis macht Export nicht wertvoller. Sobald der Spotpreis ueber
        // der Verguetung liegt, ist Entladen (teuren Netzbezug vermeiden)
        // wirtschaftlich immer mindestens gleichwertig, meist besser. Der
        // einzige korrekte Export-Ausloeser bleibt der Zweig oben (Bezug <
        // Einspeiseverguetung -- Batterie exportiert, Haus aus Netz).
        if ($price > $ctx['thDischarge'] && $soc > ($ctx['socMin'] + $ctx['socReserve'] + $ctx['hystSoc'])) {
            $lossKwh = $loadW / 1000.0 * 0.25;
            $soc = max(0.0, $soc - ($lossKwh / max(0.001, $ctx['capKwh']) * 100.0));
            return array('plan' => array('op' => EMS_OP_DISCHARGE, 'gw' => GW_MODE_DISCHARGE, 'power' => 0,
                'reason' => sprintf('Bezug %.2fct teuer -- Eigenverbrauch aus Batterie', $price * 100),
                'price' => $price, 'soc' => round($soc, 1)), 'soc' => $soc);
        }

        return array('plan' => array('op' => EMS_OP_AUTO, 'gw' => GW_MODE_AUTO, 'power' => 0,
            'reason' => sprintf('Automatik: Bezug %.2fct guenstiger als Alternative', $price * 100),
            'price' => $price, 'soc' => round($soc, 1)), 'soc' => $soc);
    }

    /**
     * Oeffentlicher Abruf fuer Partnermodule (20.08.2026, Dietmars Wunsch:
     * "Planung im Dashboard sehen, zusammen mit SOC und Preis"). Liefert
     * heute (ab jetzt) + morgen als EINE zusammenhaengende Liste, je Slot
     * mit Uhrzeit, Entscheidung, Preis und simuliertem SOC -- fuer eine
     * externe Visualisierung (z. B. Dashboard-Diagramm), NICHT fuer den
     * nativen IPS-Kalender (der bleibt architektonisch auf einen Tag
     * begrenzt, siehe SUITE.md). contractVersion 1.0, additiv erweiterbar.
     */
    /**
     * Liefert 'price' bewusst in CT/KWH nach aussen (20.08.2026, Fix nach
     * Ruecksprache mit Dashboard) -- INTERN rechnet BuildDayPlan()/
     * simulateDaySlot() seit 0.22.2 in EUR/kWh (passend zu EMS' eigenen
     * Preisschwellen-Properties), aber der externe Vertrag soll konsistent
     * zur Tibber-Grid-Reward-Konvention bleiben (ct/kWh, siehe SUITE.md
     * Stolperfalle 16 + `TIBBERGR_GetPriceCurve`). Ohne diese Rueckumrechnung
     * haette GetDayPlan() nach dem 0.22.2-Fix ploetzlich einen zehnfach zu
     * kleinen Wert geliefert (0.2348 statt 23.48) -- Dashboard hatte parallel
     * ihren eigenen (unabhaengigen) *100-Bug entfernt, beide Faktor-100-
     * Fehler haetten sich sonst gegenseitig unbemerkt aufgehoben oder addiert.
     */
    public function GetDayPlan()
    {
        $today    = json_decode($this->ReadAttributeString('DayPlan'), true) ?: array();
        $tomorrow = json_decode($this->ReadAttributeString('DayPlanTomorrow'), true) ?: array();
        $out = array();
        $baseToday    = strtotime('today');
        $baseTomorrow = strtotime('tomorrow');
        foreach ($today as $slot => $entry) {
            $entry['time'] = $baseToday + $slot * 900;
            if (isset($entry['price']) && $entry['price'] !== null) { $entry['price'] = round($entry['price'] * 100, 2); }
            $out[] = $entry;
        }
        foreach ($tomorrow as $slot => $entry) {
            $entry['time'] = $baseTomorrow + $slot * 900;
            if (isset($entry['price']) && $entry['price'] !== null) { $entry['price'] = round($entry['price'] * 100, 2); }
            $out[] = $entry;
        }
        return array('contractVersion' => '1.0', 'priceUnit' => 'ct/kWh', 'slots' => $out);
    }

    /**
     * Status-Zeile fuer das Bat1-SOC-Fallback-Feld — mit roter Pflichtfeld-
     * Kennzeichnung, wenn das Feld wirklich unabdingbar ist (Dietmars
     * Praezisierung 20.08.2026: nicht nur "nicht automatisch verbunden"
     * sagen, sondern wenn es ein echtes Pflichtfeld ist, das auch in Rot
     * hervorheben). Pflicht bedeutet hier konkret: BAT_Active ist an, aber
     * WEDER InverterHub liefert einen SOC NOCH ist das manuelle Feld
     * gesetzt -- dann rechnet EMS intern mit SOC=0%, was Tagesplan und
     * optimize() zu falschen Entscheidungen verleiten kann (z.B. unnoetiges
     * Nachladen, weil die Batterie faelschlich als leer gilt).
     */
    /**
     * Normalisiert eine Status-Helper-Rueckgabe (einfacher String ODER schon
     * fertiges Label-Array mit 'color') zu einem einsatzbereiten form.json-
     * Label-Element. Vermeidet, dass jeder Status-Helper (getPT15MStatusLine,
     * getGridFieldStatusLine, getBatterySocStatusLine, ...) selbst das
     * Array-Wrapping wiederholen muss -- nur die roten Pflichtfeld-Faelle
     * liefern direkt ein Array mit 'color' (20.08.2026).
     */
    private function statusLabel($textOrArray)
    {
        if (is_array($textOrArray)) {
            return array('type' => 'Label', 'caption' => $textOrArray['caption'], 'color' => $textOrArray['color']);
        }
        return array('type' => 'Label', 'caption' => $textOrArray);
    }

    /**
     * Prominente Kopfzeile fuers "Verbund-Status"-Panel, im Stil einer
     * anderen NRG-Stack-Geraetesuche, die Dietmar besser gefallen hat als
     * EMS' bisheriger technischer Fliesstext (20.08.2026, Screenshot-Vorbild:
     * "✅ 12 Geräte gefunden (zuletzt 16:25:41 Uhr).") -- grosses Icon,
     * eine Kernzahl, Zeitstempel der letzten Suche, kein Aufzaehlungssatz.
     * Der bisherige technische Fliesstext (EMS_Partners, je Partnermodul-Typ
     * mit Zahl) bleibt als zweite, kleinere Zeile fuer Diagnosezwecke
     * erhalten -- nur nicht mehr als Kopfzeile. Referenz fuer die neue
     * Verbund-Konvention "Einheitliche Verbund-Status-Kopfzeile", siehe
     * SUITE.md.
     */
    private function getDiscoverySummaryLine()
    {
        $ts = $this->ReadAttributeInteger('LastDiscoveryTs');
        if ($ts === 0) {
            return 'ℹ️ Noch nicht gesucht — Button unten drücken.';
        }
        $partners = json_decode($this->ReadAttributeString('PartnerCache'), true) ?: array();
        $count = 0;
        foreach ($partners as $list) {
            $count += is_array($list) ? count($list) : 0;
        }
        $icon = $count > 0 ? '✅' : '⚠️';
        return sprintf('%s %d Partnermodul-Instanz(en) gefunden (zuletzt %s Uhr).', $icon, $count, date('H:i:s', $ts));
    }

    private function getBatterySocStatusLine()
    {
        $twoStrings = $this->ReadPropertyInteger('BAT_String_Count') >= 2;
        $inv = $this->getInverterEntry();
        if ($inv !== null && ($inv['socID'] ?? 0) > 0) {
            return sprintf(
                '✅ Automatisch verbunden: InverterHub #%d liefert den Batterie-SOC%s — Felder unten werden ignoriert.',
                $inv['instanceID'],
                $twoStrings ? ' (InverterHub liefert bereits EINEN über beide Batteriestrings aggregierten Wert, gilt also auch für Batteriestring 2)' : ''
            );
        }
        if (!$this->ReadPropertyBoolean('BAT_Active')) {
            return 'ℹ️ Batteriespeicher ist deaktiviert — Feld unten wird nicht benötigt.';
        }
        if ($this->ReadPropertyInteger('VAR_BAT1_SOC') > 0) {
            return '⚠️ Keine InverterHub-Instanz gefunden — Feld unten als manueller Fallback aktiv.';
        }
        return array(
            'type'    => 'Label',
            'caption' => '⛔ PFLICHTFELD: Keine InverterHub-Instanz gefunden UND kein Fallback verknüpft — EMS rechnet sonst mit SOC=0% und trifft falsche Entscheidungen (z. B. unnötiges Nachladen). Bitte unten verknüpfen.',
            'color'   => 0xFF0000,
        );
    }

    /**
     * Status-Zeilen fuer das "Wechselrichter & PV"-Fallback-Panel (20.08.2026,
     * Dietmars Folgefrage: gleiche Unsicherheit wie bei Netzmesspunkte). Beim
     * Nachschauen zusaetzlich entdeckt: mehrere Felder dort (Export-Enable/
     * -Limit, PV-Tageserzeugung, MPPT1-3, WR-Temperatur x2, WR-Diagnose)
     * werden vom Code AKTUELL NIRGENDS gelesen -- weder automatisch noch
     * manuell, reine Karteileichen aus einer frueheren Version. Das muss die
     * Statuszeile ehrlich sagen ("wird nicht ausgewertet"), nicht als
     * "manueller Fallback" verkaufen -- sonst verknuepft der Nutzer etwas,
     * das nie etwas bewirkt.
     */
    private function getInverterFieldStatusLine($field)
    {
        $inv = $this->getInverterEntry();
        switch ($field) {
            case 'control': // VAR_WR_EMS_Mode + VAR_WR_EMS_Power (setGoodweMode())
                if ($inv === null) {
                    return 'ℹ️ Keine InverterHub-Instanz gefunden — Felder unten werden für die Steuerung benötigt.';
                }
                $authority = $inv['controlAuthority'] ?? 'none';
                if ($authority === 'ems' && ($inv['controllable'] ?? false)) {
                    return sprintf(
                        '✅ Automatisch verbunden: EMS steuert InverterHub #%d direkt (ctl_ems_*) — Felder unten werden ignoriert.',
                        $inv['instanceID']
                    );
                }
                return sprintf(
                    '⚠️ InverterHub #%d gefunden, aber Steuerhoheit liegt nicht bei EMS ("%s") — EMS schreibt hier bewusst nichts, auch nicht über die Felder unten.',
                    $inv['instanceID'], $authority
                );
            case 'pv_total':
                if ($inv !== null && ($inv['pvPowerID'] ?? 0) > 0) {
                    return sprintf('✅ Automatisch verbunden: InverterHub #%d liefert die PV-Gesamtleistung — Feld unten wird ignoriert.', $inv['instanceID']);
                }
                return 'ℹ️ Keine automatische PV-Gesamtleistung gefunden — Feld unten wird benötigt.';
            case 'wr_total':
                if ($inv !== null && ($inv['acPowerID'] ?? 0) > 0) {
                    return sprintf('✅ Automatisch verbunden: InverterHub #%d liefert die WR-Gesamtleistung — Feld unten wird ignoriert.', $inv['instanceID']);
                }
                return 'ℹ️ Keine automatische WR-Gesamtleistung gefunden — Feld unten wird benötigt.';
            case 'unused':
                return '🚫 Wird von EMS aktuell nicht ausgewertet (weder automatisch noch über dieses Feld) — eine Verknüpfung hat derzeit keine Wirkung.';
        }
        return '';
    }

    private function getGridFieldStatusLine($field)
    {
        if ($field === 'total') {
            $inv = $this->getInverterEntry();
            if ($inv === null) {
                return 'ℹ️ Keine InverterHub-Instanz gefunden — Feld unten wird benötigt.';
            }
            if (($inv['gridPowerID'] ?? 0) > 0) {
                return sprintf(
                    '✅ Automatisch verbunden: InverterHub #%d liefert die Netz-Gesamtleistung — Feld unten wird ignoriert.',
                    $inv['instanceID']
                );
            }
            return sprintf(
                '⚠️ InverterHub #%d gefunden, liefert aber keine Netzleistung — Feld unten als Fallback aktiv.',
                $inv['instanceID']
            );
        }
        return 'ℹ️ EMS liest diesen Wert aktuell nicht automatisch von einem Partnermodul — nur diese manuelle Verknüpfung wird genutzt, falls gesetzt.';
    }

    /**
     * Menschenlesbare Statuszeile fuer das Formular: zeigt an, ob und WOMIT
     * das Fallback-Feld VAR_TIB_PT15M_Today gerade automatisch versorgt wird
     * (Dietmars Einwand 20.08.2026: ein Auswahlfeld allein sagt nicht, ob es
     * durch eine Automatik ueberholt/unnoetig ist). Verbundweites Muster,
     * siehe SUITE.md "Formular-Konvention: Status neben manuellen Fallback-
     * Feldern" -- jedes Modul mit einem manuellen SelectVariable-Fallback
     * neben einer automatischen Discovery sollte diese Zeile analog bauen.
     */
    private function getPT15MStatusLine()
    {
        $tibberId = $this->getTibberGridRewardInstance();
        if ($tibberId <= 0) {
            return 'ℹ️ Keine Tibber-Grid-Reward-Instanz gefunden — Feld unten wird benötigt.';
        }
        try {
            $curve = TIBBERGR_GetPriceCurve($tibberId);
            if (is_array($curve) && !empty($curve)) {
                $name = IPS_GetName($tibberId);
                return sprintf(
                    '✅ Automatisch verbunden: Tibber Grid Reward #%d ("%s"), %d Preis-Slots geladen — Feld unten wird ignoriert.',
                    $tibberId, $name, count($curve)
                );
            }
            return sprintf(
                '⚠️ Tibber Grid Reward #%d gefunden, liefert aber gerade keine Preiskurve — Feld unten als Fallback aktiv.',
                $tibberId
            );
        } catch (Throwable $e) {
            return sprintf(
                '⚠️ Tibber Grid Reward #%d gefunden, TIBBERGR_GetPriceCurve fehlgeschlagen (%s) — Feld unten als Fallback aktiv.',
                $tibberId, $e->getMessage()
            );
        }
    }

    /**
     * Erwarteter PV-Produktionsstart morgen frueh (erster Slot mit echter
     * Erzeugung statt Rauschen) aus PVF_GetForecast(offset=1)['mean'].
     * Schwellwert-Formel von Prognose/LFC uebernommen (deren Build 44/45):
     * 10W absolut ODER 2% des Tagesmaximums, je nachdem was groesser ist --
     * bewusst 'mean' statt 'p50', konsistent zu LFC_GetEnergyWindow's
     * eigener kwh-Berechnung (ebenfalls 'mean'-basiert).
     */
    private function getPvStartTomorrowTs()
    {
        $pvfId = $this->getPvfInstance();
        if ($pvfId <= 0) { return 0; }
        $tomorrow = PVF_GetForecast($pvfId, 1);
        $mean = $tomorrow['mean'] ?? null;
        if (!is_array($mean) || empty($mean)) { return 0; }
        $dayMax = max($mean);
        if ($dayMax <= 0) { return 0; }
        $floor = max(10.0, 0.02 * $dayMax);
        $midnight = strtotime('tomorrow midnight');
        foreach ($mean as $i => $w) {
            if ($w >= $floor) {
                return $midnight + $i * 900; // 96 Slots a 15 Min
            }
        }
        return 0;
    }

    /**
     * Energiebasiertes Batterie-Tagesziel statt starrem SOC%: Wieviel
     * Energie wird bis zum PV-Start morgen benoetigt (LFC_GetEnergyWindow),
     * umgerechnet auf den noetigen SOC + Sicherheitsmarge. Dietmars
     * Vorgabe 27.07.2026: "die enthaltene Energie muss nur bis zum
     * naechsten Tag reichen, nicht ein fester SOC%". Faellt auf den
     * statischen BAT_SOC_Target_Day-Wert zurueck, wenn LFC/PVF fehlen,
     * BAT_SOC_Dynamic_Target deaktiviert ist, oder die Prognose den
     * Zeitraum nicht voll abdeckt (coverage < 1.0 -- vor Prognose' Fix
     * vom 27.07.2026 haette eine kaputte LFC-Instanz hier faelschlich
     * coverage=1.0 mit kwh=0.0 gemeldet und ein zu niedriges Ziel erzeugt).
     */
    private function getDynamicSocTargetDay()
    {
        $staticTarget = (float)$this->ReadPropertyInteger('BAT_SOC_Target_Day');
        if (!$this->ReadPropertyBoolean('BAT_SOC_Dynamic_Target')) {
            return $staticTarget;
        }

        $lfcId = $this->getLfcInstance();
        if ($lfcId <= 0) { return $staticTarget; }

        $pvStartTs = $this->getPvStartTomorrowTs();
        if ($pvStartTs <= time()) { return $staticTarget; }

        $window   = LFC_GetEnergyWindow($lfcId, time(), $pvStartTs);
        $kwh      = $window['kwh']      ?? null;
        $coverage = $window['coverage'] ?? 0;
        if ($kwh === null || $coverage < 1.0) {
            return $staticTarget;
        }

        $capacity = (float)$this->ReadPropertyFloat('BAT_Capacity_kWh');
        if ($capacity <= 0) { return $staticTarget; }

        $margin = (float)$this->ReadPropertyInteger('BAT_SOC_Safety_Margin_Pct');
        $dynamicTarget = ($kwh / $capacity * 100.0) + $margin;

        return max(0.0, min(100.0, $dynamicTarget));
    }

    private function readDiscoveredVar($varId, $default)
    {
        if ($varId <= 0 || !IPS_VariableExists($varId)) { return $default; }
        return GetValue($varId);
    }

    // ----------------------------------------------------------------
    //  Situation A/B — Steuerhoheit je Geraet
    //  (siehe Memory ems-prioritaetshierarchie: Situation A = EMS besitzt
    //  den Schreibkanal, echte interne Prioritaet; Situation B = ein
    //  externer Akteur besitzt den Schreibkanal, EMS erkennt nur und
    //  weicht zurueck, uebersteuert NIE)
    // ----------------------------------------------------------------

    /**
     * Wertet die zuletzt per Discover() gefundenen Partnerdaten aus und
     * bestimmt je steuerbarem Geraet, ob das EMS schreiben darf
     * (Situation A) oder nur beobachten muss (Situation B), inkl. Quelle.
     * Liest NICHTS live nach — arbeitet auf dem PartnerCache-Attribut,
     * damit diese Funktion beliebig oft ohne Netzwerk-/Modbus-Last
     * aufgerufen werden kann.
     */
    public function GetSituation()
    {
        $partners = $this->GetPartners();
        $situation = array();

        // InverterHub: controlAuthority direkt aus dem Vertrag (heute gebaut)
        foreach ((array)($partners['inverterhub'] ?? array()) as $inv) {
            $authority = $inv['controlAuthority'] ?? 'none';
            $situation[] = array(
                'domain'     => 'inverter',
                'instanceID' => $inv['instanceID'] ?? 0,
                'label'      => $inv['manufacturer'] ?? 'Wechselrichter',
                'situation'  => ($authority === 'ems') ? 'A' : 'B',
                'source'     => $authority,
                'writable'   => ($authority === 'ems') && (bool)($inv['controllable'] ?? false),
            );
        }

        // ChargerHub: managedBy-Feld (none/ems = Situation A, alles andere = B)
        foreach ((array)($partners['chargerhub'] ?? array()) as $chg) {
            $managedBy = $chg['managedBy'] ?? 'none';
            $isEmsOwned = in_array($managedBy, array('none', 'ems'), true);
            $situation[] = array(
                'domain'     => 'wallbox',
                'instanceID' => $chg['instanceID'] ?? 0,
                'label'      => $chg['label'] ?? 'Wallbox',
                'situation'  => $isEmsOwned ? 'A' : 'B',
                'source'     => $managedBy,
                'writable'   => $isEmsOwned,
            );
        }

        // HeishaMon: keine externe Fremdsteuerung bekannt -> immer Situation A,
        // aber das EMS steuert bewusst nicht aktiv (siehe README-Sicherheitshinweis:
        // haeufige Schreibzugriffe koennen den EEPROM schaedigen).
        foreach ((array)($partners['heishamon'] ?? array()) as $hp) {
            $situation[] = array(
                'domain'     => 'heatpump',
                'instanceID' => $hp['instanceID'] ?? 0,
                'label'      => $hp['Caption'] ?? 'Wärmepumpe',
                'situation'  => 'A',
                'source'     => 'ems',
                'writable'   => false, // bewusst inaktiv, siehe README
            );
        }

        // Tessie: Situation B, sobald Tibber Grid Rewards fuer dieses Fahrzeug
        // die Ladehoheit hat (kein direkter Schreibkanal des EMS aufs Fahrzeug
        // selbst — Fahrzeugladung laeuft ohnehin ueber den Charger, nicht Tessie).
        foreach ((array)($partners['tessie'] ?? array()) as $veh) {
            $isGridReward = (bool)($veh['scheduledChargingActive'] ?? false);
            $situation[] = array(
                'domain'     => 'vehicle',
                'instanceID' => $veh['instanceID'] ?? 0,
                'label'      => $veh['name'] ?? 'Fahrzeug',
                'situation'  => $isGridReward ? 'B' : 'A',
                'source'     => $isGridReward ? 'tibber' : 'ems',
                'writable'   => false, // EMS steuert das Fahrzeug nicht direkt, nur den Charger
            );
        }

        // Tibber: GetActiveControls zeigt explizit, welche Geraete Tibber
        // gerade fremdsteuert (Situation B) — als eigener Eintrag je aktivem
        // Eingriff, informativ fuers Log/die Kachel.
        foreach ((array)($partners['tibber'] ?? array()) as $tib) {
            foreach ((array)($tib['activeControls'] ?? array()) as $ctrl) {
                $situation[] = array(
                    'domain'     => $ctrl['type'] ?? 'unknown',
                    'instanceID' => $ctrl['deviceId'] ?? 0,
                    'label'      => $ctrl['name'] ?? 'Tibber-gesteuertes Gerät',
                    'situation'  => 'B',
                    'source'     => 'tibber',
                    'writable'   => false,
                );
            }
        }

        return $situation;
    }

    public function GetStatus()
    {
        $modeNames = array(
            EMS_OP_AUTO        => 'Automatik',
            EMS_OP_PV_SELFUSE  => 'PV-Eigenverbrauch',
            EMS_OP_NET_CHARGE  => 'Netz-Laden',
            EMS_OP_DISCHARGE   => 'Entladen',
            EMS_OP_STANDBY     => 'Bereitschaft',
            EMS_OP_EXPORT      => 'Export',
            EMS_OP_BACKUP      => 'Notbetrieb',
            EMS_OP_GRIDREWARDS => 'Grid Rewards',
        );
        $mode     = $this->GetValue('EMS_Mode');
        $modeName = isset($modeNames[$mode]) ? $modeNames[$mode] : 'Unbekannt';
        return sprintf(
            'Modus: %s | SOC: %.0f%% | Netz: %.0f W | PV: %.0f W | Tibber: %.2f ct | Aktion: %s',
            $modeName,
            $this->GetValue('EMS_BatSOC'),
            $this->GetValue('EMS_GridPower'),
            $this->GetValue('EMS_PVPower'),
            $this->GetValue('EMS_TibberPrice'),
            $this->GetValue('EMS_LastAction')
        );
    }

    public function RequestAction($ident, $value)
    {
        if ($ident === 'EMS_GridRewards') {
            $this->SetValue('EMS_GridRewards', (bool)$value);
            $this->emsLog(EMS_LOG_BASIC, 'Grid Rewards ' . ($value ? 'aktiviert' : 'deaktiviert'));
            $this->Update();
        }
    }

    /**
     * Startet den Batterie-Boost fuer $minutes Minuten (Default 30): Batterie
     * entlaedt mit maximaler Leistung, alle Wallboxen werden freigegeben --
     * fuer schnelles Nachladen kurz vor Abfahrt, unabhaengig von der
     * normalen Preis-/PV-Logik. Bricht automatisch ab, sobald SOC die
     * Reserve-Grenze erreicht (siehe optimize()).
     */
    public function StartBatteryBoost(int $minutes = 30)
    {
        $minutes = max(1, min(180, (int)$minutes));
        $this->WriteAttributeInteger('BatteryBoostUntil', time() + $minutes * 60);
        $this->emsLog(EMS_LOG_BASIC, sprintf('🚀 Batterie-Boost gestartet fuer %d Minuten', $minutes));
        $this->Update();
        $this->UpdateFormField('BoostStatusLabel', 'caption', $this->getBoostStatusLine());
    }

    /**
     * Beendet einen laufenden Batterie-Boost sofort.
     */
    public function StopBatteryBoost()
    {
        $this->WriteAttributeInteger('BatteryBoostUntil', 0);
        $this->emsLog(EMS_LOG_BASIC, 'Batterie-Boost manuell beendet');
        $this->Update();
        $this->UpdateFormField('BoostStatusLabel', 'caption', $this->getBoostStatusLine());
    }

    /**
     * Statuszeile fuers Formular: laufender Boost mit Restzeit, oder Hinweis
     * dass keiner aktiv ist.
     */
    private function getBoostStatusLine()
    {
        $until = $this->ReadAttributeInteger('BatteryBoostUntil');
        if ($until > time()) {
            $remainMin = (int)ceil(($until - time()) / 60);
            return sprintf('🚀 Batterie-Boost aktiv, noch ca. %d Min.', $remainMin);
        }
        return 'Batterie-Boost: nicht aktiv';
    }

    /**
     * TAGESPLAN (19.08.2026, ersetzt SetECOWindow()/PlanNightCharge()/
     * PlanNegativePriceExport()): jene drei Funktionen liefen nur auf
     * manuellen Formular-Klick, nie automatisch, UND schrieben in die
     * Goodwe-eigenen ECO-Zeitfenster-Register -- ein zweiter, von
     * optimize()/applyDecision() komplett unabhaengiger Steuerpfad, der
     * dessen kontinuierliche ctl_ems_mode/-power-Reassert-Schleife
     * ueberschrieben oder ignoriert hat (siehe SUITE.md-Historie
     * 19.08.2026). Der Tagesplan ersetzt beide alten Wege: EIN
     * Entscheidungsmodell, das den ganzen Tag vorausplant UND sichtbar
     * macht (Wochenplan-Event, siehe ensureDayPlanEvent()), exekutiert
     * wird er weiterhin ausschliesslich ueber applyDecision()/
     * setGoodweMode() -- also ueber InverterHubs ctl_ems_*, nie mehr
     * ueber die WR-eigenen ECO-Fenster.
     *
     * Vorbild: Dietmars Winterskript (IPS-Objekt #55729, "Speicher-
     * beladungszyklen festlegen") -- Rang-basierte Auswahl der
     * guenstigsten Viertelstunden statt nur des guenstigsten
     * zusammenhaengenden Blocks, materialisiert in einem echten
     * Symcon-Wochenplan statt nur im Log.
     */

    /**
     * Berechnet den Tagesplan (96 Viertelstunden-Slots, heute) neu, falls
     * sich die zugrundeliegenden Preise oder der Kalendertag seit dem
     * letzten Lauf geaendert haben (Signatur-Vergleich) -- kein Aufwand bei
     * jedem 30-Sekunden-Update()-Tick, nur wenn wirklich neue Tibber-Daten
     * da sind. $force=true (Formular-Button) erzwingt eine Neuberechnung.
     */
    public function BuildDayPlan(bool $force = false)
    {
        $todayJson = $this->getPT15MTodayJson();
        $signature = date('Y-m-d') . '|' . md5($todayJson);

        if (!$force && $this->ReadAttributeString('DayPlanSignature') === $signature) {
            return 'ℹ️ Tagesplan unverändert (gleiche Preisdaten wie beim letzten Lauf) — keine Neuberechnung nötig.';
        }

        if (empty($todayJson)
            || !$this->ReadPropertyBoolean('TIBBER_Active')
            || !$this->ReadPropertyBoolean('BAT_Active')) {
            $this->WriteAttributeString('DayPlan', '[]');
            $this->WriteAttributeString('DayPlanSignature', $signature);
            $this->emsLog(EMS_LOG_BASIC, 'BuildDayPlan: keine PT15M-Preisdaten (weder von Tibber Grid Reward automatisch noch manuell verknuepft) oder Tibber/Batterie inaktiv -- kein Tagesplan moeglich');
            return '⛔ Kein Tagesplan möglich: keine PT15M-Preisdaten (weder automatisch von Tibber Grid Reward noch manuell verknüpft) oder Tibber/Batteriespeicher deaktiviert.';
        }

        $prices   = $this->parsePT15M($todayJson);
        $pvfSlots = $this->getPvfSlotsWatt(); // Index 0-191 (heute+morgen), hier nur 0-95 genutzt

        // Lastprognose je Slot: LFC liefert bisher nur eine Fenster-Summe
        // (kWh fuer einen beliebigen Zeitraum), keine 15-Min-Kurve -- als
        // Naeherung wird das heutige Tagesfenster gleichmaessig auf 24h
        // verteilt, wenn LFC eine belastbare Zahl liefert (coverage=1.0),
        // sonst bleibt der feste Erfahrungswert NEG_Avg_House_Load_W die
        // Grundlage (bisher "Solarspitzengesetz"-Property, jetzt allgemeiner
        // Lastprognose-Fallback fuer den ganzen Tagesplan). Eine echte
        // 15-Min-Lastkurve ist ein offener Ausbauschritt fuer LFC selbst.
        $avgHouseW = (float)$this->ReadPropertyInteger('NEG_Avg_House_Load_W');
        $lfcId = $this->getLfcInstance();
        if ($lfcId > 0) {
            $window = @LFC_GetEnergyWindow($lfcId, strtotime('today'), strtotime('tomorrow'));
            if (is_array($window) && isset($window['kwh']) && ($window['coverage'] ?? 0) >= 1.0) {
                $avgHouseW = ($window['kwh'] * 1000.0) / 24.0;
            }
        }

        $capKwh         = (float)$this->ReadPropertyFloat('BAT_Capacity_kWh');
        $maxW           = (float)$this->ReadPropertyInteger('EMS_Max_Power_W');
        $socMin         = (float)$this->ReadPropertyInteger('BAT_SOC_Min');
        $socReserve     = (float)$this->ReadPropertyInteger('BAT_SOC_Reserve_Backup');
        $hystSoc        = (float)$this->ReadPropertyInteger('OPT_Hysteresis_SOC');
        $socTargetDay   = $this->getDynamicSocTargetDay();
        $socTargetNight = (float)$this->ReadPropertyInteger('BAT_SOC_Target_Night');
        $thCharge       = (float)$this->ReadPropertyFloat('TIB_Threshold_Charge');
        $thDischarge    = (float)$this->ReadPropertyFloat('TIB_Threshold_Discharge');
        $fcMinPower     = (float)$this->ReadPropertyInteger('FORECAST_Min_Power_W');
        $feedTariff     = (float)$this->readVar('VAR_TIB_Feed_Tariff', 0.1836);
        $enwgActive     = $this->ReadPropertyBoolean('ENWG14A_Active');
        $enwgStartH     = $this->ReadPropertyInteger('ENWG14A_Start_Hour');
        $enwgEndH       = $this->ReadPropertyInteger('ENWG14A_End_Hour');

        // Rangfolge der Slots nach Preis (Muster: Dietmars Winterskript
        // #58045, 19.08.2026) -- die N insgesamt guenstigsten Viertelstunden
        // werden gesucht, nicht nur der guenstigste ZUSAMMENHAENGENDE Block
        // (alte findCheapestBlock()-Logik) -- bei unruhiger Preiskurve
        // koennen verstreute billige Slots guenstiger sein als ein Block.
        $ranked = $prices;
        asort($ranked);
        $cheapRank = array();
        $r = 0;
        foreach ($ranked as $slotIdx => $p) { $cheapRank[$slotIdx] = $r++; }

        $soc = $this->getCurrentBatterySoc();
        $nowSlot  = (int)(((int)date('H') * 60 + (int)date('i')) / 15);
        $chargeKw = min($maxW / 1000.0, $capKwh * 0.5); // wie bisher: Ladegeschwindigkeit aus Leistungsgrenze/0.5C geschaetzt

        $ctx = array(
            'enwgActive' => $enwgActive, 'enwgStartH' => $enwgStartH, 'enwgEndH' => $enwgEndH,
            'avgHouseW' => $avgHouseW, 'fcMinPower' => $fcMinPower,
            'socTargetDay' => $socTargetDay, 'hystSoc' => $hystSoc,
            'socMin' => $socMin, 'socReserve' => $socReserve, 'socTargetNight' => $socTargetNight,
            'capKwh' => $capKwh, 'chargeKw' => $chargeKw, 'maxW' => $maxW,
            'feedTariff' => $feedTariff, 'thCharge' => $thCharge, 'thDischarge' => $thDischarge,
        );

        $futureMax = $this->computeSuffixMaxPrice($prices);

        $plan = array();
        for ($slot = 0; $slot < 96; $slot++) {
            if ($slot < $nowSlot) {
                $plan[$slot] = array('op' => EMS_OP_AUTO, 'gw' => GW_MODE_AUTO, 'power' => 0, 'reason' => '(vergangen)',
                    'price' => $prices[$slot], 'soc' => round($soc, 1));
                continue;
            }
            $price = $prices[$slot];
            $pvW   = (float)($pvfSlots[$slot] ?? 0.0);
            $result = $this->simulateDaySlot($slot, $price, $pvW, $soc, $cheapRank, $ctx, $futureMax[$slot] ?? null);
            $plan[$slot] = $result['plan'];
            $soc = $result['soc'];
        }

        $this->WriteAttributeString('DayPlan', json_encode($plan));
        $this->WriteAttributeString('DayPlanSignature', $signature);
        $this->emsLog(EMS_LOG_BASIC, sprintf('Tagesplan neu berechnet (ab Slot %d/96, Preis-Signatur %s)', $nowSlot, substr(md5($todayJson), 0, 8)));

        // Erweiterte Planung fuer MORGEN (20.08.2026, Dietmars Wunsch: "im
        // Dashboard zusammen mit SOC und Preis sehen -- auch morgen"). Der
        // native IPS-Wochenplan-Kalender oben bleibt bewusst auf "heute"
        // beschraenkt (er ist architektonisch ein einziges, taeglich
        // wiederholtes 24h-Muster, keine echte Zwei-Tage-Zeitleiste -- siehe
        // SUITE.md). Diese erweiterte Fassung ist NICHT fuer den Kalender
        // gedacht, sondern fuer eine externe Visualisierung (Dashboard-
        // Diagramm), die zwei Tage durchaus als eine Linie zeichnen kann.
        // Nutzt dieselbe Entscheidungslogik wie oben (simulateDaySlot()),
        // beginnt aber bei Slot 0 (nicht "vergangen") und fuehrt den am Ende
        // von heute simulierten SOC nahtlos fort.
        $tomorrowJson = $this->getPT15MTomorrowJson();
        $tomorrowPrices = $this->parsePT15M($tomorrowJson, 1);
        $tomorrowRanked = $tomorrowPrices;
        asort($tomorrowRanked);
        $tomorrowCheapRank = array();
        $tr = 0;
        foreach ($tomorrowRanked as $slotIdx => $p) { $tomorrowCheapRank[$slotIdx] = $tr++; }

        $tomorrowFutureMax = $this->computeSuffixMaxPrice($tomorrowPrices);

        $tomorrowPlan = array();
        for ($slot = 0; $slot < 96; $slot++) {
            $price = $tomorrowPrices[$slot];
            $pvW   = (float)($pvfSlots[96 + $slot] ?? 0.0);
            $result = $this->simulateDaySlot($slot, $price, $pvW, $soc, $tomorrowCheapRank, $ctx, $tomorrowFutureMax[$slot] ?? null);
            $tomorrowPlan[$slot] = $result['plan'];
            $soc = $result['soc'];
        }
        $this->WriteAttributeString('DayPlanTomorrow', json_encode($tomorrowPlan));

        $written = $this->writeDayPlanEvent($plan);

        // Rueckgabewert fuer den Formular-Button (20.08.2026, Live-Fund:
        // "ich druecke den Button und sehe keine Rueckmeldung" -- gleicher
        // Fehlertyp wie bei "Jetzt neu suchen" zuvor, siehe SUITE.md
        // Stolperfalle 12. Der Button ruft das jetzt per 'echo' auf (Muster:
        // bestehender "Status anzeigen"-Button), damit ein sofortiges Popup
        // erscheint statt gar nichts.
        if ($written['failed'] > 0) {
            return sprintf('⚠️ Tagesplan berechnet, aber %d/%d Slots konnten nicht in den Kalender geschrieben werden (siehe Instanz-Debug für Details).', $written['failed'], $written['ok'] + $written['failed']);
        }
        return sprintf('✅ Tagesplan neu berechnet und in den Kalender geschrieben (%d Viertelstunden ab Slot %d/96).', $written['ok'], $nowSlot);
    }

    /**
     * Liest fuer den AKTUELLEN Viertelstunden-Slot nach, was BuildDayPlan()
     * dort vorgesehen hat. Sicherheitsnetz: der Plan kann Stunden alt sein
     * (Prognose-basiert) -- der echte IST-SOC hat immer Vorrang vor der
     * Plan-Annahme. Gibt null zurueck, wenn kein Plan vorliegt oder die
     * Realitaet der Plan-Annahme sichtbar widerspricht (dann faellt
     * optimize() auf die Automatik zurueck statt stur weiterzumachen).
     */
    private function applyPlanSlot($s)
    {
        $plan = $this->loadDayPlan();
        if (empty($plan)) { return null; }

        $slotIndex = (int)(((int)date('H') * 60 + (int)date('i')) / 15);
        if (!isset($plan[$slotIndex])) { return null; }
        $slot = $plan[$slotIndex];
        $op   = $slot['op'] ?? EMS_OP_AUTO;

        if (!$s['bat_active']) { return null; }

        $socMin     = (float)$this->ReadPropertyInteger('BAT_SOC_Min');
        $socReserve = (float)$this->ReadPropertyInteger('BAT_SOC_Reserve_Backup');
        if (in_array($op, array(EMS_OP_DISCHARGE, EMS_OP_EXPORT), true) && $s['bat_soc'] <= ($socMin + $socReserve)) {
            return null; // Plan wollte entladen/exportieren, SOC ist aber schon an der Reserve
        }
        if ($op === EMS_OP_NET_CHARGE && $s['bat_soc'] >= 99.5) {
            return null; // schon voll -- Plan-Annahme ueberholt
        }

        $thWB  = (float)$this->ReadPropertyFloat('TIB_Threshold_WB');
        $price = $s['tib_price_eff'];
        $wb1En = ($s['wb_active'] && $s['wb1_cable'] > 0 && $s['wb1_error'] === 0 && (!$s['tib_active'] || $price < $thWB));
        $wb2En = ($s['wb_active'] && $s['wb_count'] >= 2 && $s['wb2_cable'] > 0 && $s['wb2_error'] === 0 && (!$s['tib_active'] || $price < $thWB));

        return array(
            'op_mode'    => $op,
            'gw_mode'    => $slot['gw'] ?? GW_MODE_AUTO,
            'gw_power_w' => (int)($slot['power'] ?? 0),
            'gw_enable'  => true,
            'wb1_enable' => $wb1En,
            'wb2_enable' => $wb2En,
            'reason'     => 'Tagesplan: ' . ($slot['reason'] ?? ''),
        );
    }

    private function loadDayPlan()
    {
        $json = $this->ReadAttributeString('DayPlan');
        if (empty($json)) { return array(); }
        $plan = json_decode($json, true);
        return is_array($plan) ? $plan : array();
    }

    /**
     * Idempotente Erstellung des sichtbaren Tagesplan-Wochenplans als Kind
     * der EMS-Instanz (Muster: Dietmars Winterskript, Event #10593) --
     * legt nur an, was fehlt, wie bei den NRG.*-Variablenprofilen.
     */
    private function ensureDayPlanEvent()
    {
        $eventId = $this->ReadAttributeInteger('DayPlanEventId');
        if ($eventId <= 0 || !@IPS_ObjectExists($eventId)) {
            $eventId = IPS_CreateEvent(2); // EVENTTYPE_SCHEDULE (Wochenplan)
            IPS_SetParent($eventId, $this->InstanceID);
            IPS_SetName($eventId, 'EMS Tagesplan (automatisch)');
            IPS_SetIdent($eventId, 'EMS_DayPlanEvent');
            IPS_SetPosition($eventId, 15);
            IPS_SetEventActive($eventId, true);

            // ID SOFORT sichern, bevor Gruppe/Aktionen konfiguriert werden --
            // schlaegt das fehl, entsteht beim naechsten Versuch kein zweites
            // Duplikat mehr (live-Fund 19.08.2026: falscher Parametercount bei
            // IPS_SetEventScheduleAction() liess ApplyChanges() zweimal
            // hintereinander abstuerzen, jedesmal BEVOR die ID gespeichert war
            // -- Ident-Kollision + zwei verwaiste Event-Objekte als Folge).
            $this->WriteAttributeInteger('DayPlanEventId', $eventId);
        }

        // Schedule-Gruppe UND Aktionen bei JEDEM Aufruf neu setzen (laut
        // Symcon-Doku idempotent) -- repariert einen zuvor unvollstaendig
        // konfigurierten Event automatisch beim naechsten ApplyChanges(),
        // statt dauerhaft kaputt zu bleiben. Live-Fund 20.08.2026: die
        // Gruppen-Definition (IPS_SetEventScheduleGroup) stand bisher NUR im
        // einmaligen Erstellungszweig oben -- ein Event, dessen ApplyChanges()
        // beim allerersten Aufbau vor diesem Schritt abbrach (oder aus einer
        // frueheren Code-Version stammt, die die Gruppe anders/gar nicht
        // gesetzt hat), blieb dauerhaft ohne Gruppe 0 stehen. Jeder Versuch,
        // IPS_SetEventScheduleGroupPoint($eventId, 0, ...) zu schreiben,
        // scheiterte seitdem mit "Kann Gruppe mit ID 0 nicht finden" -- 96 von
        // 96 Fehlschlaegen, sichtbar erst NACH dem Entfernen der '@'-
        // Fehlerunterdrueckung in writeDayPlanEvent() (siehe SUITE.md
        // Stolperfalle 13). Jetzt wird die Gruppe genauso wie die Aktionen bei
        // jedem Aufruf neu gesetzt -- heilt einen kaputten Bestands-Event
        // automatisch beim naechsten ApplyChanges(), ohne dass der Event
        // manuell geloescht werden muss.
        // alle Wochentage -- Inhalt wird taeglich neu geschrieben, kein echter
        // Wochenrhythmus. Live-Fund 20.08.2026: $Days ist eine 7-Bit-Maske
        // (Bit0=Montag..Bit6=Sonntag, gueltiger Bereich 0-127), NICHT der
        // vorher angenommene 16-Bit-Wert 65535 -- IPS quittierte das beim
        // naechsten ApplyChanges() mit "'Day' ausserhalb des gueltigen
        // Bereichs". 127 = alle 7 Bits gesetzt = alle Wochentage.
        IPS_SetEventScheduleGroup($eventId, 0, 127);

        // 5. Parameter ist Pflicht (ScriptContent) -- leer, weil dieser Event
        // NUR zur Anzeige dient. Wuerde hier echter Code stehen, haette IPS'
        // eigener interner Schedule-Trigger einen zweiten, von optimize()/
        // applyDecision() unabhaengigen Steuerpfad -- genau das Problem, das
        // der Tagesplan eigentlich beseitigen soll (siehe SUITE.md-Historie).
        foreach ($this->getPlanActions() as $a) {
            IPS_SetEventScheduleAction($eventId, $a['op'], $a['name'], $a['color'], '');
        }

        return $eventId;
    }

    private function getPlanActions()
    {
        return array(
            array('op' => EMS_OP_AUTO,       'name' => 'Automatik',                 'color' => 0xAAAAAA),
            array('op' => EMS_OP_PV_SELFUSE, 'name' => 'PV-Eigenverbrauch (laden)', 'color' => 0x4CAF50),
            array('op' => EMS_OP_NET_CHARGE, 'name' => 'Netz laden',                'color' => 0x2196F3),
            array('op' => EMS_OP_DISCHARGE,  'name' => 'Eigenverbrauch (entladen)', 'color' => 0xFF9800),
            array('op' => EMS_OP_EXPORT,     'name' => 'Einspeisen',                'color' => 0x9C27B0),
        );
    }

    private function writeDayPlanEvent($plan)
    {
        $eventId = $this->ensureDayPlanEvent();
        if ($eventId <= 0) {
            $this->emsLog(EMS_LOG_BASIC, 'writeDayPlanEvent: ensureDayPlanEvent() lieferte keine gueltige Event-ID -- Tagesplan-Kalender NICHT geschrieben.');
            return array('ok' => 0, 'failed' => 96);
        }
        $validOps = array(EMS_OP_AUTO, EMS_OP_PV_SELFUSE, EMS_OP_NET_CHARGE, EMS_OP_DISCHARGE, EMS_OP_EXPORT);
        $ok = 0;
        $failed = array();
        for ($slot = 0; $slot < 96; $slot++) {
            $op = $plan[$slot]['op'] ?? EMS_OP_AUTO;
            if (!in_array($op, $validOps, true)) { $op = EMS_OP_AUTO; }
            $h = (int)($slot / 4);
            $m = ($slot % 4) * 15;
            // Kein '@' mehr (20.08.2026, Live-Fund: Tagesplan blieb trotz
            // erfolgreicher Berechnung im WebFront leer -- die '@'-
            // Fehlerunterdrueckung haette das lautlos verschluckt, egal aus
            // welchem Grund. Fehlschlaege werden jetzt gezaehlt und geloggt,
            // statt zu verschwinden -- Dietmars "kein Blindflug"-Grundsatz).
            if (IPS_SetEventScheduleGroupPoint($eventId, 0, $slot, $h, $m, 0, $op)) {
                $ok++;
            } else {
                $failed[] = $slot;
            }
        }
        if (!empty($failed)) {
            $this->emsLog(EMS_LOG_BASIC, sprintf(
                'writeDayPlanEvent: %d/%d Slots geschrieben, %d fehlgeschlagen (erste fehlgeschlagene Slots: %s) -- IPS_SetEventScheduleGroupPoint() lieferte false, siehe IPS-Fehlerlog fuer Details.',
                $ok, count($plan), count($failed), implode(',', array_slice($failed, 0, 5))
            ));
        } else {
            $this->emsLog(EMS_LOG_VERBOSE, sprintf('writeDayPlanEvent: alle %d Slots erfolgreich geschrieben.', $ok));
        }
        return array('ok' => $ok, 'failed' => count($failed));
    }

    /**
     * Prueft, ob eine gegebene Stunde im §14a-Fenster liegt (auch ueber
     * Mitternacht hinweg, z.B. 22-6 Uhr). Von isInEnwgWindow() (aktuelle
     * Stunde) UND BuildDayPlan() (beliebige Slot-Stunde) genutzt.
     */
    private function slotInEnwgWindow($hour, $startH, $endH)
    {
        if ($startH < $endH) {
            return ($hour >= $startH && $hour < $endH);
        }
        return ($hour >= $startH || $hour < $endH);
    }

    // ----------------------------------------------------------------
    //  Tibber PT15M Hilfsmethode
    // ----------------------------------------------------------------

    /**
     * PT15M-JSON zu einem Array mit genau 96 Preiseintraegen (Index 0-95) parsen.
     * Unterstuetzt:
     *  - Einfaches Zahlen-Array: [0.2345, 0.2234, ...]
     *  - TibberV2-Objekt-Array:  [{"total":0.2345,"startsAt":"2024-01-01T00:00:00+01:00",...},...]
     */
    /**
     * Liefert 96 PT15M-Preise (Index 0-95) ODER null je Slot, wenn fuer
     * diesen Slot KEINE echte Preisangabe vorlag. Frueher wurde ein
     * fehlender Slot mit 0.0 aufgefuellt -- das sah fuer die
     * Entscheidungslogik in BuildDayPlan() wie ein ECHTER Preis von 0ct aus
     * (guenstiger als jede Einspeiseverguetung), was am 20.08.2026 live dazu
     * fuehrte, dass Abendstunden ohne Tibber-Preisdaten faelschlich als
     * "Export" statt "Automatik" geplant wurden -- fehlende Daten wurden wie
     * ein reales Sonderangebot behandelt. `null` macht "keine Daten" jetzt
     * von "Preis ist tatsaechlich 0" unterscheidbar; der Aufrufer MUSS jeden
     * Slot auf `null` pruefen, bevor er ihn in eine Preisschwelle einsetzt.
     *
     * Zusaetzlich datumsbewusst (20.08.2026): `startsAt` wird nur fuer Slots
     * uebernommen, deren Kalendertag mit HEUTE uebereinstimmt -- verhindert,
     * dass eine eventuell schon mitgelieferte Preisangabe fuer MORGEN am
     * gleichen Uhrzeit-Slot den echten Preis von HEUTE stillschweigend
     * ueberschreibt (die Slot-Berechnung selbst kennt nur die Uhrzeit, nicht
     * das Datum).
     */
    /**
     * Fuer jeden Slot: der teuerste bekannte Preis ab DIESEM Slot bis zum
     * Ende des Preishorizonts (Suffix-Maximum, O(96)). `null`-Slots (keine
     * Daten) werden uebersprungen, nicht als 0 gewertet (Stolperfalle 15).
     * Liefert `null` fuer einen Slot, wenn ab dort ueberhaupt kein Preis
     * bekannt ist. Grundlage fuer die preisbewusste Sicherheitsmarge in
     * simulateDaySlot() (20.08.2026, Dietmars Einwand: "reicht die Energie
     * bis morgen" -- bzw. hier: "lohnt sich Export, wenn spaeter am Tag noch
     * eine teurere Stunde kommt?").
     */
    private function computeSuffixMaxPrice(array $prices)
    {
        // $out[$i] = teuerster Preis NACH Slot $i (exklusive $i selbst) --
        // die aktuelle Stunde soll den Vergleich "kommt noch was Teureres"
        // nicht mit sich selbst beantworten.
        $out = array_fill(0, 96, null);
        $runningMax = null;
        for ($i = 95; $i >= 0; $i--) {
            $out[$i] = $runningMax;
            if (isset($prices[$i]) && $prices[$i] !== null) {
                $runningMax = ($runningMax === null) ? $prices[$i] : max($runningMax, $prices[$i]);
            }
        }
        return $out;
    }

    private function parsePT15M($json, int $dayOffset = 0)
    {
        $prices = array_fill(0, 96, null);
        if (empty($json)) { return $prices; }

        $data = json_decode($json, true);
        if (!is_array($data) || empty($data)) { return $prices; }

        // Format: einfaches Float-Array (kein Datumsbezug moeglich -- wird
        // unveraendert positionsweise als der Zieltag ($dayOffset) uebernommen).
        if (isset($data[0]) && is_numeric($data[0])) {
            for ($i = 0; $i < min(96, count($data)); $i++) {
                $prices[$i] = (float)$data[$i];
            }
            return $prices;
        }

        $targetDate = date('Y-m-d', strtotime($dayOffset === 0 ? 'today' : "today +{$dayOffset} day"));
        // Format: Objekt-Array mit Preis- + Zeitfeld. Unterstuetzt beide
        // bekannten Formen: Tibber Grid Reward liefert `start` als Unix-
        // Timestamp (int) + `price` (contractVersion 1.1, verifiziert
        // 20.08.2026) -- `startsAt` als ISO-String + `total` bleibt als
        // Fallback fuer andere/manuell eingetragene Preisquellen bestehen.
        //
        // EINHEITEN-FIX 20.08.2026 (kritischer Live-Fund, Dashboard-
        // Diagramm zeigte "Strompreis: 2848.00" statt ~28ct): Tibber Grid
        // Reward liefert `price` explizit in CT/KWH (verifiziert durch deren
        // eigenen Quellcode: 'price' => round($total * 100, 2)), waehrend
        // JEDE Preisschwelle in EMS selbst (TIB_Threshold_Charge/Export/
        // Discharge, VAR_TIB_Feed_Tariff) als EUR/KWH-Dezimalzahl konfiguriert
        // ist (z.B. 0.15, 0.1836). Ohne Umrechnung war der Vergleich "Preis
        // (28.48) > Exportschwelle (0.20)" praktisch IMMER wahr, sobald echte
        // Tibber-Daten durchkamen -- das erklaert die Dauer-Export-Planung
        // grundlegender als nur die zuvor gefixten fehlenden Slots (0.21.14).
        // `total` (Alt-/Fallback-Format) bleibt unveraendert EUR/KWH, wird
        // NICHT durch 100 geteilt.
        foreach ($data as $i => $entry) {
            if (!is_array($entry)) { continue; }
            $price = null;
            if (isset($entry['price']))  { $price = (float)$entry['price'] / 100.0; } // ct/kWh -> EUR/kWh
            elseif (isset($entry['total'])) { $price = (float)$entry['total']; }       // bereits EUR/kWh
            if ($price === null) { continue; }

            $ts = null;
            if (isset($entry['start']) && is_numeric($entry['start'])) {
                $ts = (int)$entry['start'];
            } elseif (isset($entry['startsAt'])) {
                $ts = strtotime($entry['startsAt']);
            }

            if ($ts !== null) {
                if (date('Y-m-d', $ts) !== $targetDate) { continue; } // gehoert zu einem anderen Kalendertag
                $slot = (int)floor(((int)date('H', $ts) * 60 + (int)date('i', $ts)) / 15);
                if ($slot >= 0 && $slot < 96) {
                    $prices[$slot] = $price;
                }
            } elseif ($i >= 0 && $i < 96) {
                $prices[$i] = $price;
            }
        }
        return $prices;
    }

    /**
     * Liest den PV-Forecast-JSON aus VAR_FC_JSON und summiert die
     * pv_estimate-Werte der naechsten $hours Stunden ab jetzt.
     * JSON-Format (open-meteo iconD2):
     *   [{"ts":1234567890,"hour":10,"pv_estimate":2.5,"temp":15,...},...]
     */
    private function parseForecastNextHours($hours)
    {
        $pvfSlots = $this->getPvfSlotsWatt();
        if (!empty($pvfSlots)) {
            $nowSlot   = (int)(((int)date('H') * 60 + (int)date('i')) / 15);
            $numSlots  = (int)ceil($hours * 4);
            $sumWh     = 0.0;
            for ($i = 0; $i < $numSlots; $i++) {
                $slot = $nowSlot + $i;
                if (!isset($pvfSlots[$slot])) { break; }
                $sumWh += (float)$pvfSlots[$slot] * 0.25; // 15-Min-Slot -> Wh
            }
            return $sumWh / 1000.0; // kWh
        }

        // Fallback: alte manuelle VAR_FC_JSON-Verknuepfung
        $varId = $this->ReadPropertyInteger('VAR_FC_JSON');
        if ($varId <= 0 || !IPS_VariableExists($varId)) { return 0.0; }

        $json = GetValue($varId);
        if (empty($json)) { return 0.0; }

        $data = json_decode($json, true);
        if (!is_array($data)) { return 0.0; }

        $now    = time();
        $cutoff = $now + $hours * 3600;
        $sum    = 0.0;

        foreach ($data as $entry) {
            if (!is_array($entry)) { continue; }

            // Zeitstempel ermitteln — "ts" oder "tiso" (ISO-String)
            $ts = 0;
            if (isset($entry['ts']))   { $ts = (int)$entry['ts']; }
            elseif (isset($entry['tiso'])) { $ts = (int)strtotime($entry['tiso']); }

            if ($ts <= 0 || $ts < $now || $ts > $cutoff) { continue; }

            if (isset($entry['pv_estimate'])) {
                $sum += (float)$entry['pv_estimate'];
            }
        }
        return $sum;
    }

    // ----------------------------------------------------------------
    //  Layer 1: Daten einlesen
    // ----------------------------------------------------------------

    private function readState()
    {
        $s = array();

        // Bevorzugt: automatisch gefundener WR aus der NRG-Stack-Discovery
        // (siehe Discover()/getInverterEntry()). Nur wenn kein Partnermodul
        // gefunden wird, greift die alte manuelle Variablenverknuepfung als
        // Fallback (z.B. fuer Anlagen ohne InverterHub).
        $inv = $this->getInverterEntry();

        $fromIhub = ($inv !== null && $inv['source'] === 'inverterhub');

        // Netz (SmartMeter)
        if ($fromIhub && ($inv['gridPowerID'] ?? 0) > 0) {
            $s['grid_total_w'] = (float)$this->readDiscoveredVar($inv['gridPowerID'], 0);
        } else {
            $s['grid_total_w'] = (float)$this->readVar('VAR_SM_Total_Power', 0);
        }
        $s['grid_l1_w']     = (float)$this->readVar('VAR_SM_L1_Power',    0);
        $s['grid_l2_w']     = (float)$this->readVar('VAR_SM_L2_Power',    0);
        $s['grid_l3_w']     = (float)$this->readVar('VAR_SM_L3_Power',    0);

        // PV
        if ($fromIhub && ($inv['pvPowerID'] ?? 0) > 0) {
            $s['pv_total_w'] = (float)$this->readDiscoveredVar($inv['pvPowerID'], 0);
        } else {
            $s['pv_total_w'] = (float)$this->readVar('VAR_PV_Total_Power', 0);
        }

        // Wechselrichter (AC-Gesamtleistung)
        if ($fromIhub && ($inv['acPowerID'] ?? 0) > 0) {
            $s['wr_total_w'] = (float)$this->readDiscoveredVar($inv['acPowerID'], 0);
        } else {
            $s['wr_total_w'] = (float)$this->readVar('VAR_WR_Total_Power', 0);
        }

        // Batterie — InverterHub liefert SOC/Leistung bereits stringuebergreifend
        // aggregiert, deshalb hier kein BAT_String_Count-Handling mehr noetig.
        $s['bat_active'] = $this->ReadPropertyBoolean('BAT_Active')
            || ($fromIhub && ($inv['batPowerID'] ?? 0) > 0);
        if ($fromIhub && ($inv['socID'] ?? 0) > 0) {
            $s['bat_soc']   = (float)$this->readDiscoveredVar($inv['socID'], 0);
            $s['bat_pow_w'] = (float)$this->readDiscoveredVar($inv['batPowerID'] ?? 0, 0);
        } elseif ($s['bat_active']) {
            $soc1           = (float)$this->readVar('VAR_BAT1_SOC',   0);
            $pow1           = (float)$this->readVar('VAR_BAT1_Power', 0);
            if ($this->ReadPropertyInteger('BAT_String_Count') >= 2) {
                $soc2       = (float)$this->readVar('VAR_BAT2_SOC',   0);
                $pow2       = (float)$this->readVar('VAR_BAT2_Power', 0);
                $s['bat_soc']    = ($soc1 + $soc2) / 2.0;
                $s['bat_pow_w']  = $pow1 + $pow2;
            } else {
                $s['bat_soc']    = $soc1;
                $s['bat_pow_w']  = $pow1;
            }
        } else {
            $s['bat_soc']    = 0.0;
            $s['bat_pow_w']  = 0.0;
        }

        // Wallboxen
        $s['wb_active']     = $this->ReadPropertyBoolean('WB_Active');
        $s['wb_count']      = $this->ReadPropertyInteger('WB_Count');
        $s['grid_rewards']  = $this->GetValue('EMS_GridRewards');
        $s['wb1_pow_kw']    = (float)$this->readVar('VAR_WB1_Power',  0);
        $s['wb1_status']    = (int)  $this->readVar('VAR_WB1_Status', 0);
        $s['wb1_cable']     = (int)  $this->readVar('VAR_WB1_Cable',  0);
        $s['wb1_error']     = (int)  $this->readVar('VAR_WB1_Error',  0);
        $s['wb2_pow_kw']    = (float)$this->readVar('VAR_WB2_Power',  0);
        $s['wb2_status']    = (int)  $this->readVar('VAR_WB2_Status', 0);
        $s['wb2_cable']     = (int)  $this->readVar('VAR_WB2_Cable',  0);
        $s['wb2_error']     = (int)  $this->readVar('VAR_WB2_Error',  0);

        // Waermepumpe (nur Monitoring)
        $s['hp_active']     = $this->ReadPropertyBoolean('HP_Active');
        $s['hp_pow_w']      = $s['hp_active'] ? (float)$this->readVar('VAR_HP_Power', 0) : 0.0;

        // Tibber
        $s['tib_active']    = $this->ReadPropertyBoolean('TIBBER_Active');
        $s['tib_price']     = $s['tib_active'] ? (float)$this->readVar('VAR_TIB_Price',       0)     : 0.0;
        $s['tib_level']     = $s['tib_active'] ? (int)  $this->readVar('VAR_TIB_Level',       2)     : 2;
        $s['tib_feed']      = $s['tib_active'] ? (float)$this->readVar('VAR_TIB_Feed_Tariff', 0.1836) : 0.1836;

        // PV Forecast
        $s['fc_active']     = $this->ReadPropertyBoolean('FORECAST_Active');
        $s['fc_today_kwh']  = 0.0;
        if ($s['fc_active']) {
            $pvfId = $this->getPvfInstance();
            if ($pvfId > 0) {
                $today = PVF_GetForecast($pvfId, 0);
                $s['fc_today_kwh'] = (float)($today['kwh'] ?? 0.0);
            } else {
                $s['fc_today_kwh'] = (float)$this->readVar('VAR_FC_Today', 0);
            }
        }

        // PV Forecast: stündliche Vorschau für Planungshorizont
        $s['fc_next_kwh']   = 0.0;
        if ($s['fc_active']) {
            $horizonH = $this->ReadPropertyInteger('OPT_Planning_Horizon_H');
            $s['fc_next_kwh'] = $this->parseForecastNextHours($horizonH);
        }

        // §14a
        $s['enwg_active']   = $this->ReadPropertyBoolean('ENWG14A_Active');
        $s['enwg_in_window']= ($s['enwg_active'] && $this->isInEnwgWindow());

        // Berechneter Hausverbrauch
        $wbW = ($s['wb1_pow_kw'] + $s['wb2_pow_kw']) * 1000;
        $s['house_pow_w'] = $s['pv_total_w'] - abs($s['bat_pow_w']) - $s['grid_total_w'] - $wbW;
        if ($s['house_pow_w'] < 0) { $s['house_pow_w'] = 0.0; }

        // Effektiver Tibber-Preis nach §14a-Reduktion
        if ($s['enwg_in_window'] && $s['tib_active']) {
            $reduction          = $this->ReadPropertyInteger('ENWG14A_Reduction_Pct') / 100.0;
            $s['tib_price_eff'] = $s['tib_price'] * (1.0 - $reduction);
        } else {
            $s['tib_price_eff'] = $s['tib_price'];
        }

        $s['timestamp'] = time();

        $this->emsLog(EMS_LOG_VERBOSE, sprintf(
            'State: Grid=%.0fW PV=%.0fW Bat=%.0fW(SOC=%.0f%%) HP=%.0fW Tibber=%.2f->%.2fct %s',
            $s['grid_total_w'], $s['pv_total_w'], $s['bat_pow_w'], $s['bat_soc'],
            $s['hp_pow_w'], $s['tib_price'], $s['tib_price_eff'],
            $s['enwg_in_window'] ? '[14a aktiv]' : ''
        ));

        return $s;
    }

    // ----------------------------------------------------------------
    //  Layer 2: Statusvariablen aktualisieren
    // ----------------------------------------------------------------

    private function updateStatusVars($s)
    {
        $this->SetValue('EMS_GridPower',   $s['grid_total_w']);
        $this->SetValue('EMS_PVPower',     $s['pv_total_w']);
        $this->SetValue('EMS_BatPower',    $s['bat_pow_w']);
        $this->SetValue('EMS_BatSOC',      $s['bat_soc']);
        $this->SetValue('EMS_HousePower',  $s['house_pow_w']);
        $this->SetValue('EMS_WB1Power',    $s['wb1_pow_kw'] * 1000);
        $this->SetValue('EMS_WB2Power',    $s['wb2_pow_kw'] * 1000);
        $this->SetValue('EMS_TibberPrice', $s['tib_price']);
    }

    // ----------------------------------------------------------------
    //  Layer 3: Optimierungs-Layer
    //  Hinweis: Schutzfunktionen (Temperatur, Zellspannung, SLS) macht
    //  die Hardware selbst (Goodwe WR + BMS). Die EMS-Leistungs-
    //  einstellung ist per IPS-Variablenprofil auf max. 34500 W begrenzt.
    // ----------------------------------------------------------------

    private function optimize($s)
    {
        $d = array(
            'op_mode'    => EMS_OP_AUTO,
            'gw_mode'    => GW_MODE_AUTO,
            'gw_power_w' => 0,
            // ctl_ems_enable: true = EMS uebernimmt aktiv die Kontrolle (WR
            // wartet auf einen expliziten Sollwert), false = WR laeuft komplett
            // autonom mit seiner eigenen Selbstverbrauchslogik. Live bestaetigt
            // 30.07.2026 (InverterHub, in beide Richtungen reproduziert): das
            // SEMS+-Portal zeigt bei enable=true explizit "3rd party EMS" an --
            // der WR uebergibt dann die GESAMTE Entscheidungshoheit, auch im
            // Modus "Automatik". Nur im Fallback-Branch (7) bewusst false.
            'gw_enable'  => true,
            'wb1_enable' => false,
            'wb2_enable' => false,
            'reason'     => '',
        );

        // ── Grid Rewards ─────────────────────────────────────────────
        // Tibber steuert Wallbox direkt. Batterie darf weder laden noch
        // entladen. Der Goodwe importiert nur so viel wie die Wallboxen
        // und der Hausverbrauch benoetigen — keine Batterieladung.
        if ($s['grid_rewards']) {
            $wbTotalW = (int)round(($s['wb1_pow_kw'] + $s['wb2_pow_kw']) * 1000);
            $houseW   = (int)round($s['house_pow_w']);
            // Leistungseinstellung = nur aktueller Verbrauch ohne Batterie
            // Goodwe darf nur Haus + WB aus Netz versorgen, PV geht in Eigenverbrauch
            $importLimit = max(0, $houseW + $wbTotalW);
            $d['op_mode']    = EMS_OP_GRIDREWARDS;
            $d['gw_mode']    = GW_MODE_AC_IMPORT;
            $d['gw_power_w'] = $importLimit;
            $d['wb1_enable'] = false; // Tibber steuert Wallbox direkt
            $d['wb2_enable'] = false;
            $d['reason']     = sprintf(
                'Grid Rewards: Tibber steuert WB, Import-Limit=%.0fW (Haus=%.0fW WB=%.0fW)',
                $importLimit, $houseW, $wbTotalW
            );
            return $d;
        }

        // ── Batterie-Boost (Nutzerwunsch 29.07.2026, Vorbild evcc) ──────
        // Manuell ausgeloester, zeitlich begrenzter Modus: Batterie entlaedt
        // mit maximaler Leistung, alle Wallboxen werden freigegeben, damit ein
        // Fahrzeug moeglichst schnell laedt (z.B. kurz vor Abfahrt) --
        // unabhaengig von Preis-/PV-Logik. Ueberstimmt bewusst nichts an der
        // Batterie-Reserve (socMin+Reserve bleibt Grenze), damit der Boost nicht
        // die Notreserve leerfahren kann.
        $boostUntil = $this->ReadAttributeInteger('BatteryBoostUntil');
        if ($boostUntil > time()) {
            $socMinBoost = (float)$this->ReadPropertyInteger('BAT_SOC_Min')
                + (float)$this->ReadPropertyInteger('BAT_SOC_Reserve_Backup');
            if ($s['bat_active'] && $s['bat_soc'] > $socMinBoost) {
                $d['op_mode']    = EMS_OP_DISCHARGE;
                $d['gw_mode']    = GW_MODE_DISCHARGE;
                $d['gw_power_w'] = (int)$this->ReadPropertyInteger('EMS_Max_Power_W');
                $d['wb1_enable'] = ($s['wb1_cable'] > 0 && $s['wb1_error'] === 0);
                $d['wb2_enable'] = ($s['wb_count'] >= 2 && $s['wb2_cable'] > 0 && $s['wb2_error'] === 0);
                $d['reason']     = sprintf(
                    '🚀 Batterie-Boost aktiv (noch %ds): volle Entladung fuer Schnellladen, SOC=%.0f%%',
                    $boostUntil - time(), $s['bat_soc']
                );
                return $d;
            }
            // SOC-Grenze erreicht -- Boost frueher als geplant beenden, statt
            // stumm weiterzulaufen und die Reserve anzugreifen.
            $this->WriteAttributeInteger('BatteryBoostUntil', 0);
            $this->emsLog(EMS_LOG_BASIC, 'Batterie-Boost vorzeitig beendet: SOC-Reserve erreicht');
        }

        $socMin         = (float)$this->ReadPropertyInteger('BAT_SOC_Min');
        $socTargetNight = (float)$this->ReadPropertyInteger('BAT_SOC_Target_Night');
        $hystSoc        = (float)$this->ReadPropertyInteger('OPT_Hysteresis_SOC');
        $maxW           = (float)$this->ReadPropertyInteger('EMS_Max_Power_W');
        $thWB           = (float)$this->ReadPropertyFloat('TIB_Threshold_WB');

        $price          = $s['tib_price_eff'];
        $soc            = $s['bat_soc'];
        $pvW            = $s['pv_total_w'];

        // ── 1. §14a Nacht-Laden ──────────────────────────────────────
        if ($s['enwg_in_window'] && $s['bat_active'] && $soc < ($socTargetNight - $hystSoc)) {
            $d['op_mode']    = EMS_OP_NET_CHARGE;
            $d['gw_mode']    = GW_MODE_AC_IMPORT;
            $d['gw_power_w'] = (int)$maxW;
            $d['wb1_enable'] = ($s['wb1_cable'] > 0 && $s['wb1_error'] === 0);
            $d['wb2_enable'] = ($s['wb_count'] >= 2 && $s['wb2_cable'] > 0 && $s['wb2_error'] === 0);
            $d['reason']     = sprintf(
                '14a Nacht-Laden: SOC=%.0f%% Ziel=%.0f%% Preis=%.2fct(eff)',
                $soc, $socTargetNight, $price
            );
            return $d;
        }

        // ── 2b. Gruenste Ladezeit → Netz laden (optional, Vorbild evcc) ──
        // Bleibt reaktiv statt Teil des Tagesplans: StromGedacht liefert nur
        // den JETZIGEN Gruenstrom-Index (GSI), keine Prognose fuer kuenftige
        // Slots -- kann also nicht vorausgeplant werden (Grundregel "keine
        // eigene Anlage als Norm" -- nicht raten ohne Datengrundlage).
        // Bewusst standardmaessig deaktiviert (GREEN_Charge_Enabled=false).
        if ($this->ReadPropertyBoolean('GREEN_Charge_Enabled') && $s['bat_active'] && $soc < ($socTargetNight - $hystSoc)) {
            $greenScore = $this->getCurrentGreenScore();
            $greenThreshold = (float)$this->ReadPropertyInteger('GREEN_GSI_Threshold');
            if ($greenScore !== null && $greenScore >= $greenThreshold) {
                $d['op_mode']    = EMS_OP_NET_CHARGE;
                $d['gw_mode']    = GW_MODE_AC_IMPORT;
                $d['gw_power_w'] = (int)$maxW;
                $d['wb1_enable'] = ($s['wb1_cable'] > 0 && $s['wb1_error'] === 0);
                $d['wb2_enable'] = ($s['wb_count'] >= 2 && $s['wb2_cable'] > 0 && $s['wb2_error'] === 0);
                $d['reason']     = sprintf(
                    'Gruenste Ladezeit: GSI=%.0f >= %.0f, Netz laden',
                    $greenScore, $greenThreshold
                );
                return $d;
            }
        }

        // ── 3. Tagesplan: was hat BuildDayPlan() fuer den aktuellen
        // Viertelstunden-Slot vorgesehen? (PT15M-Preise + PVF-Prognose +
        // Lastschaetzung, vorausschauend statt nur reaktiv -- ersetzt die
        // bisherigen, rein auf dem AKTUELLEN Momentwert basierenden
        // Branches 2-6, siehe BuildDayPlan()-Kommentar. Materialisiert als
        // sichtbarer Wochenplan, siehe ensureDayPlanEvent().)
        $planned = $this->applyPlanSlot($s);
        if ($planned !== null) {
            return $planned;
        }

        // ── 4. Fallback: Automatik (kein Tagesplan vorhanden, z.B. PVF/LFC
        // fehlt oder noch keine Preisdaten da -- oder das Plan-Sicherheits-
        // netz in applyPlanSlot() hat ausgeloest) ────────────────────────
        // ctl_ems_enable=false setzen (siehe Kommentar bei $d-Initialisierung):
        // live bestaetigt 30.07.2026, dass enable=true+mode=Automatik den WR
        // in einen "3rd party EMS"-Wartezustand versetzt (kaum PV-Ernte, wartet
        // auf expliziten Sollwert), waehrend enable=false die volle autonome
        // Selbstverbrauchslogik des WR aktiviert -- genau das, was der Fallback
        // eigentlich will.
        $wb1En = ($s['wb_active'] && $s['wb1_cable'] > 0 && $s['wb1_error'] === 0 && (!$s['tib_active'] || $price < $thWB));
        $wb2En = ($s['wb_active'] && $s['wb_count'] >= 2 && $s['wb2_cable'] > 0 && $s['wb2_error'] === 0 && (!$s['tib_active'] || $price < $thWB));

        $d['op_mode']    = EMS_OP_AUTO;
        $d['gw_mode']    = GW_MODE_AUTO;
        $d['gw_power_w'] = 0;
        $d['gw_enable']  = false;
        $d['wb1_enable'] = $wb1En;
        $d['wb2_enable'] = $wb2En;
        $d['reason']     = sprintf('Automatik (WR autonom, ctl_ems_enable=false, kein Tagesplan-Eintrag): SOC=%.0f%% Preis=%.2fct PV=%.0fW', $soc, $price, $pvW);
        return $d;
    }

    // ----------------------------------------------------------------
    //  Layer 5: Steuerungs-Layer
    // ----------------------------------------------------------------

    /**
     * Schuetzt den Netzanschluss vor Ueberlast, wenn mehrere Wallboxen (und
     * die uebrige Grundlast) zusammen mehr ziehen wuerden als konfiguriert
     * erlaubt (`SITE_Max_Grid_Import_W`, 0=deaktiviert). Schaltet bei
     * Ueberschreitung die Wallbox(en) mit der niedrigsten Prioritaet
     * (hoechste `WB{n}_Priority`-Zahl) ab, bis das Budget eingehalten wird.
     * Reine Enable/Disable-Entscheidung auf EMS-Ebene -- die eigentliche
     * Strombegrenzung je Ladepunkt bleibt ChargerHubs Aufgabe.
     */
    private function enforceGridImportBudget(&$d, $s)
    {
        $limit = (float)$this->ReadPropertyInteger('SITE_Max_Grid_Import_W');
        if ($limit <= 0) {
            return; // Feature deaktiviert
        }

        $wbs = array(
            1 => array(
                'enable'   => $d['wb1_enable'],
                'currentW' => $s['wb1_pow_kw'] * 1000,
                'maxW'     => (float)$this->ReadPropertyInteger('WB1_Max_Power_W'),
                'priority' => (int)$this->ReadPropertyInteger('WB1_Priority'),
            ),
        );
        if ($s['wb_count'] >= 2) {
            $wbs[2] = array(
                'enable'   => $d['wb2_enable'],
                'currentW' => $s['wb2_pow_kw'] * 1000,
                'maxW'     => (float)$this->ReadPropertyInteger('WB2_Max_Power_W'),
                'priority' => (int)$this->ReadPropertyInteger('WB2_Priority'),
            );
        }

        // Projizierten Netzbezug schaetzen: aktueller Bezug, angepasst um
        // die geplanten Aenderungen je Wallbox (neu an: + max. Leistung als
        // Worst-Case, neu aus: - zuletzt gemessene Leistung).
        $projected = $s['grid_total_w'];
        foreach ($wbs as $wb) {
            $wasOn = $wb['currentW'] > 100;
            if ($wb['enable'] && !$wasOn) {
                $projected += $wb['maxW'];
            } elseif (!$wb['enable'] && $wasOn) {
                $projected -= $wb['currentW'];
            }
        }
        if ($projected <= $limit) {
            return; // Budget eingehalten
        }

        // Niedrigste Prioritaet zuerst abschalten (hoechste Prioritaetszahl).
        uasort($wbs, function ($a, $b) { return $b['priority'] <=> $a['priority']; });
        $overBy    = $projected - $limit;
        $throttled = array();
        foreach ($wbs as $num => $wb) {
            if ($overBy <= 0) {
                break;
            }
            if (!$wb['enable']) {
                continue;
            }
            $d['wb' . $num . '_enable'] = false;
            $overBy -= $wb['maxW'];
            $throttled[] = 'WB' . $num;
        }
        if (!empty($throttled)) {
            $d['reason'] .= sprintf(
                ' | Lastverteilung: %s gedrosselt (Netzanschluss-Budget %.0fW, projiziert %.0fW)',
                implode('+', $throttled), $limit, $projected
            );
            $this->emsLog(EMS_LOG_BASIC, 'Lastverteilungs-Budget ueberschritten: ' . implode('+', $throttled) . ' abgeschaltet');
        }
    }

    private function applyDecision($d, $s)
    {
        $lastMode     = $this->ReadAttributeInteger('LastGoodweMode');
        $lastEnable   = $this->ReadAttributeBoolean('LastGoodweEnable');
        $cooldown     = $this->ReadPropertyInteger('OPT_Cooldown_Sec');
        $lastDecision = $this->ReadAttributeInteger('LastDecision');
        $now          = time();

        // Kontinuierliche Regelschleife (OpenEMS-Vorbild, 27.07.2026): der
        // Sollwert wird JEDEN Zyklus neu geschrieben, nicht nur bei
        // Aenderung -- ein einmaliger Schreibvorgang faellt sonst GoodWes
        // eigenem SMART-Modus zum Opfer (live beobachtet, siehe Abschnitt
        // "GoodWe-Steuerregister" in SUITE.md: ctl_ems_mode springt bei den
        // meisten Werten von selbst auf Sentinel 255 zurueck, wenn er nicht
        // laufend reasserted wird).
        //
        // Cooldown gilt nur fuer den eigentlichen MODUSWECHSEL (verhindert
        // Thrashing zwischen Modi bei knapp schwankenden Schwellwerten) --
        // waehrend der Cooldown-Phase wird trotzdem der zuletzt aktive
        // Modus weiter reasserted, statt komplett zu pausieren.
        $isGridRewards  = ($d['op_mode'] === EMS_OP_GRIDREWARDS);
        $gwEnable       = $d['gw_enable'] ?? true;
        $modeChanging   = ($d['gw_mode'] !== $lastMode || $gwEnable !== $lastEnable);

        if ($modeChanging && !$isGridRewards && ($now - $lastDecision) < $cooldown) {
            $this->emsLog(EMS_LOG_VERBOSE, 'Cooldown aktiv (' . ($now - $lastDecision) . 's < ' . $cooldown . 's) -- reassert letzter Modus ' . $lastMode . '/enable=' . ($lastEnable ? '1' : '0'));
            $this->setGoodweMode($lastMode, 0, $lastEnable);
            return;
        }

        $this->setGoodweMode($d['gw_mode'], $d['gw_power_w'], $gwEnable);
        if ($modeChanging || $isGridRewards) {
            $this->WriteAttributeInteger('LastGoodweMode',   $d['gw_mode']);
            $this->WriteAttributeBoolean('LastGoodweEnable', $gwEnable);
            $this->WriteAttributeInteger('LastDecision',     $now);
            $this->emsLog(EMS_LOG_BASIC, 'Goodwe -> Modus ' . $d['gw_mode'] . ' (enable=' . ($gwEnable ? '1' : '0') . ') | ' . $d['reason']);
        } else {
            $this->emsLog(EMS_LOG_VERBOSE, 'Goodwe -> Modus ' . $d['gw_mode'] . ' (reassert) | ' . $d['reason']);
        }

        // Leistungseinstellung immer aktualisieren wenn gesetzt
        if ($d['gw_power_w'] > 0) {
            $varPower = $this->ReadPropertyInteger('VAR_WR_EMS_Power');
            if ($varPower > 0) {
                $this->writeVar($varPower, $d['gw_power_w']);
            }
        }

        // Lastverteilung/Netzanschluss-Budget (Nutzerwunsch 29.07.2026, Vorbild
        // evcc: Leistung ueber mehrere Ladepunkte verteilen, Netzanschluss vor
        // Ueberlast schuetzen). Kann die Wallbox-Freigaben aus optimize() noch
        // nachtraeglich zurücknehmen, bevor sie an ChargerHub geschickt werden.
        if ($s['wb_active']) {
            $this->enforceGridImportBudget($d, $s);
            $this->controlWallbox(1, $d['wb1_enable']);
            if ($s['wb_count'] >= 2) {
                $this->controlWallbox(2, $d['wb2_enable']);
            }
        }

        $this->SetValue('EMS_Mode',       $d['op_mode']);
        $this->SetValue('EMS_LastAction', $d['reason']);
        $this->SetValue('EMS_Status',     'OK: ' . $d['reason']);
    }

    /**
     * Schreibt den Goodwe-EMS-Modus/-Leistungswert. Bevorzugt den
     * NRG-Stack-Kontrollkanal (IPS_RequestAction auf den automatisch
     * gefundenen WR, nur wenn dessen controlAuthority == 'ems' ist —
     * Situation A). GW_MODE_*-Konstanten entsprechen 1:1 dem Goodwe-
     * Register 47511, das sowohl das alte VAR_WR_EMS_Mode als auch
     * InverterHubs ctl_ems_mode adressiert, deshalb keine Modus-Uebersetzung
     * noetig. Fallback: alte manuelle Variablenverknuepfung.
     */
    private function setGoodweMode($mode, $powerW, $enable = true)
    {
        $inv = $this->getInverterEntry();

        if ($inv !== null && $inv['source'] === 'inverterhub') {
            $authority = $inv['controlAuthority'] ?? 'none';
            if ($authority === 'ems' && ($inv['controllable'] ?? false)) {
                // RequestAction() ist ein IPSModule-Kernel-Methodenname und wird NIE als
                // Prefix_RequestAction() exponiert -- der Kernel-Einstiegspunkt ist
                // IPS_RequestAction($InstanceID, $Ident, $Value) (siehe ChargerHub-Befund
                // 25.07.2026, gleiche Ursache bei InverterHub).
                //
                // ctl_ems_enable (Register 47505) ist der Hauptschalter. Zwei
                // gegenlaeufige, beide live bestaetigte Effekte:
                // - =false: Goodwe ignoriert ctl_ems_mode/ctl_ems_power komplett und
                //   faehrt seine eigene Selbstverbrauchslogik (Fund 25.07.2026).
                // - =true: SEMS+-Portal zeigt "3rd party EMS", der WR uebergibt die
                //   GESAMTE Entscheidungshoheit an uns -- "Automatik"+enable=true
                //   heisst dann "warte auf expliziten Sollwert", nicht "WR entscheidet
                //   selbst" (Fund 30.07.2026, in beide Richtungen reproduziert).
                // Deshalb schreibt der Aufrufer (optimize()) jetzt explizit, ob er
                // gerade wirklich die Kontrolle will ($enable=true) oder den WR
                // autonom laufen lassen will ($enable=false, siehe Fallback-Branch 7).
                IPS_RequestAction($inv['instanceID'], 'ctl_ems_enable', (bool)$enable);
                IPS_RequestAction($inv['instanceID'], 'ctl_ems_mode', $mode);
                // IMMER schreiben, auch 0 -- ctl_ems_power (Register 47512) ist in
                // GW_MODE_CHARGE_PV keine additive Zusatzleistung, sondern eine
                // Netzbezugs-OBERGRENZE (Batterie-Ziel = ctl_ems_power(Netz) + PV,
                // lt. GoodWe Modbus-Doku ARM205-HV Tab. 8-16). Ein "if ($powerW > 0)"
                // liess hier frueher einen stehengebliebenen alten Wert unangetastet
                // (live beobachtet 27.07.2026: 3000W Altwert fuehrte zu 3,4kW
                // ungewolltem Netzbezug trotz $powerW=0 in der aktuellen Entscheidung).
                IPS_RequestAction($inv['instanceID'], 'ctl_ems_power', (int)$powerW);
                return;
            }
            $this->emsLog(EMS_LOG_BASIC, sprintf(
                'setGoodweMode: WR #%d hat Steuerhoheit "%s" (nicht "ems") oder ist nicht steuerbar — EMS greift nicht ein (Situation B)',
                $inv['instanceID'], $authority
            ));
            return;
        }

        // Fallback: alte manuelle Variablenverknuepfung (kein Partnermodul gefunden)
        $varMode  = $this->ReadPropertyInteger('VAR_WR_EMS_Mode');
        $varPower = $this->ReadPropertyInteger('VAR_WR_EMS_Power');
        if ($varMode  > 0) { $this->writeVar($varMode, $mode); }
        if ($varPower > 0 && $powerW > 0) { $this->writeVar($varPower, $powerW); }
    }

    /**
     * Wallbox $num (1 oder 2) freigeben/sperren. Bevorzugt den NRG-Stack-
     * ChargerHub-Kontrollkanal (nur Instanzen mit managedBy none/ems,
     * siehe getWritableChargers() — Situation A). Die alte Property
     * WB{n}_Instance dient nur noch als optionale Zuordnung, WELCHER
     * gefundene Lader "WB1" bzw. "WB2" ist; ist sie leer, wird einfach
     * der n-te automatisch gefundene, EMS-steuerbare Lader genommen.
     * Fallback ohne ChargerHub: alte direkte GO-eCharger-Steuerung.
     */
    private function controlWallbox($num, $enable)
    {
        $configuredInstance = $this->ReadPropertyInteger('WB' . $num . '_Instance');
        $chargers = $this->getWritableChargers();
        $entry = null;
        foreach ($chargers as $c) {
            if ($configuredInstance > 0 && ($c['instanceID'] ?? 0) === $configuredInstance) {
                $entry = $c;
                break;
            }
        }
        if ($entry === null && $configuredInstance <= 0 && isset($chargers[$num - 1])) {
            $entry = $chargers[$num - 1];
        }

        if ($entry !== null) {
            $this->controlWallboxViaChargerHub($num, $entry, $enable);
            return;
        }

        // Fallback: alte direkte GO-eCharger-Steuerung ueber manuelle Instanz-Property
        if ($configuredInstance <= 0 || !function_exists('GOeCharger_SetMode')) { return; }

        $lastSwitch = $this->ReadAttributeInteger('LastWB' . $num . 'Switch');
        $cooldown   = $this->ReadPropertyInteger('WB_Cooldown_Sec');
        if ((time() - $lastSwitch) < $cooldown) {
            $this->emsLog(EMS_LOG_VERBOSE, 'WB' . $num . ': Cooldown aktiv');
            return;
        }

        $isActive = ((int)$this->readVar('VAR_WB' . $num . '_Active', 0) == 1);

        if ($enable && !$isActive) {
            $maxPower = $this->ReadPropertyInteger('WB' . $num . '_Max_Power_W');
            GOeCharger_SetMode($configuredInstance, 2);
            GOeCharger_SetCurrentChargingWatt($configuredInstance, $maxPower);
            $this->WriteAttributeInteger('LastWB' . $num . 'Switch', time());
            $this->emsLog(EMS_LOG_BASIC, 'WB' . $num . ' freigegeben (' . $maxPower . ' W)');
        } elseif (!$enable && $isActive) {
            GOeCharger_SetMode($configuredInstance, 1);
            $this->WriteAttributeInteger('LastWB' . $num . 'Switch', time());
            $this->emsLog(EMS_LOG_BASIC, 'WB' . $num . ' gesperrt');
        }
    }

    private function controlWallboxViaChargerHub($num, $entry, $enable)
    {
        $instance = $entry['instanceID'] ?? 0;
        if ($instance <= 0) { return; }

        $lastSwitch = $this->ReadAttributeInteger('LastWB' . $num . 'Switch');
        $cooldown   = $this->ReadPropertyInteger('WB_Cooldown_Sec');
        if ((time() - $lastSwitch) < $cooldown) {
            $this->emsLog(EMS_LOG_VERBOSE, 'WB' . $num . ': Cooldown aktiv');
            return;
        }

        $chargeEnableID = $entry['chargeEnableID'] ?? 0;
        $isActive = ($chargeEnableID > 0 && IPS_VariableExists($chargeEnableID)) ? (bool)GetValue($chargeEnableID) : false;

        if ($enable && !$isActive) {
            $maxCurrentA = (int)($entry['maxCurrent'] ?? 16);
            $configuredMaxW = $this->ReadPropertyInteger('WB' . $num . '_Max_Power_W');
            if ($configuredMaxW > 0) {
                $maxCurrentA = min($maxCurrentA, max(6, (int)round($configuredMaxW / 230)));
            }
            // RequestAction() ist Kernel-reserviert, nie als Prefix_RequestAction()
            // exponiert -- Einstiegspunkt ist IPS_RequestAction() (siehe ChargerHub-Befund
            // 25.07.2026).
            IPS_RequestAction($instance, 'ctl_curr_limit', $maxCurrentA);
            IPS_RequestAction($instance, 'ctl_enable', true);
            $this->WriteAttributeInteger('LastWB' . $num . 'Switch', time());
            $this->emsLog(EMS_LOG_BASIC, 'WB' . $num . ' (ChargerHub #' . $instance . ') freigegeben (' . $maxCurrentA . ' A)');
        } elseif (!$enable && $isActive) {
            IPS_RequestAction($instance, 'ctl_enable', false);
            $this->WriteAttributeInteger('LastWB' . $num . 'Switch', time());
            $this->emsLog(EMS_LOG_BASIC, 'WB' . $num . ' (ChargerHub #' . $instance . ') gesperrt');
        }
    }

    private function setAllWallboxes($enable)
    {
        if (!$this->ReadPropertyBoolean('WB_Active')) { return; }
        $count = $this->ReadPropertyInteger('WB_Count');
        for ($i = 1; $i <= min($count, 2); $i++) {
            $instance = $this->ReadPropertyInteger('WB' . $i . '_Instance');
            if ($instance > 0) {
                GOeCharger_SetMode($instance, $enable ? 2 : 1);
            }
        }
    }

    // ----------------------------------------------------------------
    //  Fallback
    // ----------------------------------------------------------------

    private function applyFallback()
    {
        $fallbackMode = $this->ReadPropertyInteger('EMS_Fallback_Mode');
        $this->emsLog(EMS_LOG_BASIC, 'Fallback aktiv - Goodwe Modus ' . $fallbackMode);
        // enable=false: bei wiederholten Kommunikationsfehlern kann EMS ohnehin
        // keinen verlaesslichen Sollwert mehr liefern -- der WR soll dann autonom
        // laufen (siehe Fund 30.07.2026), nicht scharfgeschaltet auf einen
        // Sollwert warten, der wegen der Kommunikationsstoerung ausbleibt.
        $this->setGoodweMode($fallbackMode, 0, false);
        $this->SetValue('EMS_Status', 'FALLBACK - Kommunikationsfehler');
        $this->SetStatus(200);
    }

    // ----------------------------------------------------------------
    //  Hilfsfunktionen
    // ----------------------------------------------------------------

    /**
     * Liest eine externe IPS-Variable sicher aus.
     * Gibt $default zurueck wenn Variable nicht konfiguriert oder nicht vorhanden.
     */
    private function readVar($property, $default)
    {
        $varId = $this->ReadPropertyInteger($property);
        if ($varId <= 0) { return $default; }
        if (!IPS_VariableExists($varId)) {
            $this->emsLog(EMS_LOG_VERBOSE, 'Variable ' . $property . ' (ID ' . $varId . ') nicht gefunden');
            return $default;
        }
        return GetValue($varId);
    }

    /**
     * Schreibt einen Wert auf eine externe IPS-Variable.
     * Verwendet RequestAction() fuer Modbus/read-only Variablen,
     * SetValue() als Fallback.
     */
    private function writeVar($varId, $value)
    {
        if ($varId <= 0) { return; }
        if (!IPS_VariableExists($varId)) {
            $this->emsLog(EMS_LOG_VERBOSE, 'writeVar: Variable ID ' . $varId . ' nicht gefunden');
            return;
        }
        $varInfo = IPS_GetVariable($varId);
        if ($varInfo['VariableAction'] > 0) {
            RequestAction($varId, $value);
        } else {
            SetValue($varId, $value);
        }
    }

    /**
     * Prueft ob aktuelle Uhrzeit im §14a-Zeitfenster liegt.
     */
    private function isInEnwgWindow()
    {
        $startH = $this->ReadPropertyInteger('ENWG14A_Start_Hour');
        $endH   = $this->ReadPropertyInteger('ENWG14A_End_Hour');
        return $this->slotInEnwgWindow((int)date('G'), $startH, $endH);
    }

    /**
     * Logging: SendDebug ins Instanz-Debug-Fenster +
     * IPS_LogMessage ins Systemlog (nur Basis-Level).
     */
    private function emsLog($level, $message)
    {
        $configLevel = $this->ReadPropertyInteger('EMS_Log_Level');
        if ($level > $configLevel) { return; }
        $prefix = ($level === EMS_LOG_VERBOSE) ? 'VERBOSE' : 'INFO';
        $this->SendDebug($prefix, $message, 0);
        if ($level <= EMS_LOG_BASIC) {
            IPS_LogMessage('EMS', $message);
        }
    }
}
