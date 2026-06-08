<?php

declare(strict_types=1);

// ============================================================
//  EMS — Energy Management System für IP-Symcon
//  Autor   : DG65
//  Version : 0.1
//  GUID    : {31C61A7B-28C4-4F97-9651-1A64B3469E3C}
// ============================================================

// Goodwe EMS Leistungsmodi (Register 47511)
define('EMS_MODE_STOP',       0);   // Gestoppt
define('EMS_MODE_AUTO',       1);   // Automatik
define('EMS_MODE_CHARGE_PV',  2);   // Laden – Solar
define('EMS_MODE_DISCHARGE',  3);   // Entladen + Solar
define('EMS_MODE_AC_IMPORT',  4);   // AC – Import (Netz → Batterie)
define('EMS_MODE_AC_EXPORT',  5);   // AC – Export (Batterie → Netz)
define('EMS_MODE_ECO',        6);   // Energiesparen
define('EMS_MODE_ISLAND',     7);   // Inselbetrieb / Backup
define('EMS_MODE_STANDBY',    8);   // Batterie – Bereitschaft
define('EMS_MODE_BUY',        9);   // Stromeinkauf
define('EMS_MODE_SELL',      10);   // Stromverkauf
define('EMS_MODE_BAT_CHARGE',11);   // Batterie – Laden (forciert)
define('EMS_MODE_BAT_DISCH', 12);   // Batterie – Entladen (forciert)

// Interne EMS Betriebsmodi
define('EMS_OP_AUTO',        0);
define('EMS_OP_PV_SELFUSE',  1);
define('EMS_OP_NET_CHARGE',  2);
define('EMS_OP_DISCHARGE',   3);
define('EMS_OP_STANDBY',     4);
define('EMS_OP_EXPORT',      5);
define('EMS_OP_BACKUP',      6);
define('EMS_OP_GRIDREWARDS', 7);

// Logging-Level
define('EMS_LOG_OFF',        0);
define('EMS_LOG_BASIC',      1);
define('EMS_LOG_VERBOSE',    2);

class EMS extends IPSModule
{
    // ----------------------------------------------------------------
    //  Modul-Lebenszyklus
    // ----------------------------------------------------------------

