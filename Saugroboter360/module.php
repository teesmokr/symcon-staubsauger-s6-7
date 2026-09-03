<?php

declare(strict_types=1);

/**
 * IP-Symcon module for controlling 360 (Qihoo) robot vacuums (S6 / S7)
 * through the 360 smart cloud (q.smart.360.cn).
 *
 * Authentication is done with the session cookie that the 360 app uses.
 * The cookie has to be supplied in the module configuration.
 */
class Saugroboter360 extends IPSModule
{
    // Cloud endpoints
    private const API_HOST       = 'https://q.smart.360.cn';
    private const EP_CMD_SEND    = '/clean/cmd/send';
    private const EP_DEV_GETLIST = '/common/dev/GetList';

    // Command identifiers (infoType) used by the 360 cloud
    private const INFO_CLEAN   = 21005; // start / stop cleaning
    private const INFO_CHARGE  = 21012; // return to charging dock
    private const INFO_PAUSE   = 21017; // pause / resume / stop
    private const INFO_LOCATE  = 21020; // locate robot (play sound)
    private const INFO_SUCTION = 21022; // set suction / fan level

    // Device type of the vacuum in the 360 cloud
    private const DEV_TYPE = 3;

    // Action variable associations
    private const ACTION_START  = 0;
    private const ACTION_PAUSE  = 1;
    private const ACTION_STOP   = 2;
    private const ACTION_CHARGE = 3;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Cookie', '');
        $this->RegisterPropertyString('Serial', '');
        $this->RegisterPropertyInteger('UpdateInterval', 60);

        $this->RegisterAttributeString('LastState', '');

        $this->RegisterTimer('SR360_Update', 0, 'SR360_UpdateStatus($_IPS[\'TARGET\']);');

        $this->registerProfiles();

        // Control variables
        $this->RegisterVariableInteger('Action', $this->Translate('Control'), 'SR360.Action', 10);
        $this->EnableAction('Action');

        $this->RegisterVariableInteger('FanLevel', $this->Translate('Suction level'), 'SR360.FanLevel', 20);
        $this->EnableAction('FanLevel');

        $this->RegisterVariableBoolean('Locate', $this->Translate('Locate robot'), '~Switch', 30);
        $this->EnableAction('Locate');

        // Status variables (read only)
        $this->RegisterVariableInteger('Status', $this->Translate('Status'), 'SR360.Status', 40);
        $this->RegisterVariableInteger('Battery', $this->Translate('Battery'), '~Battery.100', 50);
        $this->RegisterVariableBoolean('Online', $this->Translate('Online'), '~Alert.Reversed', 60);

        // Enable the custom HTML tile in the tile visualization
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $interval = $this->ReadPropertyInteger('UpdateInterval');
        $ok       = ($this->ReadPropertyString('Cookie') !== '') && ($this->ReadPropertyString('Serial') !== '');

        $this->SetTimerInterval('SR360_Update', ($ok && $interval > 0) ? $interval * 1000 : 0);

        if (!$ok) {
            $this->SetStatus(104); // inactive / not configured
            return;
        }

        $this->SetStatus(102); // active
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Action':
                switch ((int) $Value) {
                    case self::ACTION_START:
                        $this->StartCleaning();
                        break;
                    case self::ACTION_PAUSE:
                        $this->Pause();
                        break;
                    case self::ACTION_STOP:
                        $this->StopCleaning();
                        break;
                    case self::ACTION_CHARGE:
                        $this->GoCharging();
                        break;
                }
                $this->SetValue('Action', (int) $Value);
                $this->pushVisualizationValues();
                break;

            case 'FanLevel':
                $this->SetFanLevel((int) $Value);
                break;

            case 'Locate':
                $this->Locate();
                break;

