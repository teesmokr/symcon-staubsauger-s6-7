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

        // Status variable (read only)
        $this->RegisterVariableBoolean('Online', $this->Translate('Online'), '~Alert.Reversed', 60);

        // Enable the custom HTML tile in the tile visualization
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Remove the status/battery variables from older versions: the 360 cloud
        // does not deliver these values via the device list, so they stayed empty.
        foreach (['Status', 'Battery'] as $ident) {
            if (@$this->GetIDForIdent($ident)) {
                $this->UnregisterVariable($ident);
            }
        }
        if (IPS_VariableProfileExists('SR360.Status')) {
            @IPS_DeleteVariableProfile('SR360.Status');
        }

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
        return '<style>
    .sr360 { box-sizing: border-box; padding: 8px; font-family: inherit; color: inherit; }
    .sr360 * { box-sizing: border-box; }
    .sr360-btns { display: flex; gap: 8px; }
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
    }
}