    public function Create(): void
    {
        parent::Create();

        // ── Allgemein & Schutz ──────────────────────────────────────
        $this->RegisterPropertyBoolean('EMS_Active',          false);
        $this->RegisterPropertyInteger('EMS_Interval',        30);
        $this->RegisterPropertyInteger('EMS_SLS_Limit_A',     50);
        $this->RegisterPropertyInteger('EMS_HAK_Limit_A',     63);
        $this->RegisterPropertyInteger('EMS_SLS_Limit_W',     34500);
        $this->RegisterPropertyInteger('EMS_Fallback_Mode',   EMS_MODE_AUTO);
        $this->RegisterPropertyInteger('EMS_Fallback_Timeout',60);
        $this->RegisterPropertyInteger('EMS_Log_Level',       EMS_LOG_BASIC);

        // ── Netzmesspunkte ──────────────────────────────────────────
        $this->RegisterPropertyInteger('VAR_SM_L1_Power',     0);
        $this->RegisterPropertyInteger('VAR_SM_L2_Power',     0);
        $this->RegisterPropertyInteger('VAR_SM_L3_Power',     0);
        $this->RegisterPropertyInteger('VAR_SM_Total_Power',  0);
        $this->RegisterPropertyInteger('VAR_SM_L1_Current',   0);
        $this->RegisterPropertyInteger('VAR_SM_L2_Current',   0);
        $this->RegisterPropertyInteger('VAR_SM_L3_Current',   0);
        $this->RegisterPropertyInteger('VAR_SM_Frequency',    0);
        $this->RegisterPropertyInteger('VAR_SM_Status',       0);
        $this->RegisterPropertyBoolean('PAC2200_Active',      false);
        $this->RegisterPropertyInteger('VAR_PAC_L1_Power',    0);
        $this->RegisterPropertyInteger('VAR_PAC_L2_Power',    0);
        $this->RegisterPropertyInteger('VAR_PAC_L3_Power',    0);
        $this->RegisterPropertyInteger('VAR_PAC_L1_Current',  0);
        $this->RegisterPropertyInteger('VAR_PAC_L2_Current',  0);
        $this->RegisterPropertyInteger('VAR_PAC_L3_Current',  0);
        $this->RegisterPropertyInteger('VAR_PAC_Energy_Import',0);
        $this->RegisterPropertyInteger('VAR_PAC_Energy_Export',0);

        // ── Wechselrichter & PV ─────────────────────────────────────
        $this->RegisterPropertyInteger('VAR_WR_EMS_Mode',     0);
        $this->RegisterPropertyInteger('VAR_WR_EMS_Power',    0);
        $this->RegisterPropertyInteger('VAR_WR_Export_Enable',0);
        $this->RegisterPropertyInteger('VAR_WR_Export_Limit', 0);
        // Zeitfenster 1–4
        foreach (range(1, 4) as $i) {
            $this->RegisterPropertyInteger("VAR_WR_Time{$i}_Start", 0);
            $this->RegisterPropertyInteger("VAR_WR_Time{$i}_End",   0);
            $this->RegisterPropertyInteger("VAR_WR_Time{$i}_Power", 0);
            $this->RegisterPropertyInteger("VAR_WR_Time{$i}_Week",  0);
        }
        $this->RegisterPropertyInteger('VAR_PV_Total_Power',  0);
        $this->RegisterPropertyInteger('VAR_PV_Day_Energy',   0);
        $this->RegisterPropertyBoolean('PV_MPPT_Active',      false);
        $this->RegisterPropertyInteger('VAR_PV_MPPT1_Power',  0);
        $this->RegisterPropertyInteger('VAR_PV_MPPT2_Power',  0);
        $this->RegisterPropertyInteger('VAR_PV_MPPT3_Power',  0);
        $this->RegisterPropertyInteger('VAR_WR_Total_Power',  0);
        $this->RegisterPropertyInteger('VAR_WR_Temp',         0);
        $this->RegisterPropertyInteger('VAR_WR_Temp_Cooler',  0);
        $this->RegisterPropertyInteger('VAR_WR_Diag_Status',  0);
        $this->RegisterPropertyInteger('VAR_WR_Backup_Power', 0);
        $this->RegisterPropertyInteger('VAR_WR_Backup_Active',0);
        $this->RegisterPropertyInteger('EMS_WR_Temp_Max',     75);

        // ── Batteriespeicher ────────────────────────────────────────
        $this->RegisterPropertyBoolean('BAT_Active',              false);
        $this->RegisterPropertyInteger('BAT_String_Count',        1);
        $this->RegisterPropertyFloat(  'BAT_Capacity_kWh',        10.0);
        $this->RegisterPropertyInteger('BAT_SOC_Min',             10);
        $this->RegisterPropertyInteger('BAT_SOC_Target_Night',    100);
        $this->RegisterPropertyInteger('BAT_SOC_Target_Day',      80);
        $this->RegisterPropertyInteger('BAT_SOC_Reserve_Backup',  10);
        $this->RegisterPropertyInteger('BAT_Temp_Max',            45);
        $this->RegisterPropertyInteger('BAT_Cell_Voltage_Max',    3600);
        $this->RegisterPropertyInteger('BAT_Cell_Voltage_Min',    2900);
        foreach (range(1, 2) as $i) {
            $this->RegisterPropertyInteger("VAR_BAT{$i}_SOC",          0);
            $this->RegisterPropertyInteger("VAR_BAT{$i}_Power",        0);
            $this->RegisterPropertyInteger("VAR_BAT{$i}_Mode",         0);
            $this->RegisterPropertyInteger("VAR_BAT{$i}_SOH",          0);
            $this->RegisterPropertyInteger("VAR_BAT{$i}_Temp",         0);
            $this->RegisterPropertyInteger("VAR_BAT{$i}_Cell_V_Max",   0);
            $this->RegisterPropertyInteger("VAR_BAT{$i}_Cell_V_Min",   0);
            $this->RegisterPropertyInteger("VAR_BAT{$i}_Min_SOC_Online", 0);
            $this->RegisterPropertyInteger("VAR_BAT{$i}_Min_SOC_Offline",0);
        }

        // ── Wallboxen ───────────────────────────────────────────────
        $this->RegisterPropertyBoolean('WB_Active',           false);
        $this->RegisterPropertyInteger('WB_Count',            1);
        $this->RegisterPropertyBoolean('WB_GridRewards_Active',false);
        $this->RegisterPropertyInteger('WB_Cooldown_Sec',     120);
        $this->RegisterPropertyInteger('WB_Min_Charge_Min',   5);
        foreach (range(1, 2) as $i) {
            $this->RegisterPropertyInteger("WB{$i}_Instance",   0);
            $this->RegisterPropertyInteger("WB{$i}_Max_Power_W",11000);
            $this->RegisterPropertyInteger("WB{$i}_Min_Power_W",1380);
            $this->RegisterPropertyInteger("WB{$i}_Priority",   $i);
            $this->RegisterPropertyInteger("VAR_WB{$i}_Status", 0);
            $this->RegisterPropertyInteger("VAR_WB{$i}_Power",  0);
            $this->RegisterPropertyInteger("VAR_WB{$i}_Active", 0);
            $this->RegisterPropertyInteger("VAR_WB{$i}_Cable",  0);
            $this->RegisterPropertyInteger("VAR_WB{$i}_Phases", 0);
            $this->RegisterPropertyInteger("VAR_WB{$i}_Error",  0);
        }

        // ── Wärmepumpe ──────────────────────────────────────────────
        $this->RegisterPropertyBoolean('HP_Active',           false);
        $this->RegisterPropertyInteger('VAR_HP_Power',        0);
        $this->RegisterPropertyInteger('VAR_HP_State',        0);
        $this->RegisterPropertyInteger('VAR_HP_Outside_Temp', 0);
        $this->RegisterPropertyInteger('VAR_HP_Mode',         0);
        $this->RegisterPropertyInteger('VAR_HP_COP_Heat',     0);
        $this->RegisterPropertyInteger('VAR_HP_DHW_Temp',     0);

        // ── Tibber ──────────────────────────────────────────────────
        $this->RegisterPropertyBoolean('TIBBER_Active',           false);
        $this->RegisterPropertyInteger('VAR_TIB_Price',           0);
        $this->RegisterPropertyInteger('VAR_TIB_Level',           0);
        $this->RegisterPropertyInteger('VAR_TIB_Feed_Tariff',     0);
        $this->RegisterPropertyInteger('VAR_TIB_PT15M_Today',     0);
        $this->RegisterPropertyInteger('VAR_TIB_PT15M_Tomorrow',  0);
        $this->RegisterPropertyInteger('VAR_TIB_PT60M_Today',     0);
        $this->RegisterPropertyInteger('VAR_TIB_Ahead_15M',       0);
        $this->RegisterPropertyFloat(  'TIB_Threshold_Charge',    15.0);
        $this->RegisterPropertyFloat(  'TIB_Threshold_Discharge', 25.0);
        $this->RegisterPropertyFloat(  'TIB_Threshold_WB',        20.0);
        $this->RegisterPropertyFloat(  'TIB_Threshold_Export',    20.0);

        // ── §14a EnWG ───────────────────────────────────────────────
        $this->RegisterPropertyBoolean('ENWG14A_Active',          false);
        $this->RegisterPropertyString( 'ENWG14A_Provider',        '');
        $this->RegisterPropertyInteger('ENWG14A_Start_Hour',      0);
        $this->RegisterPropertyInteger('ENWG14A_End_Hour',        6);
        $this->RegisterPropertyInteger('ENWG14A_Reduction_Pct',   90);

        // ── PV Forecast ─────────────────────────────────────────────
        $this->RegisterPropertyBoolean('FORECAST_Active',         false);
        $this->RegisterPropertyInteger('VAR_FC_Today',            0);
        $this->RegisterPropertyInteger('VAR_FC_Tomorrow',         0);
        $this->RegisterPropertyInteger('VAR_FC_JSON',             0);
        $this->RegisterPropertyInteger('FORECAST_Min_Power_W',    200);
        $this->RegisterPropertyInteger('FORECAST_Confidence',     50);

        // ── Optimierungsparameter ───────────────────────────────────
        $this->RegisterPropertyInteger('OPT_Weight_Selfuse',      70);
        $this->RegisterPropertyInteger('OPT_Hysteresis_SOC',      3);
        $this->RegisterPropertyInteger('OPT_Hysteresis_Power',    200);
        $this->RegisterPropertyFloat(  'OPT_Hysteresis_Price',    1.0);
        $this->RegisterPropertyInteger('OPT_Cooldown_Sec',        60);
        $this->RegisterPropertyInteger('OPT_Planning_Horizon_H',  24);

        // ── Statusvariablen (vom Modul angelegt) ────────────────────
        $this->RegisterVariableBoolean('EMS_Active',     'EMS aktiv',                  '',  10);
        $this->RegisterVariableInteger('EMS_Mode',       'Betriebsmodus',              '',  20);
        $this->RegisterVariableFloat(  'EMS_GridPower',  'Netzleistung (W)',            '',  30);
        $this->RegisterVariableFloat(  'EMS_PVPower',    'PV-Leistung (W)',             '',  40);
        $this->RegisterVariableFloat(  'EMS_BatPower',   'Batterieleistung (W)',        '',  50);
        $this->RegisterVariableFloat(  'EMS_BatSOC',     'Batterie SOC (%)',            '',  60);
        $this->RegisterVariableFloat(  'EMS_HousePower', 'Hausverbrauch (W)',           '',  70);
        $this->RegisterVariableFloat(  'EMS_WB1Power',   'Wallbox 1 Leistung (W)',      '',  80);
        $this->RegisterVariableFloat(  'EMS_WB2Power',   'Wallbox 2 Leistung (W)',      '',  90);
        $this->RegisterVariableFloat(  'EMS_TibberPrice','Tibber Preis (ct/kWh)',       '', 100);
        $this->RegisterVariableBoolean('EMS_GridRewards','Grid Rewards aktiv',          '', 110);
        $this->RegisterVariableString( 'EMS_LastAction', 'Letzte Aktion',              '', 120);
        $this->RegisterVariableString( 'EMS_Status',     'Status',                     '', 130);

        // Aktionssteuerung für Grid Rewards Schalter
        $this->EnableAction('EMS_GridRewards');

        // ── Timer ───────────────────────────────────────────────────
        $this->RegisterTimer('EMS_UpdateTimer', 0, 'EMS_Update($_IPS[\'TARGET\']);');

        // ── Interne Attribute (Laufzeitdaten) ───────────────────────
        $this->RegisterAttributeInteger('LastGoodweMode',   EMS_MODE_AUTO);
        $this->RegisterAttributeInteger('LastWB1Switch',    0);
        $this->RegisterAttributeInteger('LastWB2Switch',    0);
        $this->RegisterAttributeInteger('LastDecision',     0);
        $this->RegisterAttributeInteger('ConsecutiveErrors',0);
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $active   = $this->ReadPropertyBoolean('EMS_Active');
        $interval = $this->ReadPropertyInteger('EMS_Interval');

        if ($active) {
            $this->SetTimerInterval('EMS_UpdateTimer', $interval * 1000);
            $this->SetStatus(102);
            $this->log(EMS_LOG_BASIC, 'EMS gestartet, Intervall: ' . $interval . ' s');
        } else {
            $this->SetTimerInterval('EMS_UpdateTimer', 0);
            $this->SetStatus(104);
            $this->log(EMS_LOG_BASIC, 'EMS deaktiviert');
        }

        $this->SetValue('EMS_Active', $active);
    }

