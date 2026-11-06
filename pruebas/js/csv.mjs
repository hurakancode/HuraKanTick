/* Inyección de fórmulas en las exportaciones CSV (lista de partes, informes,
 * registro de seguridad). Se ejecuta la función DE VERDAD, sacada del
 * fichero tal cual: ui.js no se puede importar en node porque toca el DOM.
 *   node pruebas/js/csv.mjs www/assets/js/ui.js */
import fs from 'node:fs';
const src = fs.readFileSync(process.argv[2], 'utf8');
const ini = src.indexOf('const PARECE_FORMULA'); const fin = src.indexOf('\n}\n', src.indexOf('export function aCsv')) + 3;
const aCsv = new Function(src.slice(ini, fin).replace('export function aCsv', 'function aCsv') + '\nreturn aCsv;')();
const casos = [['=HYPERLINK("http://x";"Pulsa")'], ['+SUM(A1)'], ['-2+3'], ['@SUM(1)'], ['-3'], ['-2,5'], [-7], ['Grifo lavabo'], ['\t=1'], ['a;b']];
// Que ninguna fórmula salga viva y que lo normal no cambie.
const linea = (v) => aCsv(['x'], [[v]]).split('\r\n')[1];
const malos = [];
for (const v of ['=HYPERLINK("http://x";"Pulsa")', '+SUM(A1)', '-2+3', '@SUM(1)', '\t=1', '\r=1', '=1+1']) {
  const l = linea(v).replace(/^"/, '');
  if (!l.startsWith("'")) malos.push(`sale viva como fórmula: ${JSON.stringify(v)} → ${linea(v)}`);
}
for (const [v, esp] of [['-3', '-3'], ['-2,5', '-2,5'], [-7, '-7'], ['Grifo lavabo', 'Grifo lavabo'], ['a;b', '"a;b"']]) {
  if (linea(v) !== esp) malos.push(`cambia lo que no debe: ${JSON.stringify(v)} → ${linea(v)} (se esperaba ${esp})`);
}
if (malos.length) { console.log(malos.join('\n')); process.exit(1); }
console.log('aCsv neutraliza las fórmulas y deja los números y el texto como están.');
