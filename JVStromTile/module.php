<?php
/**
 * JV Stromzaehler Klappkachel – IP-Symcon >=9.0
 * Anzeige der originalen sechs Stromzaehlerwerte + einklappbarer 13er-Geraeteliste.
 * Datenquelle sind ausschliesslich vorhandene Variablen; keine Polls, keine neuen Zaehler.
 * Bedienaktion ausschliesslich fuer Frostwaechter #36571.
 * module.json name = JVStromTile; Klasse = JVStromTile; Ordner = JVStromTile.
 */
class JVStromTile extends IPSModule
{
    private const METER_LINK_IDS = [34305, 29479, 15910, 40209, 16069, 16395];
    // Links können in Symcon einen leeren eigenen Namen haben.
    // Dann zeigt Symcon den Zielnamen an, IPS_GetName(LinkID) jedoch nicht.
    private const METER_LABELS = [
        34305 => 'Zählerstand',
        29479 => 'aktueller Verbrauch',
        15910 => 'Verbrauch letzte 24 Stunden',
        40209 => 'Verbrauch letzte 7 Tage',
        16069 => 'Verbrauch letzte 30 Tage',
        16395 => 'Verbrauch letzte 365 Tage'
    ];
    // Nur fuer die Kuehlzelle: bei Shelly-Offline die Visualisierungsleistung auf 0 setzen.
    private const COLDROOM_POWER = 35646;
    private const COLDROOM_ONLINE = 17999;