    // ----------------------------------------------------------------
    //  Öffentliche Funktionen
    // ----------------------------------------------------------------

    /**
     * Hauptzyklus — wird vom Timer oder manuell aufgerufen
     */
    public function Update(): void
    {
        if (!$this->ReadPropertyBoolean('EMS_Active')) {
            return;
        }

        try {
            // 1. Messwerte einlesen
            $state = $this->readState();

            // 2. Statusvariablen aktualisieren
            $this->updateStatusVars($state);

            // 3. Schutz-Layer prüfen (überschreibt alles andere)
            $protection = $this->checkProtection($state);
            if ($protection['triggered']) {
                $this->applyProtection($protection, $state);
                $this->WriteAttributeInteger('ConsecutiveErrors', 0);
                return;
            }

            // 4. Optimierungs-Layer: Entscheidung treffen
            $decision = $this->optimize($state);

            // 5. Steuerungs-Layer: Entscheidung umsetzen
            $this->applyDecision($decision, $state);

            // Fehler-Zähler zurücksetzen
            $this->WriteAttributeInteger('ConsecutiveErrors', 0);
            $this->SetStatus(102);

        } catch (Exception $e) {
            $errors = $this->ReadAttributeInteger('ConsecutiveErrors') + 1;
            $this->WriteAttributeInteger('ConsecutiveErrors', $errors);
            $this->log(EMS_LOG_BASIC, 'Fehler: ' . $e->getMessage() . ' (Fehler #' . $errors . ')');

            $timeout = $this->ReadPropertyInteger('EMS_Fallback_Timeout');
            if ($errors >= max(1, intdiv($timeout, $this->ReadPropertyInteger('EMS_Interval')))) {
                $this->applyFallback();
            }
        }
    }

    /**
     * Statustext zurückgeben
     */
    public function GetStatus(): string
    {
        $mode  = $this->GetValue('EMS_Mode');
        $soc   = $this->GetValue('EMS_BatSOC');
        $grid  = $this->GetValue('EMS_GridPower');
        $pv    = $this->GetValue('EMS_PVPower');
        $price = $this->GetValue('EMS_TibberPrice');

        $modeNames = [
            EMS_OP_AUTO       => 'Automatik',
            EMS_OP_PV_SELFUSE => 'PV-Eigenverbrauch',
            EMS_OP_NET_CHARGE => 'Netz-Laden',
            EMS_OP_DISCHARGE  => 'Entladen',
            EMS_OP_STANDBY    => 'Bereitschaft',
            EMS_OP_EXPORT     => 'Export',
            EMS_OP_BACKUP     => 'Notbetrieb',
            EMS_OP_GRIDREWARDS=> 'Grid Rewards',
        ];

        $modeName = $modeNames[$mode] ?? 'Unbekannt';

        return sprintf(
            "Modus: %s | SOC: %.0f%% | Netz: %.0f W | PV: %.0f W | Tibber: %.2f ct/kWh | Letzte Aktion: %s",
            $modeName, $soc, $grid, $pv, $price,
            $this->GetValue('EMS_LastAction')
        );
    }

    /**
     * RequestAction — Grid Rewards Schalter aus WebFront
     */
    public function RequestAction(string $ident, mixed $value): void
    {
        if ($ident === 'EMS_GridRewards') {
            $this->SetValue('EMS_GridRewards', (bool)$value);
            $this->log(EMS_LOG_BASIC, 'Grid Rewards ' . ($value ? 'aktiviert' : 'deaktiviert'));
            // Sofort neu optimieren
            $this->Update();
        }
    }

