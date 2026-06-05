<?php
class EMS extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger("battery_soc", 0);
        $this->RegisterPropertyString("price_json", "[]");
        $this->RegisterPropertyString("pv_forecast_json", "[]");
        $this->RegisterPropertyString("house_load", "[]");
        $this->RegisterPropertyString("ev_energy", "[20,20]");
        $this->RegisterPropertyString("ev_deadline", "[32,48]");

        $this->RegisterPropertyFloat("battery_capacity", 40);
        $this->RegisterPropertyFloat("soc_min", 10);
        $this->RegisterPropertyFloat("soc_max", 90);
        $this->RegisterPropertyFloat("grid_limit", 34);

        $this->RegisterPropertyString("solver_url", "http://127.0.0.1:5000/optimize");
    }

    public function Run()
    {
        $payload = json_encode([
            "battery_soc" => GetValue($this->ReadPropertyInteger("battery_soc")),
            "price" => json_decode($this->ReadPropertyString("price_json"), true),
            "pv" => json_decode($this->ReadPropertyString("pv_forecast_json"), true),
            "load" => json_decode($this->ReadPropertyString("house_load"), true),
            "ev_energy" => json_decode($this->ReadPropertyString("ev_energy"), true),
            "ev_deadline" => json_decode($this->ReadPropertyString("ev_deadline"), true),
            "config" => [
                "capacity" => $this->ReadPropertyFloat("battery_capacity"),
                "soc_min" => $this->ReadPropertyFloat("soc_min")/100,
                "soc_max" => $this->ReadPropertyFloat("soc_max")/100,
                "grid_limit" => $this->ReadPropertyFloat("grid_limit")
            ]
        ]);

        $url = $this->ReadPropertyString("solver_url");
        $opts = ['http'=>['method'=>'POST','header'=>'Content-Type: application/json','content'=>$payload]];
        $result = file_get_contents($url,false,stream_context_create($opts));

        IPS_LogMessage("EMS_V9", $result);
    }
}
?>