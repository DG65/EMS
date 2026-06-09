<?php
declare(strict_types=1);

class EMS extends IPSModule
{
    private const MODE_AUTO = 1;
    private const MODE_CHARGE_PV = 2;
    private const MODE_DISCHARGE = 3;
    private const MODE_AC_IMPORT = 4;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('EMS_Active', false);
        $this->RegisterPropertyInteger('EMS_Interval', 60);

        $this->RegisterPropertyInteger('VAR_SM_Total_Power', 0);
        $this->RegisterPropertyInteger('VAR_PV_Total_Power', 0);
        $this->RegisterPropertyInteger('VAR_BAT1_SOC', 0);
        $this->RegisterPropertyInteger('VAR_TIB_Price', 0);
        $this->RegisterPropertyInteger('VAR_WB1_Power', 0);
        $this->RegisterPropertyInteger('VAR_WB2_Power', 0);

        $this->RegisterPropertyInteger('VAR_WR_EMS_Mode', 0);
        $this->RegisterPropertyInteger('VAR_WR_EMS_Power', 0);

        $this->RegisterVariableInteger('EMS_Mode', 'Mode');
        $this->RegisterVariableString('EMS_Status', 'Status');

        $this->RegisterVariableBoolean('EMS_GridRewards', 'Grid Rewards');
        $this->EnableAction('EMS_GridRewards');

        $this->RegisterTimer('Update', 0, 'EMS_RequestAction($_IPS["TARGET"], "Update", 0);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if ($this->ReadPropertyBoolean('EMS_Active')) {
            $this->SetTimerInterval('Update', $this->ReadPropertyInteger('EMS_Interval') * 1000);
        } else {
            $this->SetTimerInterval('Update', 0);
        }
    }

    public function RequestAction($ident, $value)
    {
        if ($ident === 'Update') {
            $this->Update();
        }
        if ($ident === 'EMS_GridRewards') {
            $this->SetValue('EMS_GridRewards', $value);
        }
    }

    public function Update(): void
    {
        if (!$this->ReadPropertyBoolean('EMS_Active')) return;

        $state = [
            'grid' => $this->readVar('VAR_SM_Total_Power'),
            'pv' => $this->readVar('VAR_PV_Total_Power'),
            'soc' => $this->readVar('VAR_BAT1_SOC'),
            'price' => $this->readVar('VAR_TIB_Price'),
            'wb' => ($this->readVar('VAR_WB1_Power') + $this->readVar('VAR_WB2_Power')) * 1000,
            'grid_rewards' => $this->GetValue('EMS_GridRewards')
        ];

        $this->SendDebug('STATE', json_encode($state), 0);

        if ($state['grid_rewards']) {
            $this->setGoodweMode(self::MODE_AUTO, $state['wb']);
            $this->SetValue('EMS_Status', "Grid Rewards aktiv");
            return;
        }

        $result = $this->optimize($state);

        $this->setGoodweMode($result['mode'], $result['power']);
        $this->SetValue('EMS_Mode', $result['mode']);
        $this->SetValue('EMS_Status', "Optimiert");
    }

    private function optimize(array $s): array
    {
        $actions = [
            ['mode'=>1,'bat'=>0],
            ['mode'=>2,'bat'=>1],
            ['mode'=>3,'bat'=>-1],
            ['mode'=>4,'bat'=>2]
        ];

        $best=null; $bestCost=INF;

        foreach($actions as $a){
            $cost=0;
            $bat=0;

            if($a['bat']==1) $bat=2000;
            if($a['bat']==-1) $bat=-2000;
            if($a['bat']==2) $bat=3000;

            $grid=$s['grid']+$bat-$s['pv'];

            if($s['grid_rewards'] && $grid>$s['wb']) continue;

            if($grid>0) $cost+=$grid*($s['price']/100);
            else $cost-=abs($grid)*0.18;

            if($cost<$bestCost){
                $bestCost=$cost;
                $best=['mode'=>$a['mode'],'power'=>max(0,$bat)];
            }
        }

        return $best ?? ['mode'=>1,'power'=>0];
    }

    private function setGoodweMode($mode,$power)
    {
        $m=$this->ReadPropertyInteger('VAR_WR_EMS_Mode');
        $p=$this->ReadPropertyInteger('VAR_WR_EMS_Power');

        if($m>0 && IPS_VariableExists($m)) SetValue($m,$mode);
        if($p>0 && IPS_VariableExists($p)) SetValue($p,$power);
    }

    private function readVar($name)
    {
        $id=$this->ReadPropertyInteger($name);
        return ($id>0 && IPS_VariableExists($id)) ? GetValue($id) : 0;
    }
}

?>