/* =====================================================================
   Captura de fotografías.

   Se comprimen en el propio móvil antes de guardarlas: una foto de 4 MB
   pasa a unos 200 KB. Así la cola offline no llena el almacenamiento y
   la subida por datos móviles es rápida.
   ===================================================================== */

import { mensaje } from '../ui.js';
import { nativo, fotoNativa } from '../nativo.js';

const LADO_MAXIMO = 1600;
const CALIDAD = 0.72;

/** Abre la cámara o la galería y devuelve [{datos, nombre}]. */
export async function elegirFotos({ camara = false, varias = true } = {}) {
  // En la aplicación de Android se usa la cámara del sistema: da mejor
  // calidad, corrige la orientación y no depende del selector de
  // archivos, que en algunos móviles es un desastre.
  if (nativo()) {
    const datos = await fotoNativa({ galeria: !camara });
    return datos ? [{ datos, nombre: 'foto.jpg' }] : [];
  }

  return new Promise((resolve) => {
    const entrada = document.createElement('input');
    entrada.type = 'file';
    entrada.accept = 'image/*';
    if (varias) entrada.multiple = true;
    if (camara) entrada.capture = 'environment';
    entrada.style.display = 'none';
    document.body.appendChild(entrada);

    let resuelto = false;
    const terminar = (v) => { if (!resuelto) { resuelto = true; entrada.remove(); resolve(v); } };

    entrada.onchange = async () => {
      const ficheros = Array.from(entrada.files || []);
      if (!ficheros.length) return terminar([]);

      const salida = [];
      for (const f of ficheros.slice(0, 8)) {
        try {
          salida.push({ datos: await comprimir(f), nombre: f.name || 'foto.jpg' });
        } catch (e) {
          console.warn(e);
          mensaje(`No se ha podido preparar «${f.name}».`, 'error');
        }
      }
      terminar(salida);
    };

    // Si el usuario cancela no salta ningún evento fiable: se libera al volver.
    window.addEventListener('focus', () => setTimeout(() => terminar([]), 900), { once: true });

    entrada.click();
  });
}

/** Reduce la imagen y la devuelve como data-url JPEG. */
export function comprimir(fichero) {
  return new Promise((resolve, reject) => {
    const lector = new FileReader();
    lector.onerror = () => reject(new Error('No se ha podido leer el archivo.'));
    lector.onload = () => {
      const img = new Image();
      img.onerror = () => reject(new Error('El archivo no es una imagen válida.'));
      img.onload = () => {
        let { width: w, height: h } = img;
        if (Math.max(w, h) > LADO_MAXIMO) {
          const e = LADO_MAXIMO / Math.max(w, h);
          w = Math.round(w * e);
          h = Math.round(h * e);
        }
        const lienzo = document.createElement('canvas');
        lienzo.width = w;
        lienzo.height = h;
        const ctx = lienzo.getContext('2d');
        ctx.drawImage(img, 0, 0, w, h);
        resolve(lienzo.toDataURL('image/jpeg', CALIDAD));
      };
      img.src = lector.result;
    };
    lector.readAsDataURL(fichero);
  });
}
