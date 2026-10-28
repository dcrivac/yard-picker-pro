<?php
// Pure helpers for VIN handling. No I/O here so tests/run.php can load it.

// Normalize user/OCR input: uppercase, strip spaces and dashes.
function vinNormalize($vin) {
    return strtoupper(preg_replace('/[\s\-]+/', '', (string)$vin));
}

// 17 chars, no I/O/Q (never used in VINs).
function vinFormatOk($vin) {
    return (bool)preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin);
}

// North American check digit (position 9). Required on every vehicle sold
// in the US since 1981, which covers what U-Pull yards carry.
function vinCheckDigitOk($vin) {
    if (!vinFormatOk($vin)) return false;
    $map = [
        'A'=>1,'B'=>2,'C'=>3,'D'=>4,'E'=>5,'F'=>6,'G'=>7,'H'=>8,
        'J'=>1,'K'=>2,'L'=>3,'M'=>4,'N'=>5,'P'=>7,'R'=>9,
        'S'=>2,'T'=>3,'U'=>4,'V'=>5,'W'=>6,'X'=>7,'Y'=>8,'Z'=>9,
    ];
    $weights = [8,7,6,5,4,3,2,10,0,9,8,7,6,5,4,3,2];
    $sum = 0;
    for ($i = 0; $i < 17; $i++) {
        $c = $vin[$i];
        $v = ctype_digit($c) ? (int)$c : $map[$c];
        $sum += $v * $weights[$i];
    }
    $r = $sum % 11;
    return $vin[8] === ($r === 10 ? 'X' : (string)$r);
}

// Turn one vPIC DecodeVinValues result row into the vehicle shape the app
// uses. Returns null when vPIC couldn't identify year/make/model.
function vinVehicleFromVpic($vin, $row) {
    $g = function ($k) use ($row) { return isset($row[$k]) ? trim((string)$row[$k]) : ''; };
    $year = $g('ModelYear'); $make = $g('Make'); $model = $g('Model');
    if (!preg_match('/^\d{4}$/', $year) || $make === '' || $model === '') return null;

    $engine = [];
    if ($g('DisplacementL') !== '' && is_numeric($g('DisplacementL'))) $engine[] = round((float)$g('DisplacementL'), 1) . 'L';
    if ($g('EngineCylinders') !== '') $engine[] = $g('EngineCylinders') . '-cyl';
    if (strcasecmp($g('Turbo'), 'Yes') === 0) $engine[] = 'turbo';
    $elec = $g('ElectrificationLevel');
    if (stripos($elec, 'PHEV') !== false)    $engine[] = 'plug-in hybrid';
    elseif (stripos($elec, 'BEV') !== false) $engine[] = 'electric';
    elseif (stripos($elec, 'HEV') !== false) $engine[] = 'hybrid';
    elseif ($g('FuelTypePrimary') !== '' && stripos($g('FuelTypePrimary'), 'Gasoline') === false) $engine[] = $g('FuelTypePrimary');

    // "FWD/Front-Wheel Drive" -> "FWD"
    $drive = $g('DriveType');
    if (strpos($drive, '/') !== false) $drive = trim(explode('/', $drive)[0]);

    return [
        'vin'          => $vin,
        'year'         => $year,
        'make'         => strtoupper($make),
        'model'        => strtoupper($model),
        'trim'         => trim($g('Trim') . ' ' . $g('Series')),
        'engine'       => implode(' ', $engine),
        'drive'        => $drive,
        'transmission' => $g('TransmissionStyle'),
        'body'         => $g('BodyClass'),
    ];
}
