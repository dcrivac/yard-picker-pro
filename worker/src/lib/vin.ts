// Port of vin-lib.php. Pure helpers, no I/O.

export function vinNormalize(vin: unknown): string {
  return String(vin ?? '').replace(/[\s-]+/g, '').toUpperCase();
}

export function vinFormatOk(vin: string): boolean {
  return /^[A-HJ-NPR-Z0-9]{17}$/.test(vin);
}

const MAP: Record<string, number> = {
  A: 1, B: 2, C: 3, D: 4, E: 5, F: 6, G: 7, H: 8, J: 1, K: 2, L: 3, M: 4, N: 5, P: 7, R: 9,
  S: 2, T: 3, U: 4, V: 5, W: 6, X: 7, Y: 8, Z: 9,
};
const WEIGHTS = [8, 7, 6, 5, 4, 3, 2, 10, 0, 9, 8, 7, 6, 5, 4, 3, 2];

export function vinCheckDigitOk(vin: string): boolean {
  if (!vinFormatOk(vin)) return false;
  let sum = 0;
  for (let i = 0; i < 17; i++) {
    const c = vin[i];
    const v = c >= '0' && c <= '9' ? Number(c) : MAP[c];
    sum += v * WEIGHTS[i];
  }
  const r = sum % 11;
  return vin[8] === (r === 10 ? 'X' : String(r));
}

export interface Vehicle {
  vin: string; year: string; make: string; model: string; trim: string;
  engine: string; drive: string; transmission: string; body: string;
}

// PHP's round(x, 1) printed as a string drops a trailing ".0" (3.0 -> "3").
function phpRound1(n: number): string {
  return String(Math.round(n * 10) / 10);
}

export function vinVehicleFromVpic(vin: string, row: Record<string, unknown>): Vehicle | null {
  const g = (k: string) => (row[k] == null ? '' : String(row[k]).trim());
  const year = g('ModelYear'), make = g('Make'), model = g('Model');
  if (!/^\d{4}$/.test(year) || make === '' || model === '') return null;

  const engine: string[] = [];
  const disp = g('DisplacementL');
  if (disp !== '' && !Number.isNaN(Number(disp))) engine.push(phpRound1(Number(disp)) + 'L');
  if (g('EngineCylinders') !== '') engine.push(g('EngineCylinders') + '-cyl');
  if (g('Turbo').toLowerCase() === 'yes') engine.push('turbo');
  const elec = g('ElectrificationLevel').toUpperCase();
  if (elec.includes('PHEV')) engine.push('plug-in hybrid');
  else if (elec.includes('BEV')) engine.push('electric');
  else if (elec.includes('HEV')) engine.push('hybrid');
  else if (g('FuelTypePrimary') !== '' && !g('FuelTypePrimary').toLowerCase().includes('gasoline')) engine.push(g('FuelTypePrimary'));

  let drive = g('DriveType');
  if (drive.includes('/')) drive = drive.split('/')[0].trim();

  return {
    vin, year, make: make.toUpperCase(), model: model.toUpperCase(),
    trim: (g('Trim') + ' ' + g('Series')).trim(),
    engine: engine.join(' '), drive, transmission: g('TransmissionStyle'), body: g('BodyClass'),
  };
}
