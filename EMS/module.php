<?php

declare(strict_types=1);

class EMS extends IPSModule
{
    // ===============================
    // Konstanten (sicher im Scope)
    // ===============================
    private const MODE_STOP       = 0;
    private const MODE_AUTO       = 1;
    private const MODE_CHARGE_PV  = 2;
    private const MODE_DISCHARGE  = 3;
    private const MODE_AC_IMPORT  = 4;
    private const MODE_AC_EXPORT  = 5;
    private const MODE_STANDBY    = 8;

    // ===============================
    // Create
    // ===============================
    public function Create(): void
    {
        parent::Create();

        // -------- Allgemein --------
        $this->RegisterPropertyBoolean('EMS_Active', false);
        $this->RegisterPropertyInteger('EMS_Interval', 60);
        $this->RegisterPropertyInteger('EMS_Log_Level', 1);

        // -------- Mapping (Minimal zum Start) --------
        $this->RegisterPropertyInteger('VAR_SM_Total_Power', 0);
        $this->RegisterPropertyInteger('VAR_PV_Total_Power', 0);
        $this->RegisterPropertyInteger('VAR_BAT1_SOC', 0);
        $this->RegisterPropertyInteger('VAR_TIB_Price', 0);

        // -------- WR Steuerung --------
        $this->RegisterPropertyInteger('VAR_WR_EMS_Mode', 0);
        $this->RegisterPropertyInteger('VAR_WR_EMS_Power', 0);

        // -------- Statusvariablen --------
        $this->RegisterVariableBoolean('EMS_Running', 'EMS aktiv', '', 10);
        $this->RegisterVariableInteger('EMS_Mode', 'Modus', '', 20);
        $this->RegisterVariableFloat('EMS_GridPower', 'Netzleistung (W)', '', 30);
        $this->RegisterVariableFloat('EMS_PVPower', 'PV Leistung (W)', '', 40);
        $this->RegisterVariableFloat('EMS_BatSOC', 'Batterie SOC (%)', '', 50);
        $this->RegisterVariableFloat('EMS_Price', 'Preis (ct/kWh)', '', 60);
        $this->RegisterVariableString('EMS_Status', 'Status', '', 100);

        // -------- Timer --------
        $this->RegisterTimer('EMS_UpdateTimer', 0, 'EMS_Update($_IPS["TARGET"]);');
    }

    // ===============================
    // ApplyChanges
    // ===============================
    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if ($this->ReadPropertyBoolean('EMS_Active')) {
            $interval = $this->ReadPropertyInteger('EMS_Interval') * 1000;
            $this->SetTimerInterval('EMS_UpdateTimer', $interval);
            $this->SetStatus(102);
        } else {
            $this->SetTimerInterval('EMS_UpdateTimer', 0);
            $this->SetStatus(104);
        }

        $this->SetValue('EMS_Running', $this->ReadPropertyBoolean('EMS_Active'));
    }

    // ===============================
    // Hauptfunktion
    // ===============================
    public function Update(): void
    {
        if (!$this->ReadPropertyBoolean('EMS_Active')) {
            return;
        }

        $grid  = $this->readVar('VAR_SM_Total_Power', 0.0);
        $pv    = $this->readVar('VAR_PV_Total_Power', 0.0);
        $soc   = $this->readVar('VAR_BAT1_SOC', 0.0);
        $price = $this->readVar('VAR_TIB_Price', 0.0);

        // Status aktualisieren
        $this->SetValue('EMS_GridPower', $grid);
        $this->SetValue('EMS_PVPower', $pv);
        $this->SetValue('EMS_BatSOC', $soc);
        $this->SetValue('EMS_Price', $price);

        // ✅ einfache, sichere Logik (macht wirklich was)
        $mode = self::MODE_AUTO;

        if ($pv > 3000 && $soc < 90) {
            $mode = self::MODE_CHARGE_PV;
            $this->setGoodweMode($mode, 0);
            $this->SetValue('EMS_Status', 'PV Überschuss → Laden');
        }
        elseif ($price < 10 && $soc < 80) {
            $mode = self::MODE_AC_IMPORT;
            $this->setGoodweMode($mode, 5000);
            $this->SetValue('EMS_Status', 'Günstiger Strom → Laden');
        }
        elseif ($price > 25 && $soc > 40) {
            $mode = self::MODE_DISCHARGE;
            $this->setGoodweMode($mode, 0);
            $this->SetValue('EMS_Status', 'Teuer → Entladen');
        }
        else {
            $mode = self::MODE_AUTO;
            $this->setGoodweMode($mode, 0);
            $this->SetValue('EMS_Status', 'Automatik');
        }

        $this->SetValue('EMS_Mode', $mode);
    }

    // ===============================
    // WR Steuerung (sicher!)
    // ===============================
    private function setGoodweMode(int $mode, int $power): void
    {
        $modeID  = $this->ReadPropertyInteger('VAR_WR_EMS_Mode');
        $powerID = $this->ReadPropertyInteger('VAR_WR_EMS_Power');

        if ($modeID > 0 && IPS_VariableExists($modeID)) {
            SetValue($modeID, $mode);
        }

        if ($powerID > 0 && IPS_VariableExists($powerID) && $power > 0) {
            SetValue($powerID, $power);
        }
    }

    // ===============================
    // sichere Variable
    // ===============================
    private function readVar(string $name, float $default): float
    {
        $id = $this->ReadPropertyInteger($name);

        if ($id <= 0) {
            return $default;
        }

        if (!IPS_VariableExists($id)) {
            return $default;
        }

        return (float)GetValue($id);
    }
}


// ===============================
// Wrapper (Pflicht für Timer/Button)
// ===============================
function EMS_Update($id)
{
    IPS_RequestAction($id, 'Update', 0);
}
