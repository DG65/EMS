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
        $this->RegisterPropertyInteger('EMS_SLS_Limit_A',      50);
        $this->RegisterPropertyInteger('EMS_HAK_Limit_A',      63);
        $this->RegisterPropertyInteger('EMS_SLS_Limit_W',      34641);
        $this->RegisterPropertyInteger('EMS_Fallback_Mode',    GW_MODE_AUTO);
        $this->RegisterPropertyInteger('EMS_Fallback_Timeout', 60);
        $this->RegisterPropertyInteger('EMS_Log_Level',        EMS_LOG_BASIC);

        // ── Netzmesspunkte ──────────────────────────────────────────
        $this->RegisterPropertyInteger('VAR_SM_L1_Power',      0);
        $this->RegisterPropertyInteger('VAR_SM_L2_Power',      0);
        $this->RegisterPropertyInteger('VAR_SM_L3_Power',      0);
        $this->RegisterPropertyInteger('VAR_SM_Total_Power',   0);
        $this->RegisterPropertyInteger('VAR_SM_L1_Current',    0);
        $this->RegisterPropertyInteger('VAR_SM_L2_Current',    0);
        $this->RegisterPropertyInteger('VAR_SM_L3_Current',    0);
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
        for ($i = 1; $i <= 4; $i++) {
            $this->RegisterPropertyInteger('VAR_WR_Time' . $i . '_Start', 0);
            $this->RegisterPropertyInteger('VAR_WR_Time' . $i . '_End',   0);
            $this->RegisterPropertyInteger('VAR_WR_Time' . $i . '_Power', 0);
            $this->RegisterPropertyInteger('VAR_WR_Time' . $i . '_Week',  0);
        }
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
        $this->RegisterPropertyInteger('EMS_WR_Temp_Max',      75);

        // ── Batteriespeicher ────────────────────────────────────────
        $this->RegisterPropertyBoolean('BAT_Active',               false);
        $this->RegisterPropertyInteger('BAT_String_Count',         1);
        $this->RegisterPropertyFloat(  'BAT_Capacity_kWh',         10.0);
        $this->RegisterPropertyInteger('BAT_SOC_Min',              10);
        $this->RegisterPropertyInteger('BAT_SOC_Target_Night',     100);
        $this->RegisterPropertyInteger('BAT_SOC_Target_Day',       80);
        $this->RegisterPropertyInteger('BAT_SOC_Reserve_Backup',   10);
        $this->RegisterPropertyInteger('BAT_Temp_Max',             45);
        $this->RegisterPropertyInteger('BAT_Cell_Voltage_Max',     3600);
        $this->RegisterPropertyInteger('BAT_Cell_Voltage_Min',     2900);
        for ($i = 1; $i <= 2; $i++) {
            $this->RegisterPropertyInteger('VAR_BAT' . $i . '_SOC',            0);
            $this->RegisterPropertyInteger('VAR_BAT' . $i . '_Power',          0);
            $this->RegisterPropertyInteger('VAR_BAT' . $i . '_Mode',           0);
            $this->RegisterPropertyInteger('VAR_BAT' . $i . '_SOH',            0);
            $this->RegisterPropertyInteger('VAR_BAT' . $i . '_Temp',           0);
            $this->RegisterPropertyInteger('VAR_BAT' . $i . '_Cell_V_Max',     0);
            $this->RegisterPropertyInteger('VAR_BAT' . $i . '_Cell_V_Min',     0);
            $this->RegisterPropertyInteger('VAR_BAT' . $i . '_Min_SOC_Online', 0);
            $this->RegisterPropertyInteger('VAR_BAT' . $i . '_Min_SOC_Offline',0);
        }

        // ── Wallboxen ───────────────────────────────────────────────
        $this->RegisterPropertyBoolean('WB_Active',            false);
        $this->RegisterPropertyInteger('WB_Count',             1);
        $this->RegisterPropertyBoolean('WB_GridRewards_Active',false);
        $this->RegisterPropertyInteger('WB_Cooldown_Sec',      120);
        $this->RegisterPropertyInteger('WB_Min_Charge_Min',    5);
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
        $this->RegisterPropertyFloat(  'TIB_Threshold_Charge',     15.0);
        $this->RegisterPropertyFloat(  'TIB_Threshold_Discharge',  25.0);
        $this->RegisterPropertyFloat(  'TIB_Threshold_WB',         20.0);
        $this->RegisterPropertyFloat(  'TIB_Threshold_Export',     20.0);

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
        $this->RegisterPropertyFloat(  'OPT_Hysteresis_Price',     1.0);
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
        $this->RegisterVariableFloat(  'EMS_TibberPrice',  'Tibber Preis (ct/kWh)',  '',100);
        $this->RegisterVariableBoolean('EMS_GridRewards',  'Grid Rewards aktiv',     '',110);
        $this->RegisterVariableString( 'EMS_LastAction',   'Letzte Aktion',          '',120);
        $this->RegisterVariableString( 'EMS_Status',       'Status',                 '',130);

        $this->EnableAction('EMS_GridRewards');

        // ── Timer ───────────────────────────────────────────────────
        $this->RegisterTimer('EMS_UpdateTimer', 0, 'EMS_Update($_IPS[\'TARGET\']);');

        // ── Interne Attribute ───────────────────────────────────────
        $this->RegisterAttributeInteger('LastGoodweMode',    GW_MODE_AUTO);
        $this->RegisterAttributeInteger('LastWB1Switch',     0);
        $this->RegisterAttributeInteger('LastWB2Switch',     0);
        $this->RegisterAttributeInteger('LastDecision',      0);
        $this->RegisterAttributeInteger('ConsecutiveErrors', 0);
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // SLS-Grenze in Watt automatisch berechnen: A x 400V x sqrt(3)
        $slsA  = $this->ReadPropertyInteger('EMS_SLS_Limit_A');
        $slsW  = (int)round($slsA * 400 * 1.7321);
        if ($this->ReadPropertyInteger('EMS_SLS_Limit_W') !== $slsW) {
            IPS_SetProperty($this->InstanceID, 'EMS_SLS_Limit_W', $slsW);
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        $active   = $this->ReadPropertyBoolean('EMS_Active');
        $interval = $this->ReadPropertyInteger('EMS_Interval');

        if ($active) {
            $this->SetTimerInterval('EMS_UpdateTimer', $interval * 1000);
            $this->SetStatus(102);
            $this->emsLog(EMS_LOG_BASIC, 'EMS gestartet, Intervall: ' . $interval . 's');
        } else {
            $this->SetTimerInterval('EMS_UpdateTimer', 0);
            $this->SetStatus(104);
            $this->emsLog(EMS_LOG_BASIC, 'EMS deaktiviert');
        }

        $this->SetValue('EMS_Active_State', $active);
    }

    // ----------------------------------------------------------------
    //  Oeffentliche Funktionen
    // ----------------------------------------------------------------

    public function Update()
    {
        if (!$this->ReadPropertyBoolean('EMS_Active')) {
            return;
        }

        try {
            $state      = $this->readState();
            $this->updateStatusVars($state);
            $protection = $this->checkProtection($state);

            if ($protection['triggered']) {
                $this->applyProtection($protection);
                $this->WriteAttributeInteger('ConsecutiveErrors', 0);
                return;
            }

            $decision = $this->optimize($state);
            $this->applyDecision($decision, $state);
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
     * Goodwe ECO-Zeitfenster setzen
     * Zeitformat: High Byte = Stunden (0-23), Low Byte = Minuten (0-59)
     * Beispiel: 02:30 -> (2 << 8) | 30 = 542
     * Work Week: High Byte 0xFF = enable, Low Byte Bits 0-6 = So-Sa
     * Ganze Woche aktiv: 0xFF7F = 65407
     */
    public function SetECOWindow($slot, $startHour, $startMin, $endHour, $endMin, $powerPct, $weekMask = 65407)
    {
        if ($slot < 1 || $slot > 4) {
            $this->emsLog(EMS_LOG_BASIC, 'SetECOWindow: Slot muss 1-4 sein');
            return;
        }
        $startVal = ($startHour << 8) | $startMin;
        $endVal   = ($endHour   << 8) | $endMin;
        $varStart = $this->ReadPropertyInteger('VAR_WR_Time' . $slot . '_Start');
        $varEnd   = $this->ReadPropertyInteger('VAR_WR_Time' . $slot . '_End');
        $varPower = $this->ReadPropertyInteger('VAR_WR_Time' . $slot . '_Power');
        $varWeek  = $this->ReadPropertyInteger('VAR_WR_Time' . $slot . '_Week');
        if ($varStart > 0) { $this->writeVar($varStart, $startVal); }
        if ($varEnd   > 0) { $this->writeVar($varEnd,   $endVal);   }
        if ($varPower > 0) { $this->writeVar($varPower,  $powerPct); }
        if ($varWeek  > 0) { $this->writeVar($varWeek,   $weekMask); }
        $this->emsLog(EMS_LOG_BASIC, sprintf(
            'ECO Slot %d: %02d:%02d-%02d:%02d %d%% Woche=0x%04X',
            $slot, $startHour, $startMin, $endHour, $endMin, $powerPct, $weekMask
        ));
    }

    public function PlanNightCharge()
    {
        $startH = $this->ReadPropertyInteger('ENWG14A_Start_Hour');
        $endH   = $this->ReadPropertyInteger('ENWG14A_End_Hour');
        $this->SetECOWindow(1, $startH, 0, $endH, 0, 100, 65407);
        $this->emsLog(EMS_LOG_BASIC, sprintf(
            'Nacht-Ladefenster: %02d:00-%02d:00 Uhr (Slot 1)', $startH, $endH
        ));
    }

    // ----------------------------------------------------------------
    //  Layer 1: Daten einlesen
    // ----------------------------------------------------------------

    private function readState()
    {
        $s = array();

        // Netz (SmartMeter)
        $s['grid_total_w']  = (float)$this->readVar('VAR_SM_Total_Power', 0);
        $s['grid_l1_w']     = (float)$this->readVar('VAR_SM_L1_Power',    0);
        $s['grid_l2_w']     = (float)$this->readVar('VAR_SM_L2_Power',    0);
        $s['grid_l3_w']     = (float)$this->readVar('VAR_SM_L3_Power',    0);
        $s['grid_l1_a']     = (float)$this->readVar('VAR_SM_L1_Current',  0);
        $s['grid_l2_a']     = (float)$this->readVar('VAR_SM_L2_Current',  0);
        $s['grid_l3_a']     = (float)$this->readVar('VAR_SM_L3_Current',  0);

        // PAC2200 optional
        if ($this->ReadPropertyBoolean('PAC2200_Active')) {
            $s['pac_l1_a']  = (float)$this->readVar('VAR_PAC_L1_Current', 0);
            $s['pac_l2_a']  = (float)$this->readVar('VAR_PAC_L2_Current', 0);
            $s['pac_l3_a']  = (float)$this->readVar('VAR_PAC_L3_Current', 0);
        } else {
            $s['pac_l1_a']  = 0.0;
            $s['pac_l2_a']  = 0.0;
            $s['pac_l3_a']  = 0.0;
        }

        // PV
        $s['pv_total_w']    = (float)$this->readVar('VAR_PV_Total_Power', 0);

        // Wechselrichter
        $s['wr_total_w']    = (float)$this->readVar('VAR_WR_Total_Power', 0);
        $s['wr_temp']       = (float)$this->readVar('VAR_WR_Temp',        25);

        // Batterie
        $s['bat_active']    = $this->ReadPropertyBoolean('BAT_Active');
        if ($s['bat_active']) {
            $soc1           = (float)$this->readVar('VAR_BAT1_SOC',        0);
            $pow1           = (float)$this->readVar('VAR_BAT1_Power',      0);
            $temp1          = (float)$this->readVar('VAR_BAT1_Temp',      25);
            $cvMax1         = (int)  $this->readVar('VAR_BAT1_Cell_V_Max',3600);
            $cvMin1         = (int)  $this->readVar('VAR_BAT1_Cell_V_Min',2900);
            if ($this->ReadPropertyInteger('BAT_String_Count') >= 2) {
                $soc2       = (float)$this->readVar('VAR_BAT2_SOC',        0);
                $pow2       = (float)$this->readVar('VAR_BAT2_Power',      0);
                $temp2      = (float)$this->readVar('VAR_BAT2_Temp',      25);
                $cvMax2     = (int)  $this->readVar('VAR_BAT2_Cell_V_Max',3600);
                $cvMin2     = (int)  $this->readVar('VAR_BAT2_Cell_V_Min',2900);
                $s['bat_soc']    = ($soc1 + $soc2) / 2.0;
                $s['bat_pow_w']  = $pow1 + $pow2;
                $s['bat_temp']   = max($temp1, $temp2);
                $s['bat_cv_max'] = max($cvMax1, $cvMax2);
                $s['bat_cv_min'] = min($cvMin1, $cvMin2);
            } else {
                $s['bat_soc']    = $soc1;
                $s['bat_pow_w']  = $pow1;
                $s['bat_temp']   = $temp1;
                $s['bat_cv_max'] = $cvMax1;
                $s['bat_cv_min'] = $cvMin1;
            }
        } else {
            $s['bat_soc']    = 0.0;
            $s['bat_pow_w']  = 0.0;
            $s['bat_temp']   = 25.0;
            $s['bat_cv_max'] = 3600;
            $s['bat_cv_min'] = 2900;
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
        $s['tib_feed']      = $s['tib_active'] ? (float)$this->readVar('VAR_TIB_Feed_Tariff', 18.36) : 18.36;

        // PV Forecast
        $s['fc_active']     = $this->ReadPropertyBoolean('FORECAST_Active');
        $s['fc_today_kwh']  = $s['fc_active'] ? (float)$this->readVar('VAR_FC_Today',    0) : 0.0;

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
    //  Layer 3: Schutz-Layer
    // ----------------------------------------------------------------

    private function checkProtection($s)
    {
        $p = array('triggered' => false, 'reason' => '', 'action' => '');

        // SLS-Schutz phasengenau
        $slsLimit  = (float)$this->ReadPropertyInteger('EMS_SLS_Limit_A');
        $threshold = $slsLimit * 0.95;
        $phases    = array('L1' => $s['grid_l1_a'], 'L2' => $s['grid_l2_a'], 'L3' => $s['grid_l3_a']);
        foreach ($phases as $phase => $current) {
            if (abs($current) > $threshold) {
                $p['triggered'] = true;
                $p['reason']    = sprintf('SLS-Schutz: %s Strom %.1f A (Grenze %.1f A)', $phase, $current, $slsLimit);
                $p['action']    = 'sls';
                return $p;
            }
        }

        // Gesamtleistung NAP
        $slsWatt = (float)$this->ReadPropertyInteger('EMS_SLS_Limit_W');
        if ($s['grid_total_w'] > $slsWatt * 0.97) {
            $p['triggered'] = true;
            $p['reason']    = sprintf('SLS-Schutz Gesamt: %.0f W (Grenze %.0f W)', $s['grid_total_w'], $slsWatt);
            $p['action']    = 'sls';
            return $p;
        }

        // WR-Ueberhitzung
        $wrTempMax = (float)$this->ReadPropertyInteger('EMS_WR_Temp_Max');
        if ($s['wr_temp'] > $wrTempMax) {
            $p['triggered'] = true;
            $p['reason']    = sprintf('WR-Ueberhitzung: %.1f C (Max %.1f C)', $s['wr_temp'], $wrTempMax);
            $p['action']    = 'throttle';
            return $p;
        }

        // Batterie-Schutz
        if ($s['bat_active']) {
            $batTempMax = (float)$this->ReadPropertyInteger('BAT_Temp_Max');
            if ($s['bat_temp'] > $batTempMax) {
                $p['triggered'] = true;
                $p['reason']    = sprintf('Batterie-Ueberhitzung: %.1f C (Max %.1f C)', $s['bat_temp'], $batTempMax);
                $p['action']    = 'bat_stop';
                return $p;
            }
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

    private function applyProtection($p)
    {
        $this->emsLog(EMS_LOG_BASIC, 'SCHUTZ: ' . $p['reason']);
        $this->SetValue('EMS_Status',     'SCHUTZ: ' . $p['reason']);
        $this->SetValue('EMS_LastAction', 'SCHUTZ: ' . $p['reason']);

        if ($p['action'] === 'sls') {
            $this->setAllWallboxes(false);
            $this->setGoodweMode(GW_MODE_STANDBY, 0);
            $this->SetValue('EMS_Mode', EMS_OP_STANDBY);
        } elseif ($p['action'] === 'throttle') {
            $this->setAllWallboxes(false);
            $this->setGoodweMode(GW_MODE_AUTO, 0);
            $this->SetValue('EMS_Mode', EMS_OP_AUTO);
        } elseif ($p['action'] === 'bat_stop') {
            $this->setGoodweMode(GW_MODE_STANDBY, 0);
            $this->SetValue('EMS_Mode', EMS_OP_STANDBY);
        }
    }

    // ----------------------------------------------------------------
    //  Layer 4: Optimierungs-Layer
    // ----------------------------------------------------------------

    private function optimize($s)
    {
        $d = array(
            'op_mode'    => EMS_OP_AUTO,
            'gw_mode'    => GW_MODE_AUTO,
            'gw_power_w' => 0,
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

        $socMin         = (float)$this->ReadPropertyInteger('BAT_SOC_Min');
        $socTargetNight = (float)$this->ReadPropertyInteger('BAT_SOC_Target_Night');
        $socTargetDay   = (float)$this->ReadPropertyInteger('BAT_SOC_Target_Day');
        $socReserve     = (float)$this->ReadPropertyInteger('BAT_SOC_Reserve_Backup');
        $hystSoc        = (float)$this->ReadPropertyInteger('OPT_Hysteresis_SOC');
        $hystPrice      = (float)$this->ReadPropertyFloat('OPT_Hysteresis_Price');
        $slsW           = (float)$this->ReadPropertyInteger('EMS_SLS_Limit_W');
        $thCharge       = (float)$this->ReadPropertyFloat('TIB_Threshold_Charge');
        $thDischarge    = (float)$this->ReadPropertyFloat('TIB_Threshold_Discharge');
        $thWB           = (float)$this->ReadPropertyFloat('TIB_Threshold_WB');
        $thExport       = (float)$this->ReadPropertyFloat('TIB_Threshold_Export');
        $fcMinPower     = (float)$this->ReadPropertyInteger('FORECAST_Min_Power_W');

        $price          = $s['tib_price_eff'];
        $soc            = $s['bat_soc'];
        $pvW            = $s['pv_total_w'];
        $feedTariff     = $s['tib_feed'];

        // ── 1. §14a Nacht-Laden ──────────────────────────────────────
        if ($s['enwg_in_window'] && $s['bat_active'] && $soc < ($socTargetNight - $hystSoc)) {
            $d['op_mode']    = EMS_OP_NET_CHARGE;
            $d['gw_mode']    = GW_MODE_AC_IMPORT;
            $d['gw_power_w'] = (int)$slsW;
            $d['wb1_enable'] = ($s['wb1_cable'] > 0 && $s['wb1_error'] === 0);
            $d['wb2_enable'] = ($s['wb_count'] >= 2 && $s['wb2_cable'] > 0 && $s['wb2_error'] === 0);
            $d['reason']     = sprintf(
                '14a Nacht-Laden: SOC=%.0f%% Ziel=%.0f%% Preis=%.2fct(eff)',
                $soc, $socTargetNight, $price
            );
            return $d;
        }

        // ── 2. Tibber guenstig → Netz laden ─────────────────────────
        if ($s['tib_active'] && $s['bat_active'] && $price < ($thCharge - $hystPrice) && $soc < ($socTargetNight - $hystSoc)) {
            $d['op_mode']    = EMS_OP_NET_CHARGE;
            $d['gw_mode']    = GW_MODE_AC_IMPORT;
            $d['gw_power_w'] = (int)$slsW;
            $d['wb1_enable'] = ($s['wb1_cable'] > 0 && $s['wb1_error'] === 0);
            $d['wb2_enable'] = ($s['wb_count'] >= 2 && $s['wb2_cable'] > 0 && $s['wb2_error'] === 0);
            $d['reason']     = sprintf('Tibber guenstig: %.2fct < %.2fct, Netz laden', $price, $thCharge);
            return $d;
        }

        // ── 3. PV-Ueberschuss → Eigenverbrauch ──────────────────────
        if ($pvW > $fcMinPower && $s['bat_active'] && $soc < ($socTargetDay - $hystSoc)) {
            $d['op_mode']    = EMS_OP_PV_SELFUSE;
            $d['gw_mode']    = GW_MODE_CHARGE_PV;
            $d['gw_power_w'] = 0;
            $d['wb1_enable'] = ($s['wb1_cable'] > 0 && (!$s['tib_active'] || $price < $thWB) && $s['wb1_error'] === 0);
            $d['wb2_enable'] = ($s['wb_count'] >= 2 && $s['wb2_cable'] > 0 && (!$s['tib_active'] || $price < $thWB) && $s['wb2_error'] === 0);
            $d['reason']     = sprintf('PV-Eigenverbrauch: %.0fW PV, SOC=%.0f%%', $pvW, $soc);
            return $d;
        }

        // ── 4. Tibber teuer → Entladen ───────────────────────────────
        if ($s['tib_active'] && $s['bat_active'] && $price > ($thDischarge + $hystPrice) && $soc > ($socMin + $hystSoc + $socReserve)) {
            $d['op_mode']    = EMS_OP_DISCHARGE;
            $d['gw_mode']    = GW_MODE_DISCHARGE;
            $d['gw_power_w'] = 0;
            $d['wb1_enable'] = false;
            $d['wb2_enable'] = false;
            $d['reason']     = sprintf('Tibber teuer: %.2fct > %.2fct, Entladen', $price, $thDischarge);
            return $d;
        }

        // ── 5. Tibber sehr teuer → Exportieren ──────────────────────
        if ($s['tib_active'] && $s['bat_active'] && $price > ($thExport + $hystPrice) && $price > $feedTariff && $soc > ($socMin + $hystSoc + $socReserve)) {
            $d['op_mode']    = EMS_OP_EXPORT;
            $d['gw_mode']    = GW_MODE_AC_EXPORT;
            $d['gw_power_w'] = 0;
            $d['wb1_enable'] = false;
            $d['wb2_enable'] = false;
            $d['reason']     = sprintf('Export: %.2fct > Einspeiseverguetung %.2fct', $price, $feedTariff);
            return $d;
        }

        // ── 6. Wallbox freigeben wenn Preis akzeptabel ───────────────
        $wb1En = ($s['wb_active'] && $s['wb1_cable'] > 0 && $s['wb1_error'] === 0 && (!$s['tib_active'] || $price < $thWB));
        $wb2En = ($s['wb_active'] && $s['wb_count'] >= 2 && $s['wb2_cable'] > 0 && $s['wb2_error'] === 0 && (!$s['tib_active'] || $price < $thWB));

        // ── 7. Fallback: Automatik ───────────────────────────────────
        $d['op_mode']    = EMS_OP_AUTO;
        $d['gw_mode']    = GW_MODE_AUTO;
        $d['gw_power_w'] = 0;
        $d['wb1_enable'] = $wb1En;
        $d['wb2_enable'] = $wb2En;
        $d['reason']     = sprintf('Automatik: SOC=%.0f%% Preis=%.2fct PV=%.0fW', $soc, $price, $pvW);
        return $d;
    }

    // ----------------------------------------------------------------
    //  Layer 5: Steuerungs-Layer
    // ----------------------------------------------------------------

    private function applyDecision($d, $s)
    {
        $lastMode     = $this->ReadAttributeInteger('LastGoodweMode');
        $cooldown     = $this->ReadPropertyInteger('OPT_Cooldown_Sec');
        $lastDecision = $this->ReadAttributeInteger('LastDecision');
        $now          = time();

        // Cooldown pruefen — bei Grid Rewards immer aktualisieren
        // da sich die Import-Leistung laufend aendern kann
        $isGridRewards = ($d['op_mode'] === EMS_OP_GRIDREWARDS);
        if (!$isGridRewards && $d['gw_mode'] !== $lastMode && ($now - $lastDecision) < $cooldown) {
            $this->emsLog(EMS_LOG_VERBOSE, 'Cooldown aktiv (' . ($now - $lastDecision) . 's < ' . $cooldown . 's)');
            return;
        }

        // Goodwe Modus setzen
        if ($d['gw_mode'] !== $lastMode || $isGridRewards) {
            $this->setGoodweMode($d['gw_mode'], $d['gw_power_w']);
            $this->WriteAttributeInteger('LastGoodweMode', $d['gw_mode']);
            $this->WriteAttributeInteger('LastDecision',   $now);
            $this->emsLog(EMS_LOG_BASIC, 'Goodwe -> Modus ' . $d['gw_mode'] . ' | ' . $d['reason']);
        }

        // Leistungseinstellung immer aktualisieren wenn gesetzt
        if ($d['gw_power_w'] > 0) {
            $varPower = $this->ReadPropertyInteger('VAR_WR_EMS_Power');
            if ($varPower > 0) {
                $this->writeVar($varPower, $d['gw_power_w']);
            }
        }

        // Wallboxen steuern
        if ($s['wb_active']) {
            $this->controlWallbox(1, $d['wb1_enable']);
            if ($s['wb_count'] >= 2) {
                $this->controlWallbox(2, $d['wb2_enable']);
            }
        }

        $this->SetValue('EMS_Mode',       $d['op_mode']);
        $this->SetValue('EMS_LastAction', $d['reason']);
        $this->SetValue('EMS_Status',     'OK: ' . $d['reason']);
    }

    private function setGoodweMode($mode, $powerW)
    {
        $varMode  = $this->ReadPropertyInteger('VAR_WR_EMS_Mode');
        $varPower = $this->ReadPropertyInteger('VAR_WR_EMS_Power');
        if ($varMode  > 0) { $this->writeVar($varMode, $mode); }
        if ($varPower > 0 && $powerW > 0) { $this->writeVar($varPower, $powerW); }
    }

    private function controlWallbox($num, $enable)
    {
        $instance = $this->ReadPropertyInteger('WB' . $num . '_Instance');
        if ($instance <= 0) { return; }

        $lastSwitch = $this->ReadAttributeInteger('LastWB' . $num . 'Switch');
        $cooldown   = $this->ReadPropertyInteger('WB_Cooldown_Sec');
        if ((time() - $lastSwitch) < $cooldown) {
            $this->emsLog(EMS_LOG_VERBOSE, 'WB' . $num . ': Cooldown aktiv');
            return;
        }

        $isActive = ((int)$this->readVar('VAR_WB' . $num . '_Active', 0) == 1);

        if ($enable && !$isActive) {
            $maxPower = $this->ReadPropertyInteger('WB' . $num . '_Max_Power_W');
            GOeCharger_SetMode($instance, 2);
            GOeCharger_SetCurrentChargingWatt($instance, $maxPower);
            $this->WriteAttributeInteger('LastWB' . $num . 'Switch', time());
            $this->emsLog(EMS_LOG_BASIC, 'WB' . $num . ' freigegeben (' . $maxPower . ' W)');
        } elseif (!$enable && $isActive) {
            GOeCharger_SetMode($instance, 1);
            $this->WriteAttributeInteger('LastWB' . $num . 'Switch', time());
            $this->emsLog(EMS_LOG_BASIC, 'WB' . $num . ' gesperrt');
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
        $this->setGoodweMode($fallbackMode, 0);
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
        $hour   = (int)date('G');
        if ($startH < $endH) {
            return ($hour >= $startH && $hour < $endH);
        }
        return ($hour >= $startH || $hour < $endH);
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
