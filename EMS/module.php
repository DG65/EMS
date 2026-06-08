<?php

class EMS extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // ========== Allgemein ==========
        $this->RegisterPropertyBoolean("EMS_Active", false);
        $this->RegisterPropertyInteger("EMS_Interval", 60);

        $this->RegisterPropertyInteger("EMS_SLS_Limit_A", 50);
        $this->RegisterPropertyInteger("EMS_HAK_Limit_A", 63);
        $this->RegisterPropertyInteger("EMS_SLS_Limit_W", 34500);

        $this->RegisterPropertyInteger("EMS_Fallback_Mode", 1);
        $this->RegisterPropertyInteger("EMS_Fallback_Timeout", 60);

        $this->RegisterPropertyInteger("EMS_Log_Level", 1);

        // ========== SmartMeter ==========
        $this->RegisterPropertyInteger("VAR_SM_L1_Power", 0);
        $this->RegisterPropertyInteger("VAR_SM_L2_Power", 0);
        $this->RegisterPropertyInteger("VAR_SM_L3_Power", 0);
        $this->RegisterPropertyInteger("VAR_SM_Total_Power", 0);

        // ========== PV ==========
        $this->RegisterPropertyInteger("VAR_PV_Total_Power", 0);

        // ========== Batterie ==========
        $this->RegisterPropertyBoolean("BAT_Active", false);
        $this->RegisterPropertyFloat("BAT_Capacity_kWh", 40);

        $this->RegisterPropertyInteger("VAR_BAT1_SOC", 0);
        $this->RegisterPropertyInteger("VAR_BAT1_Power", 0);

        // ========== Wallbox ==========
        $this->RegisterPropertyBoolean("WB_Active", false);
        $this->RegisterPropertyInteger("WB_Count", 1);

        $this->RegisterPropertyInteger("WB1_Instance", 0);
        $this->RegisterPropertyFloat("WB1_Max_Power_W", 11000);

        // ========== Tibber ==========
        $this->RegisterPropertyBoolean("TIBBER_Active", false);
        $this->RegisterPropertyInteger("VAR_TIB_Price", 0);

        // ========== PV Forecast ==========
        $this->RegisterPropertyBoolean("FORECAST_Active", false);
        $this->RegisterPropertyInteger("VAR_FC_JSON", 0);

        // Timer für Update
        $this->RegisterTimer("EMS_Update", 0, 'EMS_Update($_IPS["TARGET"]);');
    }


    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if ($this->ReadPropertyBoolean("EMS_Active")) {
            $interval = $this->ReadPropertyInteger("EMS_Interval");
            $this->SetTimerInterval("EMS_Update", $interval * 1000);
        } else {
            $this->SetTimerInterval("EMS_Update", 0);
        }
    }


    // =============================
    // 🔁 Hauptzyklus
    // =============================
    public function Update()
    {
        if (!$this->ReadPropertyBoolean("EMS_Active")) {
            return;
        }

        $data = $this->CollectData();

        $this->Log(1, "Update: " . json_encode($data));

        // 👉 Hier später Solver einhängen
        // $result = $this->CallSolver($data);
        // $this->ApplyActions($result);
    }


    // =============================
    // 📊 Daten sammeln
    // =============================
    private function CollectData()
    {
        return [
            "grid_power" => $this->GetValueSafe($this->ReadPropertyInteger("VAR_SM_Total_Power")),
            "pv_power"   => $this->GetValueSafe($this->ReadPropertyInteger("VAR_PV_Total_Power")),
            "bat_soc"    => $this->GetValueSafe($this->ReadPropertyInteger("VAR_BAT1_SOC")),
            "bat_power"  => $this->GetValueSafe($this->ReadPropertyInteger("VAR_BAT1_Power")),
            "price"      => $this->GetValueSafe($this->ReadPropertyInteger("VAR_TIB_Price"))
        ];
    }


    // =============================
    // ✅ sichere Variablenabfrage
    // =============================
    private function GetValueSafe($id)
    {
        if ($id > 0 && IPS_VariableExists($id)) {
            return GetValue($id);
        }
        return 0;
    }


    // =============================
    // 📊 Status
    // =============================
    public function GetStatus()
    {
        return json_encode([
            "active" => $this->ReadPropertyBoolean("EMS_Active"),
            "interval" => $this->ReadPropertyInteger("EMS_Interval")
        ]);
    }


    // =============================
    // 🧾 Logging
    // =============================
    private function Log($level, $msg)
    {
        if ($this->ReadPropertyInteger("EMS_Log_Level") >= $level) {
            IPS_LogMessage("EMS", $msg);
        }
    }
}


// =============================
// 🔗 Wrapper für Buttons
// =============================

function EMS_Update($id)
{
    IPS_RequestAction($id, "Update", 0);
}

function EMS_GetStatus($id)
{
    return IPS_RunScriptText('return IPS_GetInstance($id);');
}