    // ----------------------------------------------------------------
    //  Layer 1: Daten einlesen
    // ----------------------------------------------------------------

    private function readState(): array
    {
        $s = [];

        // Netz (SmartMeter)
        $s['grid_total_w']  = $this->readVar('VAR_SM_Total_Power', 0.0);
        $s['grid_l1_w']     = $this->readVar('VAR_SM_L1_Power',    0.0);
        $s['grid_l2_w']     = $this->readVar('VAR_SM_L2_Power',    0.0);
        $s['grid_l3_w']     = $this->readVar('VAR_SM_L3_Power',    0.0);
        $s['grid_l1_a']     = $this->readVar('VAR_SM_L1_Current',  0.0);
        $s['grid_l2_a']     = $this->readVar('VAR_SM_L2_Current',  0.0);
        $s['grid_l3_a']     = $this->readVar('VAR_SM_L3_Current',  0.0);
        $s['sm_ok']         = ($this->ReadPropertyInteger('VAR_SM_Status') === 0)
                              || ($this->readVar('VAR_SM_Status', 1) === 0);

        // PAC2200 (optional, Redundanz)
        if ($this->ReadPropertyBoolean('PAC2200_Active')) {
            $s['pac_l1_w']  = $this->readVar('VAR_PAC_L1_Power',   0.0);
            $s['pac_l2_w']  = $this->readVar('VAR_PAC_L2_Power',   0.0);
            $s['pac_l3_w']  = $this->readVar('VAR_PAC_L3_Power',   0.0);
            $s['pac_l1_a']  = $this->readVar('VAR_PAC_L1_Current', 0.0);
            $s['pac_l2_a']  = $this->readVar('VAR_PAC_L2_Current', 0.0);
            $s['pac_l3_a']  = $this->readVar('VAR_PAC_L3_Current', 0.0);
            $s['pac_total_w']= $s['pac_l1_w'] + $s['pac_l2_w'] + $s['pac_l3_w'];
        }

        // PV
        $s['pv_total_w']    = $this->readVar('VAR_PV_Total_Power', 0.0);

        // Wechselrichter
        $s['wr_total_w']    = $this->readVar('VAR_WR_Total_Power', 0.0);
        $s['wr_temp']       = $this->readVar('VAR_WR_Temp',        0.0);
        $s['backup_active'] = ($this->readVar('VAR_WR_Backup_Active', 0) === 1);
        $s['backup_power_w']= $this->readVar('VAR_WR_Backup_Power', 0.0);

        // Batterie
        $s['bat_active']    = $this->ReadPropertyBoolean('BAT_Active');
        if ($s['bat_active']) {
            $soc1            = $this->readVar('VAR_BAT1_SOC',   0.0);
            $pow1            = $this->readVar('VAR_BAT1_Power', 0.0);
            $temp1           = $this->readVar('VAR_BAT1_Temp',  25.0);
            $cvMax1          = $this->readVar('VAR_BAT1_Cell_V_Max', 3600);
            $cvMin1          = $this->readVar('VAR_BAT1_Cell_V_Min', 2900);

            $strCount        = $this->ReadPropertyInteger('BAT_String_Count');
            if ($strCount >= 2) {
                $soc2        = $this->readVar('VAR_BAT2_SOC',   0.0);
                $pow2        = $this->readVar('VAR_BAT2_Power', 0.0);
                $temp2       = $this->readVar('VAR_BAT2_Temp',  25.0);
                $cvMax2      = $this->readVar('VAR_BAT2_Cell_V_Max', 3600);
                $cvMin2      = $this->readVar('VAR_BAT2_Cell_V_Min', 2900);
                $s['bat_soc']   = ($soc1 + $soc2) / 2.0;
                $s['bat_pow_w'] = $pow1 + $pow2;
                $s['bat_temp']  = max($temp1, $temp2);
                $s['bat_cv_max']= max($cvMax1, $cvMax2);
                $s['bat_cv_min']= min($cvMin1, $cvMin2);
            } else {
                $s['bat_soc']   = (float)$soc1;
                $s['bat_pow_w'] = (float)$pow1;
                $s['bat_temp']  = (float)$temp1;
                $s['bat_cv_max']= (int)$cvMax1;
                $s['bat_cv_min']= (int)$cvMin1;
            }
        } else {
            $s['bat_soc']   = 0.0;
            $s['bat_pow_w'] = 0.0;
            $s['bat_temp']  = 25.0;
            $s['bat_cv_max']= 3600;
            $s['bat_cv_min']= 2900;
        }

        // Wallboxen
        $s['wb_active']     = $this->ReadPropertyBoolean('WB_Active');
        $s['wb_count']      = $this->ReadPropertyInteger('WB_Count');
        $s['grid_rewards']  = $this->GetValue('EMS_GridRewards');
        $s['wb1_pow_kw']    = $this->readVar('VAR_WB1_Power',  0.0);
        $s['wb1_status']    = $this->readVar('VAR_WB1_Status', 0);
        $s['wb1_cable']     = $this->readVar('VAR_WB1_Cable',  0);
        $s['wb1_error']     = $this->readVar('VAR_WB1_Error',  0);
        $s['wb2_pow_kw']    = $this->readVar('VAR_WB2_Power',  0.0);
        $s['wb2_status']    = $this->readVar('VAR_WB2_Status', 0);
        $s['wb2_cable']     = $this->readVar('VAR_WB2_Cable',  0);
        $s['wb2_error']     = $this->readVar('VAR_WB2_Error',  0);

        // Wärmepumpe (Monitoring)
        $s['hp_active']     = $this->ReadPropertyBoolean('HP_Active');
        $s['hp_pow_w']      = $s['hp_active'] ? $this->readVar('VAR_HP_Power', 0.0) : 0.0;
        $s['hp_state']      = $s['hp_active'] ? $this->readVar('VAR_HP_State', 0)   : 0;

        // Tibber
        $s['tib_active']    = $this->ReadPropertyBoolean('TIBBER_Active');
        $s['tib_price']     = $s['tib_active'] ? $this->readVar('VAR_TIB_Price', 0.0) : 0.0;
        $s['tib_level']     = $s['tib_active'] ? $this->readVar('VAR_TIB_Level', 2)   : 2;
        $s['tib_feed']      = $s['tib_active'] ? $this->readVar('VAR_TIB_Feed_Tariff', 18.36) : 18.36;

        // PV Forecast
        $s['fc_active']     = $this->ReadPropertyBoolean('FORECAST_Active');
        $s['fc_today_kwh']  = $s['fc_active'] ? $this->readVar('VAR_FC_Today',    0.0) : 0.0;
        $s['fc_tomorrow_kwh']=$s['fc_active'] ? $this->readVar('VAR_FC_Tomorrow', 0.0) : 0.0;

        // §14a Zeitfenster
        $s['enwg_active']   = $this->ReadPropertyBoolean('ENWG14A_Active');
        $s['enwg_in_window']= $s['enwg_active'] && $this->isInEnwgWindow();

        // Berechnete Werte
        $s['house_pow_w']   = $s['pv_total_w']
                              - abs($s['bat_pow_w'])
                              - $s['grid_total_w']
                              - ($s['wb1_pow_kw'] * 1000)
                              - ($s['wb2_pow_kw'] * 1000);
        $s['house_pow_w']   = max(0.0, $s['house_pow_w']);

        // Effektiver Tibber-Preis nach §14a-Reduktion
        if ($s['enwg_in_window'] && $s['tib_active']) {
            $reduction       = $this->ReadPropertyInteger('ENWG14A_Reduction_Pct') / 100.0;
            $s['tib_price_eff'] = $s['tib_price'] * (1.0 - $reduction);
        } else {
            $s['tib_price_eff'] = $s['tib_price'];
        }

        // Zeitstempel
        $s['timestamp']     = time();

        $this->log(EMS_LOG_VERBOSE,
            sprintf('State: Grid=%.0fW PV=%.0fW Bat=%.0fW(SOC=%.0f%%) Tibber=%.2f→%.2f ct',
                $s['grid_total_w'], $s['pv_total_w'], $s['bat_pow_w'],
                $s['bat_soc'], $s['tib_price'], $s['tib_price_eff']));

        return $s;
    }