    private const DEVICES = [
        59600 => 'Lüftungsanlage',
        47825 => 'Kochfeld',
        48714 => 'Backofen links',
        12108 => 'Backofen rechts',
        19976 => 'Kühlschrank',
        50441 => 'Gefrierschrank',
        35646 => 'Kühlzelle',
        57303 => 'Spülmaschine',
        44060 => 'Waschmaschine',
        29244 => 'Trockner',
        24271 => 'TV',
        40059 => 'HiFi',
        37121 => 'Frostwächter Garage WC'
    ];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('OriginalID', 52655);
        $this->RegisterPropertyInteger('FrostID', 36571);
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        foreach ($this->GetMessageList() as $sender => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage((int)$sender, $message);
            }
        }
        foreach (array_keys($this->AllSources()) as $id) {
            if (IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }
        $this->UpdateVisualizationValue(json_encode($this->Snapshot(), JSON_UNESCAPED_UNICODE));
    }

    private function MeterSources(): array
    {
        $origin = $this->ReadPropertyInteger('OriginalID');
        $rows = [];
        if (!IPS_ObjectExists($origin)) {
            return [];
        }
        foreach (self::METER_LINK_IDS as $linkID) {
            if (!IPS_LinkExists($linkID) || IPS_GetParent($linkID) !== $origin) {
                continue;
            }
            $id = (int)IPS_GetLink($linkID)['TargetID'];
            if (!IPS_VariableExists($id)) {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'label' => self::METER_LABELS[$linkID],
                'position' => IPS_GetObject($linkID)['ObjectPosition']
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            return $a['position'] <=> $b['position'];
        });
        return $rows;
    }

    private function AllSources(): array
    {
        $ids = [];
        foreach ($this->MeterSources() as $row) {
            $ids[(int)$row['id']] = true;
        }
        foreach (self::DEVICES as $id => $label) {
            $ids[$id] = true;
        }
        $ids[$this->ReadPropertyInteger('FrostID')] = true;
        // Erreichbarkeit der Kuehlzelle ist ein eigenes Ereignis (VM_UPDATE).
        $ids[self::COLDROOM_ONLINE] = true;
        return $ids;
    }

    private function FormattedValue(int $id): string
    {
        // Anzeige-Korrektur NUR bei Kuehlzelle: kein SetValue und kein Archiv-Eingriff.
        // Wenn die Sicherung aus ist, meldet der Shelly keine neuen Wattwerte.
        if ($id === self::COLDROOM_POWER && $this->ColdroomIsOffline()) {
            return '0,00 W';
        }
        if (!IPS_VariableExists($id)) {
            return '–';
        }
        $value = trim((string)GetValueFormatted($id));
        // Symcon liefert bei dieser SDK-Darstellung u.U. einen Punkt statt
        // des in der deutschen Standardkachel verwendeten Dezimalkommas.
        if (preg_match('/^(-?[0-9]+\.[0-9]{1,2})(\s+(?:kWh|W))$/u', $value, $parts)) {
            return str_replace('.', ',', $parts[1]) . $parts[2];
        }
        return $value;
    }

    private function ColdroomIsOffline(): bool
    {
        // Falls die Quelle wider Erwarten fehlt, NICHT als Offline interpretieren.
        // Der Installer prueft Typ und Elterninstanz gesondert.
        return IPS_VariableExists(self::COLDROOM_ONLINE)
            && IPS_GetVariable(self::COLDROOM_ONLINE)['VariableType'] === 0
            && GetValue(self::COLDROOM_ONLINE) === false;
    }

    private function CanSwitchFrost(): bool
    {
        $id = $this->ReadPropertyInteger('FrostID');
        if (!IPS_VariableExists($id) || IPS_GetVariable($id)['VariableType'] !== 0) {
            return false;
        }
        if (function_exists('HasAction')) {
            return (bool)HasAction($id);
        }
        $v = IPS_GetVariable($id);
        return ($v['VariableCustomAction'] > 1)
            || ($v['VariableCustomAction'] === 0 && $v['VariableAction'] > 0);
    }

    private function Snapshot(): array
    {
        $values = [];
        foreach ($this->AllSources() as $id => $ignored) {
            if ($id !== $this->ReadPropertyInteger('FrostID') && $id !== self::COLDROOM_ONLINE) {
                $values['v' . $id] = $this->FormattedValue((int)$id);
            }
        }
        $frostID = $this->ReadPropertyInteger('FrostID');
        $values['frost'] = IPS_VariableExists($frostID) ? (bool)GetValue($frostID) : false;
        $values['frostEnabled'] = $this->CanSwitchFrost();
        return $values;
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== VM_UPDATE) {
            return;
        }
        if (!isset($this->AllSources()[$SenderID])) {
            return;
        }
        $frostID = $this->ReadPropertyInteger('FrostID');
        if ($SenderID === $frostID) {
            $delta = ['frost' => (bool)GetValue($frostID)];
        } elseif ($SenderID === self::COLDROOM_ONLINE) {
            // Auch bei Ausfall/Wiederkehr sofort Kuehlzellenanzeige aktualisieren.
            $delta = ['v' . self::COLDROOM_POWER => $this->FormattedValue(self::COLDROOM_POWER)];
        } else {
            $delta = ['v' . $SenderID => $this->FormattedValue((int)$SenderID)];
        }
        $this->UpdateVisualizationValue(json_encode($delta, JSON_UNESCAPED_UNICODE));
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident !== 'Frost') {
            throw new InvalidArgumentException('Unbekannte Aktion.');
        }
        if (!$this->CanSwitchFrost()) {
            throw new RuntimeException('Frostwaechter-Variable hat keine Schaltaktion.');
        }
        RequestAction($this->ReadPropertyInteger('FrostID'), (bool)$Value);
    }

    private function Escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function Row(int $id, string $label, string $icon): string
    {
        $iconSvg = ($icon === 'clock')
            ? '<i class="fa-light fa-gauge" aria-hidden="true"></i>'
            : '<i class="fa-light fa-bolt" aria-hidden="true"></i>';
        $trendButton = ($icon === 'clock')
            ? '<button type="button" class="jv-trend" title="Zählerverlauf öffnen"'
                . ' onclick="openObject(' . $id . ')"><i class="fa-light fa-chart-line" aria-hidden="true"></i></button>'
            : '';
        return '<div class="jv-row"><span class="jv-icon" aria-hidden="true">' . $iconSvg . '</span>'
            . '<span class="jv-label">' . $this->Escape($label) . $trendButton . '</span>'
            . '<span class="jv-value" id="jv-v-' . $id . '">' . $this->Escape($this->FormattedValue($id)) . '</span></div>';
    }

    public function GetVisualizationTile()
    {
        $path = __DIR__ . '/module.html';
        $html = @file_get_contents($path);
        if ($html === false) {
            return '<div>Stromzaehler-Ansicht fehlt: module.html</div>';
        }
        $meterRows = '';
        foreach ($this->MeterSources() as $index => $row) {
            $meterRows .= $this->Row((int)$row['id'], (string)$row['label'], $index === 0 ? 'clock' : 'bolt');
        }
        $deviceRows = '';
        foreach (self::DEVICES as $id => $label) {
            $deviceRows .= $this->Row((int)$id, (string)$label, 'bolt');
        }
        $frostID = $this->ReadPropertyInteger('FrostID');
        $checked = IPS_VariableExists($frostID) && (bool)GetValue($frostID) ? ' checked' : '';
        $disabled = $this->CanSwitchFrost() ? '' : ' disabled';
        $frostRow = '<div class="jv-row jv-frost-row"><span class="jv-icon" aria-hidden="true">'
            . '<i class="fa-light fa-power-off" aria-hidden="true"></i>'
            . '</span><span class="jv-label">Frostwächter – Ein/Aus</span>'
            . '<label class="jv-switch" aria-label="Frostwächter schalten">'
            . '<input type="checkbox" id="jv-frost" onchange="jvSwitchFrost(this.checked)"' . $checked . $disabled . '>'
            . '<span class="jv-track"></span></label></div>';
        return str_replace(
            ['__METER_ROWS__', '__DEVICE_ROWS__', '__FROST_ROW__'],
            [$meterRows, $deviceRows, $frostRow],
            $html
        );
    }

    /** Nur lesende Bindungs- und Ereignisdiagnose, keine Schaltbefehle. */
    public function DiagnoseBindings(): array
    {
        $registered = $this->GetMessageList();
        $expectedParents = [
            59600 => 58673, 47825 => 53982, 48714 => 51977,
            12108 => 10015, 19976 => 12618, 50441 => 31142,
            35646 => 11306, 57303 => 33059, 44060 => 40395,
            29244 => 44881, 24271 => 37514, 40059 => 50854,
            37121 => 32878
        ];
        $isSubscribed = static function (int $id) use ($registered): bool {
            return in_array(VM_UPDATE, $registered[$id] ?? [], true);
        };
        $result = [
            'tile' => $this->InstanceID,
            'original' => $this->ReadPropertyInteger('OriginalID'),
            'meter' => [], 'devices' => [], 'frost' => [], 'coldroom' => []
        ];
        $original = $this->ReadPropertyInteger('OriginalID');
        foreach (self::METER_LINK_IDS as $linkID) {
            $item = [
                'link' => $linkID,
                'label' => self::METER_LABELS[$linkID],
                'linkOK' => IPS_LinkExists($linkID) && IPS_GetParent($linkID) === $original,
                'id' => 0, 'typeOK' => false, 'subscribed' => false,
                'value' => '', 'updated' => 0
            ];
            if ($item['linkOK']) {
                $id = (int)IPS_GetLink($linkID)['TargetID'];
                $item['id'] = $id;
                if (IPS_VariableExists($id)) {
                    $variable = IPS_GetVariable($id);
                    $item['typeOK'] = in_array((int)$variable['VariableType'], [1,2], true);
                    $item['subscribed'] = $isSubscribed($id);
                    $item['value'] = $this->FormattedValue($id);
                    $item['updated'] = (int)($variable['VariableUpdated'] ?? 0);
                }
            }
            $result['meter'][] = $item;
        }
        foreach (self::DEVICES as $id => $name) {
            $exists = IPS_VariableExists($id);
            $variable = $exists ? IPS_GetVariable($id) : [];
            $result['devices'][] = [
                'id' => $id, 'name' => $name,
                'exists' => $exists,
                'parentOK' => $exists && IPS_GetParent($id) === $expectedParents[$id],
                'typeOK' => $exists && (int)$variable['VariableType'] === 2,
                'subscribed' => $exists && $isSubscribed($id),
                'value' => $exists ? $this->FormattedValue($id) : '',
                'updated' => $exists ? (int)($variable['VariableUpdated'] ?? 0) : 0
            ];
        }
        $frostID = $this->ReadPropertyInteger('FrostID');
        $exists = IPS_VariableExists($frostID);
        $result['frost'] = [
            'id' => $frostID, 'exists' => $exists,
            'typeOK' => $exists && (int)IPS_GetVariable($frostID)['VariableType'] === 0,
            'subscribed' => $exists && $isSubscribed($frostID),
            'canSwitch' => $this->CanSwitchFrost(),
            'value' => $exists ? (bool)GetValue($frostID) : false
        ];
        $availability = IPS_VariableExists(self::COLDROOM_ONLINE);
        $result['coldroom'] = [
            'availabilityID' => self::COLDROOM_ONLINE,
            'powerID' => self::COLDROOM_POWER,
            'exists' => $availability,
            'parentOK' => $availability && IPS_GetParent(self::COLDROOM_ONLINE) === 11306,
            'typeOK' => $availability && IPS_GetVariable(self::COLDROOM_ONLINE)['VariableType'] === 0,
            'subscribed' => $availability && $isSubscribed(self::COLDROOM_ONLINE),
            'offline' => $this->ColdroomIsOffline(),
            'displayed' => $this->FormattedValue(self::COLDROOM_POWER),
            'raw' => IPS_VariableExists(self::COLDROOM_POWER)
                ? (string)GetValueFormatted(self::COLDROOM_POWER) : '–'
        ];
        return $result;
    }

    /** Sendet aktuelle Werte EINMAL erneut, ohne Quellen abzufragen. */
    public function ResendSnapshot(): bool
    {
        return (bool)$this->UpdateVisualizationValue(
            json_encode($this->Snapshot(), JSON_UNESCAPED_UNICODE)
        );
    }
}