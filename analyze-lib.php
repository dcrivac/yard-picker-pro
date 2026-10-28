<?php
// Pure helpers for the analysis request. No I/O here so tests/run.php can load it.

// Part names Claude may choose from. Must match the keys of LAB in yp2.html
// (labor hours) and of $LKQ / $PART_CATEGORIES in api.php.
$PROMPT_PARTS = [
    "A/C Compressor","A/C Condenser","A/C Evaporator","ABS Module","Air Flow Meter","Airbag Sensor",
    "Alternator","Amplifier","Axle Assembly Front","Axle Assembly Rear","Axle Shaft",
    "Back Glass Regulator (Electric)","Battery","Battery (Hybrid)","Brake Caliper","Brake Rotor",
    "Bumper Cover Front","Bumper Cover Rear","CV Axle Shaft","Camshaft","Carburetor","Carrier Assembly",
    "Center Console","Center Pillar","Chassis Control Module","Clock Spring","Coil Pack",
    "Control Arm Lower","Control Arm Upper","Crankshaft","Cylinder Head","Dash Panel",
    "DC Converter (Hybrid)","Decklid/Tailgate","Differential","Distributor","Door Assembly Back",
    "Door Front","Door Rear","Drive Shaft","ECU / PCM","Electric Power Steering Pump",
    "Engine (Long Block)","Exhaust Manifold","Fan Clutch","Fender","Flywheel","Front Bumper (Steel)",
    "Fuel Pump","Fuel Pump (Direct Injection)","Fuel Tank","Fuse Box","GPS / Navigation Screen",
    "Harmonic Balancer","Headlight","Heater Core","Hood","Hub Assembly","Independent Rear Suspension",
    "Instrument Cluster","Intake Manifold","Intercooler","Leaf Spring","Mirror (Side View)","Oil Pump",
    "Power Brake Booster","Power Steering Pump","Quarter Panel","Radiator","Radiator Core Support",
    "Radiator/Condenser Fan (Dual)","Radio with Display","Rear Bumper (Steel)","Ring and Pinion Gear",
    "Roof","Seat (Front)","Seat (Rear)","Seat (Third Row)","Shock Absorber","Sliding Door Motor",
    "Spindle/Knuckle","Stabilizer Bar","Starter Motor","Steering Column","Steering Rack",
    "Strut Assembly","Strut (Air)","Suspension Crossmember","Tail Light","Temperature Control",
    "Throttle Body","Torque Converter","Transfer Case","Transfer Case Motor","Transmission (Auto)",
    "Transmission (Manual)","Transmission Solenoid Pack","Transmission Valve Body","Turbocharger",
    "Water Pump","Wheel (Aluminum)","Wheel (Steel)","Window Regulator Front (Electric)",
    "Window Regulator Rear (Electric)","Wiring Harness (Body)","Wiring Harness (Dash)",
    "Wiring Harness (Engine)",
];

// Validate the client's vehicle list. Returns [vehicles, null] or [null, error].
// Every field ends up in the prompt, so each is length- and charset-limited.
function validateVehicles($list) {
    if (!is_array($list) || count($list) < 1 || count($list) > 5) return [null, 'Send 1 to 5 vehicles'];
    $maxYear = (int)gmdate('Y') + 1;
    $out = [];
    foreach (array_values($list) as $i => $v) {
        if (!is_array($v)) return [null, "Vehicle $i is not an object"];
        $year = isset($v['year']) ? trim((string)$v['year']) : '';
        if (!preg_match('/^\d{4}$/', $year) || (int)$year < 1950 || (int)$year > $maxYear) return [null, "Vehicle $i: bad year"];
        $clean = ['year' => $year];
        foreach (['make' => true, 'model' => true, 'row' => false, 'trim' => false, 'engine' => false, 'drive' => false, 'transmission' => false] as $k => $required) {
            $s = isset($v[$k]) ? trim((string)$v[$k]) : '';
            if ($required) {
                if ($s === '') return [null, "Vehicle $i: missing $k"];
                if (!preg_match('/^[A-Za-z0-9 .,\/()&+\-]{1,40}$/', $s)) return [null, "Vehicle $i: bad $k"];
            } else {
                // Optional details come from vPIC or the user; odd characters
                // shouldn't sink the whole request, so drop them instead.
                $s = substr(trim(preg_replace('/[^A-Za-z0-9 .,\/()&+\-]/', '', $s)), 0, 60);
            }
            $clean[$k] = $s;
        }
        $out[] = $clean;
    }
    return [$out, null];
}

// Build the analysis prompt. $prices maps part name => this yard's total price.
function buildAnalysisPrompt($vehicles, $partNames, $prices) {
    $lines = [];
    foreach ($vehicles as $i => $v) {
        $line = 'Car ' . $i . ': ' . $v['year'] . ' ' . $v['make'] . ' ' . $v['model'];
        $details = array_filter([$v['trim'], $v['engine'], $v['drive'], $v['transmission']], 'strlen');
        if ($details) $line .= ' (' . implode(', ', $details) . ')';
        if ($v['row'] !== '') $line .= ' Row ' . $v['row'];
        $lines[] = $line;
    }

    $list = [];
    foreach ($partNames as $n) {
        $list[] = $n . ' ($' . number_format(isset($prices[$n]) ? $prices[$n] : 50, 2, '.', '') . ')';
    }

    return 'You are a junkyard parts expert. For each vehicle pick the 10 most profitable parts to pull and sell on eBay.'
        . "\n\nVehicles:\n" . implode("\n", $lines)
        . "\n\nWhen a vehicle lists details in parentheses (trim, engine, drive, transmission), only pick parts this exact vehicle has: no turbocharger unless it says turbo, no transfer case unless it is 4WD or AWD, no hybrid parts unless it is a hybrid."
        . "\n\nYou MUST choose part names ONLY from this list — use the EXACT name and copy the lkqPrice shown into your JSON:\n"
        . implode(', ', $list)
        . "\n\nReturn ONLY a JSON array, no markdown. Copy lkqPrice exactly from the list above:\n"
        . '[{"carIndex":0,"parts":[{"name":"ECU / PCM","reason":"High demand, sells fast","ebayAvg":420,"ebayLow":280,"ebayHigh":600,"lkqPrice":92.09}]}]'
        . "\nExactly 10 parts per car. Prices in whole USD.";
}