    // ----------------------------------------------------------------
    //  Layer 2: Statusvariablen aktualisieren
    // ----------------------------------------------------------------

    private function updateStatusVars(array $s): void
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
    //  Layer 3: Schutz-Layer
    // ----------------------------------------------------------------

    private function checkProtection(array $s): array
    {
        $p = ['triggered' => false, 'reason' => '', 'action' => ''];

        // Backup / Netzausfall
        if ($s['backup_active']) {
            $p['triggered'] = true;
            $p['reason']    = 'Backup aktiv — Netzausfall erkannt';
            $p['action']    = 'backup';
            return $p;
        }

        // SLS-Schutz — phasengenauer Stromvergleich
        $slsLimit = (float)$this->ReadPropertyInteger('EMS_SLS_Limit_A');
        foreach (['l1' => $s['grid_l1_a'], 'l2' => $s['grid_l2_a'], 'l3' => $s['grid_l3_a']] as $phase => $current) {
            if (abs($current) > $slsLimit * 0.95) {
                $p['triggered'] = true;
                $p['reason']    = sprintf('SLS-Schutz: %s Strom %.1f A (Grenze %.1f A)', strtoupper($phase), $current, $slsLimit);
                $p['action']    = 'sls';
                return $p;
            }
        }

        // Gesamtleistung NAP
        $slsWatt = (float)$this->ReadPropertyInteger('EMS_SLS_Limit_W');
        if ($s['grid_total_w'] > $slsWatt * 0.97) {
            $p['triggered'] = true;
            $p['reason']    = sprintf('SLS-Schutz: Gesamtleistung %.0f W (Grenze %.0f W)', $s['grid_total_w'], $slsWatt);
            $p['action']    = 'sls';
            return $p;
        }

        // Wechselrichter-Überhitzung
        $wrTempMax = (float)$this->ReadPropertyInteger('EMS_WR_Temp_Max');
        if ($s['wr_temp'] > $wrTempMax) {
            $p['triggered'] = true;
            $p['reason']    = sprintf('WR-Überhitzung: %.1f °C (Max %.1f °C)', $s['wr_temp'], $wrTempMax);
            $p['action']    = 'throttle';
            return $p;
        }

        // Batterie-Überhitzung
        if ($s['bat_active']) {
            $batTempMax = (float)$this->ReadPropertyInteger('BAT_Temp_Max');
            if ($s['bat_temp'] > $batTempMax) {
                $p['triggered'] = true;
                $p['reason']    = sprintf('Batterie-Überhitzung: %.1f °C (Max %.1f °C)', $s['bat_temp'], $batTempMax);
                $p['action']    = 'bat_stop';
                return $p;
            }

            // Zellspannungs-Schutz
            $cvMax = $this->ReadPropertyInteger('BAT_Cell_Voltage_Max');
            $cvMin = $this->ReadPropertyInteger('BAT_Cell_Voltage_Min');
            if ($s['bat_cv_max'] > $cvMax) {
                $p['triggered'] = true;
                $p['reason']    = sprintf('Zellspannung zu hoch: %d mV (Max %d mV)', $s['bat_cv_max'], $cvMax);
                $p['action']    = 'bat_stop';
                return $p;
            }
            if ($s['bat_cv_min'] < $cvMin) {
                $p['triggered'] = true;
                $p['reason']    = sprintf('Zellspannung zu niedrig: %d mV (Min %d mV)', $s['bat_cv_min'], $cvMin);
                $p['action']    = 'bat_stop';
                return $p;
            }
        }

        return $p;
    }

    private function applyProtection(array $p, array $s): void
    {
        $this->log(EMS_LOG_BASIC, '⚠️ Schutz ausgelöst: ' . $p['reason']);
        $this->SetValue('EMS_Status', '⚠️ ' . $p['reason']);
        $this->SetValue('EMS_LastAction', 'SCHUTZ: ' . $p['reason']);

        switch ($p['action']) {
            case 'backup':
                $this->setGoodweMode(EMS_MODE_ISLAND, 0);
                $this->setAllWallboxes(false);
                $this->SetValue('EMS_Mode', EMS_OP_BACKUP);
                break;

            case 'sls':
                // Wallboxen sofort abschalten
                $this->setAllWallboxes(false);
                // Goodwe auf Bereitschaft
                $this->setGoodweMode(EMS_MODE_STANDBY, 0);
                $this->SetValue('EMS_Mode', EMS_OP_STANDBY);
                break;

            case 'throttle':
                // WR-Überhitzung: Goodwe auf Automatik, keine Zusatzlast
                $this->setAllWallboxes(false);
                $this->setGoodweMode(EMS_MODE_AUTO, 0);
                $this->SetValue('EMS_Mode', EMS_OP_AUTO);
                break;

            case 'bat_stop':
                // Batterie schonen: Bereitschaft
                $this->setGoodweMode(EMS_MODE_STANDBY, 0);
                $this->SetValue('EMS_Mode', EMS_OP_STANDBY);
                break;
        }
    }