            default:
                throw new Exception('Invalid Ident: ' . $Ident);
        }
    }

    /* ---------------------------------------------------------------------
     * Public API (callable from scripts as SR360_*)
     * ------------------------------------------------------------------- */

    public function StartCleaning(): bool
    {
        return $this->sendCommand(self::INFO_CLEAN, ['mode' => 'smartClean', 'globalCleanTimes' => 1]);
    }

    public function StopCleaning(): bool
    {
        return $this->sendCommand(self::INFO_PAUSE, ['cmd' => 'stop']);
    }

    public function Pause(): bool
    {
        return $this->sendCommand(self::INFO_PAUSE, ['cmd' => 'pause']);
    }

    public function Resume(): bool
    {
        return $this->sendCommand(self::INFO_PAUSE, ['cmd' => 'resume']);
    }

    public function GoCharging(): bool
    {
        return $this->sendCommand(self::INFO_CHARGE, ['cmd' => 'start']);
    }

    public function Locate(): bool
    {
        return $this->sendCommand(self::INFO_LOCATE, ['ctrlCode' => 3010]);
    }

    /**
     * @param int $level 0 = quiet, 1 = auto, 2 = strong, 3 = max
     */
    public function SetFanLevel(int $level): bool
    {
        $map = [0 => 'quiet', 1 => 'auto', 2 => 'strong', 3 => 'max'];
        if (!isset($map[$level])) {
            $this->LogMessage('Invalid fan level: ' . $level, KL_ERROR);
            return false;
        }
        $ok = $this->sendCommand(self::INFO_SUCTION, ['cmd' => $map[$level], 'cleanType' => 'total']);
        if ($ok) {
            $this->SetValue('FanLevel', $level);
        }
        return $ok;
    }

    /**
     * Polls the current device state from the cloud and updates the variables.
     */
    public function UpdateStatus(): bool
    {
        $response = $this->request(self::EP_DEV_GETLIST, []);
        if ($response === null) {
            return false;
        }

        $this->SendDebug('GetList', $response, 0);
        $data = json_decode($response, true);
        if (!is_array($data)) {
            $this->LogMessage('Could not decode device list response', KL_WARNING);
            return false;
        }

        $device = $this->findDevice($data, $this->ReadPropertyString('Serial'));
        if ($device === null) {
            $this->SetValue('Online', false);
            return false;
        }

        $this->WriteAttributeString('LastState', json_encode($device));

        // Online state
        $online = $this->findValue($device, ['online', 'isOnline', 'connectStatus']);
        $this->SetValue('Online', $online !== null ? ((int) $online === 1) : true);

        // Battery
        $battery = $this->findValue($device, ['elec', 'battery', 'batteryLevel', 'electricity']);
        if ($battery !== null) {
            $this->SetValue('Battery', (int) $battery);
        }

        // Work / clean state
        $state = $this->findValue($device, ['workMode', 'mode', 'state', 'workState', 'runState']);
        if ($state !== null) {
            $this->SetValue('Status', $this->mapState((string) $state));
        }

        $this->pushVisualizationValues();

        return true;
    }

    /**
     * Fetches the list of vacuums from the cloud (used by the configuration form).
     */
    public function LoadDevices(): string
    {
        $response = $this->request(self::EP_DEV_GETLIST, []);
        if ($response === null) {
            echo $this->Translate('Request failed. Please check the cookie.');
            return '';
        }
        return $response;
    }

    /* ---------------------------------------------------------------------
     * Tile visualization (HTML SDK)
     * ------------------------------------------------------------------- */

    public function GetVisualizationTile()
    {
        $initial = json_encode($this->getVisualizationPayload());

        $html = '<style>
    .sr360 { box-sizing: border-box; padding: 16px; font-family: inherit; color: inherit; }
    .sr360 * { box-sizing: border-box; }
    .sr360-head { display: flex; align-items: center; gap: 12px; }
    .sr360-icon { width: 46px; height: 46px; flex: 0 0 auto; opacity: 0.9; }
    .sr360-info { min-width: 0; }
    .sr360-name { font-size: 15px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .sr360-status { font-size: 13px; opacity: 0.75; margin-top: 2px; }
    .sr360-battery { display: flex; align-items: center; gap: 8px; margin: 14px 0 4px; font-size: 13px; }
    .sr360-batbar { flex: 1; height: 6px; border-radius: 3px; background: rgba(127,127,127,0.25); overflow: hidden; }
    .sr360-batfill { height: 100%; width: 0; background: #34c759; transition: width 0.4s ease; }
    .sr360-batval { min-width: 36px; text-align: right; opacity: 0.85; }
    .sr360-btns { display: flex; gap: 8px; margin-top: 14px; }
    .sr360-btn { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px;
        padding: 10px 4px; border: none; border-radius: 10px; cursor: pointer;
        background: rgba(127,127,127,0.15); color: inherit; font: inherit; font-size: 12px;
        transition: background 0.15s ease; }
    .sr360-btn:hover { background: rgba(127,127,127,0.28); }
    .sr360-btn:active { transform: translateY(1px); }
    .sr360-btn svg { width: 22px; height: 22px; }
    .sr360-btn.start svg { color: #34c759; }
    .sr360-btn.pause svg { color: #ff9f0a; }
    .sr360-btn.dock  svg { color: #0a84ff; }
</style>
<div class="sr360">
    <div class="sr360-head">
        <svg class="sr360-icon" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="24" cy="24" r="21" stroke="currentColor" stroke-width="2.5"/>
            <circle cx="24" cy="24" r="6" fill="currentColor" opacity="0.35"/>
            <circle cx="18" cy="14" r="2.2" fill="currentColor"/>
            <circle cx="24" cy="14" r="2.2" fill="currentColor"/>
        </svg>
        <div class="sr360-info">
            <div class="sr360-name" id="sr360-name"></div>
            <div class="sr360-status" id="sr360-status"></div>
        </div>
    </div>
    <div class="sr360-battery" id="sr360-batrow">
        <span>Batterie</span>
        <div class="sr360-batbar"><div class="sr360-batfill" id="sr360-batfill"></div></div>
        <span class="sr360-batval" id="sr360-batval"></span>
    </div>
    <div class="sr360-btns">
        <button class="sr360-btn start" onclick="requestAction(\'Action\', 0)">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
            Starten
        </button>
        <button class="sr360-btn pause" onclick="requestAction(\'Action\', 1)">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>
            Pause
        </button>
        <button class="sr360-btn dock" onclick="requestAction(\'Action\', 3)">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 3 3 10v11h6v-6h6v6h6V10z"/></svg>
            Ladestation
        </button>
    </div>
</div>';

        $html .= '<script>var SR360_INITIAL = ' . $initial . ';</script>';
        $html .= <<<'JS'
<script>
    function sr360Render(d) {
        if (!d) { return; }
        document.getElementById('sr360-name').textContent = d.name || 'Saugroboter';
        document.getElementById('sr360-status').textContent = d.statusText || '';
        var row = document.getElementById('sr360-batrow');
        if (d.batteryKnown) {
            row.style.display = 'flex';
            document.getElementById('sr360-batfill').style.width = d.battery + '%';
            document.getElementById('sr360-batval').textContent = d.battery + ' %';
        } else {
            row.style.display = 'none';
        }
    }
    function handleMessage(data) {
        sr360Render(typeof data === 'string' ? JSON.parse(data) : data);
    }
    sr360Render(SR360_INITIAL);
</script>
JS;

        return $html;
    }

    private function pushVisualizationValues(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->getVisualizationPayload()));
    }

    private function getVisualizationPayload(): array
    {
        $status  = (int) $this->GetValue('Status');
        $battery = (int) $this->GetValue('Battery');

        return [
            'name'         => IPS_GetName($this->InstanceID),
            'status'       => $status,
            'statusText'   => $this->getStatusLabel($status),
            'battery'      => $battery,
            'batteryKnown' => $battery > 0,
        ];
    }

    private function getStatusLabel(int $status): string
    {
        $labels = [
            0 => 'Bereit',
            1 => 'Reinigt',
            2 => 'Pausiert',
            3 => 'Fährt zur Ladestation',
            4 => 'Aufgeladen',
            5 => 'Fehler',
        ];
        return $labels[$status] ?? 'Unbekannt';
    }

    /* ---------------------------------------------------------------------
     * Internal helpers
     * ------------------------------------------------------------------- */

    private function sendCommand(int $infoType, array $data): bool
    {
        $serial = $this->ReadPropertyString('Serial');
        if ($serial === '') {
            $this->LogMessage('No serial number configured', KL_ERROR);
            return false;
        }

        $post = [
            'sn'       => $serial,
            'infoType' => $infoType,
            'data'     => json_encode($data),
            'devType'  => self::DEV_TYPE,
        ];

        $response = $this->request(self::EP_CMD_SEND, $post);
        if ($response === null) {
            return false;
        }

        $this->SendDebug('cmd/send', $response, 0);
        $decoded = json_decode($response, true);
        $errno   = is_array($decoded) ? ($decoded['errno'] ?? $decoded['errorCode'] ?? 0) : 0;
        if ((int) $errno !== 0) {
            $this->LogMessage('Command failed (infoType ' . $infoType . '): ' . $response, KL_WARNING);
            return false;
        }

        return true;
    }

    /**
     * Performs a POST request against the 360 cloud.
     *
     * @return string|null Raw response body or null on transport error.
     */
    private function request(string $endpoint, array $postFields): ?string
    {
        $cookie = trim($this->ReadPropertyString('Cookie'));
        if ($cookie === '') {
            $this->LogMessage('No cookie configured', KL_ERROR);
            return null;
        }

        $ch = curl_init(self::API_HOST . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postFields),
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept-Language: de-DE;q=1',
                'Cookie: ' . $cookie,
            ],
        ]);

        $result = curl_exec($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            $this->LogMessage('HTTP request failed: ' . $error, KL_ERROR);
            return null;
        }

        if ($code < 200 || $code >= 300) {
            $this->LogMessage('HTTP request returned status ' . $code, KL_WARNING);
        }

        return (string) $result;
    }

    /**
     * Recursively searches a device list response for the entry with the given serial number.
     */
    private function findDevice(array $data, string $serial): ?array
    {
        if ($serial !== '' && isset($data['sn']) && (string) $data['sn'] === $serial) {
            return $data;
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                // A plain list of devices
                if ($serial === '' && isset($value['sn'])) {
                    return $value;
                }
                $found = $this->findDevice($value, $serial);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Returns the first matching key value found anywhere inside a nested array.
     *
     * @param string[] $keys
     */
    private function findValue(array $data, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && !is_array($data[$key])) {
                return $data[$key];
            }
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->findValue($value, $keys);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * Maps a cloud work-state string to the Status variable value.
     */
    private function mapState(string $state): int
    {
        $state = strtolower($state);
        $map   = [
            'idle'         => 0,
            'standby'      => 0,
            'sleep'        => 0,
            'clean'        => 1,
            'cleaning'     => 1,
            'smartclean'   => 1,
            'work'         => 1,
            'working'      => 1,
            'pause'        => 2,
            'paused'       => 2,
            'charge'       => 3,
            'charging'     => 3,
            'gocharge'     => 3,
            'backcharge'   => 3,
            'charged'      => 4,
            'full'         => 4,
            'error'        => 5,
            'fault'        => 5,
        ];

        foreach ($map as $needle => $value) {
            if (strpos($state, $needle) !== false) {
                return $value;
            }
        }

        return 0;
    }

    private function registerProfiles(): void
    {
        if (!IPS_VariableProfileExists('SR360.Action')) {
            IPS_CreateVariableProfile('SR360.Action', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon('SR360.Action', 'Repeat');
            IPS_SetVariableProfileAssociation('SR360.Action', self::ACTION_START, $this->Translate('Start'), 'Play', -1);
            IPS_SetVariableProfileAssociation('SR360.Action', self::ACTION_PAUSE, $this->Translate('Pause'), 'Pause', -1);
            IPS_SetVariableProfileAssociation('SR360.Action', self::ACTION_STOP, $this->Translate('Stop'), 'Stop', -1);
            IPS_SetVariableProfileAssociation('SR360.Action', self::ACTION_CHARGE, $this->Translate('Charge'), 'EnergyStorage', -1);
        }

        if (!IPS_VariableProfileExists('SR360.FanLevel')) {
            IPS_CreateVariableProfile('SR360.FanLevel', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon('SR360.FanLevel', 'Ventilation');
            IPS_SetVariableProfileAssociation('SR360.FanLevel', 0, $this->Translate('Quiet'), '', -1);
            IPS_SetVariableProfileAssociation('SR360.FanLevel', 1, $this->Translate('Auto'), '', -1);
            IPS_SetVariableProfileAssociation('SR360.FanLevel', 2, $this->Translate('Strong'), '', -1);
            IPS_SetVariableProfileAssociation('SR360.FanLevel', 3, $this->Translate('Max'), '', -1);
        }

        if (!IPS_VariableProfileExists('SR360.Status')) {
            IPS_CreateVariableProfile('SR360.Status', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon('SR360.Status', 'Information');
            IPS_SetVariableProfileAssociation('SR360.Status', 0, $this->Translate('Idle'), '', -1);
            IPS_SetVariableProfileAssociation('SR360.Status', 1, $this->Translate('Cleaning'), '', 0x00FF00);
            IPS_SetVariableProfileAssociation('SR360.Status', 2, $this->Translate('Paused'), '', 0xFFFF00);
            IPS_SetVariableProfileAssociation('SR360.Status', 3, $this->Translate('Returning to dock'), '', 0x00AAFF);
            IPS_SetVariableProfileAssociation('SR360.Status', 4, $this->Translate('Charged'), '', 0x00FF00);
            IPS_SetVariableProfileAssociation('SR360.Status', 5, $this->Translate('Error'), '', 0xFF0000);
        }
    }
}