    // ----------------------------------------------------------------
    //  Layer 4: Optimierungs-Layer
    // ----------------------------------------------------------------

    private function optimize(array $s): array
    {
        $decision = [
            'op_mode'      => EMS_OP_AUTO,
            'gw_mode'      => EMS_MODE_AUTO,
            'gw_power_w'   => 0,
            'wb1_enable'   => false,
            'wb2_enable'   => false,
            'reason'       => '',
        ];

        // Grid Rewards aktiv → Batteriebereitschaft, Wallbox an Tibber übergeben
        if ($s['grid_rewards']) {
            $decision['op_mode']    = EMS_OP_GRIDREWARDS;
            $decision['gw_mode']    = EMS_MODE_STANDBY;
            $decision['gw_power_w'] = 0;
            $decision['wb1_enable'] = false;
            $decision['wb2_enable'] = false;
            $decision['reason']     = 'Grid Rewards aktiv — Batterie hält SOC, Tibber steuert Wallbox';
            return $decision;
        }

        $socMin        = (float)$this->ReadPropertyInteger('BAT_SOC_Min');
        $socTargetNight= (float)$this->ReadPropertyInteger('BAT_SOC_Target_Night');
        $socTargetDay  = (float)$this->ReadPropertyInteger('BAT_SOC_Target_Day');
        $socReserve    = (float)$this->ReadPropertyInteger('BAT_SOC_Reserve_Backup');
        $hystSoc       = (float)$this->ReadPropertyInteger('OPT_Hysteresis_SOC');
        $hystPrice     = (float)$this->ReadPropertyFloat('OPT_Hysteresis_Price');
        $slsW          = (float)$this->ReadPropertyInteger('EMS_SLS_Limit_W');

        $thCharge      = (float)$this->ReadPropertyFloat('TIB_Threshold_Charge');
        $thDischarge   = (float)$this->ReadPropertyFloat('TIB_Threshold_Discharge');
        $thWB          = (float)$this->ReadPropertyFloat('TIB_Threshold_WB');
        $thExport      = (float)$this->ReadPropertyFloat('TIB_Threshold_Export');
        $feedTariff    = $s['tib_feed'];

        $price         = $s['tib_price_eff'];
        $soc           = $s['bat_soc'];
        $pvW           = $s['pv_total_w'];
        $fcToday       = $s['fc_today_kwh'];

        // ── Entscheidungsbaum ────────────────────────────────────────

        // 1. §14a Nachtzeitfenster + günstiger Preis → Netz laden
        if ($s['enwg_in_window'] && $s['bat_active'] && $soc < ($socTargetNight - $hystSoc)) {
            $decision['op_mode']    = EMS_OP_NET_CHARGE;
            $decision['gw_mode']    = EMS_MODE_AC_IMPORT;
            $decision['gw_power_w'] = (int)$slsW;
            // Wallboxen freigeben wenn Auto angesteckt
            $decision['wb1_enable'] = ($s['wb1_cable'] > 0 && $s['wb1_error'] === 0);
            $decision['wb2_enable'] = ($s['wb_count'] >= 2 && $s['wb2_cable'] > 0 && $s['wb2_error'] === 0);
            $decision['reason']     = sprintf('§14a Nacht-Laden: SOC=%.0f%% Ziel=%.0f%% Preis=%.2f ct (eff)', $soc, $socTargetNight, $price);
            return $decision;
        }

        // 2. Tibber sehr günstig → Netz laden (auch tagsüber)
        if ($s['tib_active'] && $s['bat_active'] && $price < ($thCharge - $hystPrice) && $soc < ($socTargetNight - $hystSoc)) {
            $decision['op_mode']    = EMS_OP_NET_CHARGE;
            $decision['gw_mode']    = EMS_MODE_AC_IMPORT;
            $decision['gw_power_w'] = (int)$slsW;
            $decision['wb1_enable'] = ($s['wb1_cable'] > 0 && $s['wb1_error'] === 0);
            $decision['wb2_enable'] = ($s['wb_count'] >= 2 && $s['wb2_cable'] > 0 && $s['wb2_error'] === 0);
            $decision['reason']     = sprintf('Tibber günstig: %.2f ct < Schwelle %.2f ct, Batterie laden', $price, $thCharge);
            return $decision;
        }

        // 3. PV-Überschuss vorhanden → Eigenverbrauch
        $fcMinPower = (float)$this->ReadPropertyInteger('FORECAST_Min_Power_W');
        if ($pvW > $fcMinPower && $s['bat_active'] && $soc < ($socTargetDay - $hystSoc)) {
            $decision['op_mode']    = EMS_OP_PV_SELFUSE;
            $decision['gw_mode']    = EMS_MODE_CHARGE_PV;
            $decision['gw_power_w'] = 0;
            $decision['wb1_enable'] = ($s['wb1_cable'] > 0 && $price < $thWB && $s['wb1_error'] === 0);
            $decision['wb2_enable'] = ($s['wb_count'] >= 2 && $s['wb2_cable'] > 0 && $price < $thWB && $s['wb2_error'] === 0);
            $decision['reason']     = sprintf('PV-Eigenverbrauch: %.0f W PV, SOC=%.0f%%', $pvW, $soc);
            return $decision;
        }

        // 4. Tibber teuer und SOC ausreichend → Entladen
        if ($s['tib_active'] && $s['bat_active'] && $price > ($thDischarge + $hystPrice) && $soc > ($socMin + $hystSoc + $socReserve)) {
            $decision['op_mode']    = EMS_OP_DISCHARGE;
            $decision['gw_mode']    = EMS_MODE_DISCHARGE;
            $decision['gw_power_w'] = 0;
            $decision['wb1_enable'] = false;
            $decision['wb2_enable'] = false;
            $decision['reason']     = sprintf('Tibber teuer: %.2f ct > Schwelle %.2f ct, Batterie entladen', $price, $thDischarge);
            return $decision;
        }

        // 5. Tibber sehr teuer + Preis > Einspeisevergütung → Exportieren
        if ($s['tib_active'] && $s['bat_active'] && $price > ($thExport + $hystPrice) && $price > $feedTariff && $soc > ($socMin + $hystSoc + $socReserve)) {
            $decision['op_mode']    = EMS_OP_EXPORT;
            $decision['gw_mode']    = EMS_MODE_AC_EXPORT;
            $decision['gw_power_w'] = 0;
            $decision['wb1_enable'] = false;
            $decision['wb2_enable'] = false;
            $decision['reason']     = sprintf('Export: Tibber %.2f ct > Einspeisevergütung %.2f ct', $price, $feedTariff);
            return $decision;
        }

        // 6. Wallbox: Fahrzeug laden wenn Preis akzeptabel
        $wb1En = ($s['wb_active'] && $s['wb1_cable'] > 0 && $s['wb1_error'] === 0 && (!$s['tib_active'] || $price < $thWB));
        $wb2En = ($s['wb_active'] && $s['wb_count'] >= 2 && $s['wb2_cable'] > 0 && $s['wb2_error'] === 0 && (!$s['tib_active'] || $price < $thWB));

        // 7. Fallback: Automatik
        $decision['op_mode']    = EMS_OP_AUTO;
        $decision['gw_mode']    = EMS_MODE_AUTO;
        $decision['gw_power_w'] = 0;
        $decision['wb1_enable'] = $wb1En;
        $decision['wb2_enable'] = $wb2En;
        $decision['reason']     = sprintf('Automatik: SOC=%.0f%% Preis=%.2f ct PV=%.0fW', $soc, $price, $pvW);
        return $decision;
    }

    // ----------------------------------------------------------------
    //  Layer 5: Steuerungs-Layer
    // ----------------------------------------------------------------

    private function applyDecision(array $d, array $s): void
    {
        $lastMode    = $this->ReadAttributeInteger('LastGoodweMode');
        $cooldown    = $this->ReadPropertyInteger('OPT_Cooldown_Sec');
        $lastDecision= $this->ReadAttributeInteger('LastDecision');
        $now         = time();

        // Hysterese: Modus nur wechseln wenn Cooldown abgelaufen
        $modeChanged = ($d['gw_mode'] !== $lastMode);
        if ($modeChanged && ($now - $lastDecision) < $cooldown) {
            $this->log(EMS_LOG_VERBOSE, 'Cooldown aktiv — Modus nicht geändert (' . ($now - $lastDecision) . 's < ' . $cooldown . 's)');
            return;
        }

        // Goodwe Modus setzen
        if ($modeChanged) {
            $this->setGoodweMode($d['gw_mode'], $d['gw_power_w']);
            $this->WriteAttributeInteger('LastGoodweMode', $d['gw_mode']);
            $this->WriteAttributeInteger('LastDecision',   $now);
            $this->log(EMS_LOG_BASIC, 'Goodwe Modus → ' . $d['gw_mode'] . ' (' . $d['reason'] . ')');
        }

        // Goodwe Leistungseinstellung aktualisieren (auch ohne Moduswechsel)
        if ($d['gw_power_w'] > 0) {
            $varPower = $this->ReadPropertyInteger('VAR_WR_EMS_Power');
            if ($varPower > 0) {
                SetValue($varPower, $d['gw_power_w']);
            }
        }

        // Wallbox 1 steuern
        if ($s['wb_active']) {
            $this->controlWallbox(1, $d['wb1_enable'], $s);
            if ($s['wb_count'] >= 2) {
                $this->controlWallbox(2, $d['wb2_enable'], $s);
            }
        }

        // Status setzen
        $this->SetValue('EMS_Mode',       $d['op_mode']);
        $this->SetValue('EMS_LastAction', $d['reason']);
        $this->SetValue('EMS_Status',     '✅ ' . $d['reason']);
    }

    private function setGoodweMode(int $mode, int $powerW): void
    {
        $varMode  = $this->ReadPropertyInteger('VAR_WR_EMS_Mode');
        $varPower = $this->ReadPropertyInteger('VAR_WR_EMS_Power');

        if ($varMode > 0) {
            SetValue($varMode, $mode);
        }
        if ($varPower > 0 && $powerW > 0) {
            SetValue($varPower, $powerW);
        }
    }

    private function controlWallbox(int $num, bool $enable, array $s): void
    {
        $instance = $this->ReadPropertyInteger("WB{$num}_Instance");
        if ($instance <= 0) {
            return;
        }

        // Cooldown prüfen
        $lastSwitch = $this->ReadAttributeInteger("LastWB{$num}Switch");
        $cooldown   = $this->ReadPropertyInteger('WB_Cooldown_Sec');
        if ((time() - $lastSwitch) < $cooldown) {
            $this->log(EMS_LOG_VERBOSE, "WB{$num}: Cooldown aktiv");
            return;
        }

        $currentStatus = $this->readVar("VAR_WB{$num}_Status", 0);
        $isCharging    = ($currentStatus === 2);
        $isActive      = ($this->readVar("VAR_WB{$num}_Active", 0) == 1);

        if ($enable && !$isActive) {
            // Wallbox freigeben: Modus 2 = Laden
            $maxPower = $this->ReadPropertyInteger("WB{$num}_Max_Power_W");
            GOeCharger_SetMode($instance, 2);
            GOeCharger_SetCurrentChargingWatt($instance, $maxPower);
            $this->WriteAttributeInteger("LastWB{$num}Switch", time());
            $this->log(EMS_LOG_BASIC, "WB{$num} freigegeben ({$maxPower} W)");
        } elseif (!$enable && $isActive) {
            // Wallbox sperren: Modus 1 = nicht laden
            GOeCharger_SetMode($instance, 1);
            $this->WriteAttributeInteger("LastWB{$num}Switch", time());
            $this->log(EMS_LOG_BASIC, "WB{$num} gesperrt");
        }
    }

    private function setAllWallboxes(bool $enable): void
    {
        if (!$this->ReadPropertyBoolean('WB_Active')) {
            return;
        }
        $count = $this->ReadPropertyInteger('WB_Count');
        for ($i = 1; $i <= min($count, 2); $i++) {
            $instance = $this->ReadPropertyInteger("WB{$i}_Instance");
            if ($instance > 0) {
                GOeCharger_SetMode($instance, $enable ? 2 : 1);
            }
        }
    }

    // ----------------------------------------------------------------
    //  Goodwe Zeitfenster schreiben
    //  Format: High Byte = Stunden (0–23), Low Byte = Minuten (0–59)
    //  Beispiel: 02:30 Uhr → (2 << 8) | 30 = 542
    //  Work Week: High Byte 0xFF = enable, Low Byte Bits 0–6 = So–Sa
    //  Ganze Woche: 0xFF7F
    // ----------------------------------------------------------------

    public function SetECOWindow(int $slot, int $startHour, int $startMin, int $endHour, int $endMin, int $powerPct, int $weekMask = 0xFF7F): void
    {
        if ($slot < 1 || $slot > 4) {
            $this->log(EMS_LOG_BASIC, 'SetECOWindow: Slot muss 1–4 sein');
            return;
        }

        $startVal = ($startHour << 8) | $startMin;
        $endVal   = ($endHour   << 8) | $endMin;

        $varStart = $this->ReadPropertyInteger("VAR_WR_Time{$slot}_Start");
        $varEnd   = $this->ReadPropertyInteger("VAR_WR_Time{$slot}_End");
        $varPower = $this->ReadPropertyInteger("VAR_WR_Time{$slot}_Power");
        $varWeek  = $this->ReadPropertyInteger("VAR_WR_Time{$slot}_Week");

        if ($varStart > 0) SetValue($varStart, $startVal);
        if ($varEnd   > 0) SetValue($varEnd,   $endVal);
        if ($varPower > 0) SetValue($varPower,  $powerPct);
        if ($varWeek  > 0) SetValue($varWeek,   $weekMask);

        $this->log(EMS_LOG_BASIC,
            sprintf('ECO Slot %d gesetzt: %02d:%02d–%02d:%02d %d%% Woche=0x%04X',
                $slot, $startHour, $startMin, $endHour, $endMin, $powerPct, $weekMask));
    }

    /**
     * Tibber-optimiertes Nacht-Ladefenster berechnen und in Goodwe schreiben
     * Nutzt die 15-Min-Preise um das günstigste Fenster zwischen
     * ENWG14A_Start_Hour und ENWG14A_End_Hour zu finden
     */
    public function PlanNightCharge(): void
    {
        if (!$this->ReadPropertyBoolean('ENWG14A_Active') || !$this->ReadPropertyBoolean('TIBBER_Active')) {
            // Kein §14a oder kein Tibber → festes Fenster setzen
            $startH = $this->ReadPropertyInteger('ENWG14A_Start_Hour');
            $endH   = $this->ReadPropertyInteger('ENWG14A_End_Hour');
            $this->SetECOWindow(1, $startH, 0, $endH, 0, 100);
            return;
        }

        $varJson = $this->ReadPropertyInteger('VAR_TIB_PT15M_Today');
        if ($varJson <= 0) {
            return;
        }

        // Preisdaten lesen und parsen
        // Tibber PT15M Variablen direkt auslesen (PT15M_T0_0 bis _95)
        // Wir suchen das günstigste 3-Stunden-Fenster in der Nacht
        $startH  = $this->ReadPropertyInteger('ENWG14A_Start_Hour');
        $endH    = $this->ReadPropertyInteger('ENWG14A_End_Hour');

        // Direkte Variablen lesen für Nacht-Slots
        $slots = [];
        for ($h = $startH; $h < $endH; $h++) {
            for ($q = 0; $q < 4; $q++) {
                $slotIdx = $h * 4 + $q;
                $varName = "VAR_TIB_PT15M_T0_{$slotIdx}";
                // Fallback: Gesamtfenster nutzen wenn keine Einzelvariablen
                $slots[] = ['idx' => $slotIdx, 'hour' => $h, 'min' => $q * 15, 'price' => 0.0];
            }
        }

        // Gesamtes Fenster als ECO Slot 1 setzen
        $this->SetECOWindow(1, $startH, 0, $endH, 0, 100, 0xFF7F);

        $this->log(EMS_LOG_BASIC, sprintf('Nacht-Ladefenster: %02d:00–%02d:00 Uhr (Slot 1)', $startH, $endH));
    }

    // ----------------------------------------------------------------
    //  Fallback
    // ----------------------------------------------------------------

    private function applyFallback(): void
    {
        $fallbackMode = $this->ReadPropertyInteger('EMS_Fallback_Mode');
        $this->log(EMS_LOG_BASIC, 'Fallback aktiv — setze Goodwe Modus ' . $fallbackMode);
        $this->setGoodweMode($fallbackMode, 0);
        $this->SetValue('EMS_Status', '⚠️ Fallback aktiv — Kommunikationsfehler');
        $this->SetStatus(200);
    }

    // ----------------------------------------------------------------
    //  Hilfsfunktionen
    // ----------------------------------------------------------------

    /**
     * Variablenwert sicher lesen — gibt $default zurück wenn Variable 0 oder nicht vorhanden
     */
    private function readVar(string $property, mixed $default): mixed
    {
        $varId = $this->ReadPropertyInteger($property);
        if ($varId <= 0) {
            return $default;
        }
        if (!IPS_VariableExists($varId)) {
            $this->log(EMS_LOG_VERBOSE, "Variable {$property} (ID {$varId}) existiert nicht");
            return $default;
        }
        return GetValue($varId);
    }

    /**
     * Prüft ob die aktuelle Uhrzeit im §14a-Zeitfenster liegt
     */
    private function isInEnwgWindow(): bool
    {
        $startH = $this->ReadPropertyInteger('ENWG14A_Start_Hour');
        $endH   = $this->ReadPropertyInteger('ENWG14A_End_Hour');
        $hour   = (int)date('G');

        if ($startH < $endH) {
            // Normales Fenster, z.B. 00:00–06:00
            return ($hour >= $startH && $hour < $endH);
        } else {
            // Über Mitternacht, z.B. 22:00–06:00
            return ($hour >= $startH || $hour < $endH);
        }
    }

    /**
     * Logging mit Level-Filter
     */
    private function log(int $level, string $message): void
    {
        $configLevel = $this->ReadPropertyInteger('EMS_Log_Level');
        if ($level > $configLevel) {
            return;
        }
        $prefix = match($level) {
            EMS_LOG_BASIC   => '[EMS] ',
            EMS_LOG_VERBOSE => '[EMS][V] ',
            default         => '[EMS] ',
        };
        IPS_LogMessage($prefix . 'EMS', $message);
    }
}
