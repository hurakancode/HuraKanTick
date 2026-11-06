# La copia de los avisos a Microsoft Teams

Cada aviso de HuraKan Tick llega también al **chat privado** de la persona en
Teams, con un botón que abre el parte.

**Es de ida, no de vuelta.** Lo que alguien conteste en Teams no entra en
el parte, y la propia tarjeta lo dice. Esto es a propósito: con dos sitios
donde está la conversación, ninguno sería el oficial, y para una
inspección el registro tiene que ser el parte.

Funciona **sin HTTPS**, porque el mensaje lo manda el servidor a
Microsoft, no el navegador. Es el único canal de avisos que puede estar en
marcha antes de tener el certificado.

---

## Lo que hay que hacer en Azure y en Teams

Esto lo hace quien administre el Microsoft 365 de la empresa. Son seis
pasos y hay que apuntar tres valores por el camino.

### 1. Registrar la aplicación

Portal de Azure → **Microsoft Entra ID** → *Registros de aplicaciones* →
**Nuevo registro**.

- Nombre: `HuraKan Tick`
- Tipos de cuenta: *Sólo cuentas de este directorio organizativo*
- URI de redirección: déjalo vacío

Al terminar, apunta de la página de información general:

| Lo que pone Azure | Dónde va en HuraKan Tick |
|---|---|
| **Id. de aplicación (cliente)** | Identificador de la aplicación |
| **Id. de directorio (inquilino)** | Identificador del tenant |

### 2. El secreto

En el mismo registro → *Certificados y secretos* → **Nuevo secreto de
cliente**.

- Descripción: `HuraKan Tick`
- Caducidad: la que marque vuestra política

Copia el **Valor** en ese momento: después Azure ya no lo vuelve a
enseñar. Va en «Secreto de la aplicación».

> **Apunta la fecha de caducidad en el calendario.** El día que caduque,
> los avisos a Teams dejan de salir sin más. HuraKan Tick lo apuntará en
> Administración → Errores, pero nadie mira eso a diario.

### 3. Permiso para buscar a la gente

HuraKan Tick necesita traducir el correo de cada persona a su identificador de
Azure. En el registro → *Permisos de API* → **Agregar un permiso** →
*Microsoft Graph* → **Permisos de aplicación** → `User.Read.All`.

Después, **Conceder consentimiento del administrador**. Sin ese botón
pulsado el permiso no sirve.

### 4. El bot

Portal de Azure → **Crear un recurso** → *Azure Bot*.

- Tipo de aplicación: el mismo que elegiste en el paso 1
  (*Un solo inquilino*)
- Id. de aplicación: **reutiliza el del paso 1**, no crees otro

Cuando exista: *Canales* → añade **Microsoft Teams**.

No hace falta poner ningún «endpoint de mensajería»: HuraKan Tick no recibe
mensajes, sólo los manda.

### 5. La aplicación de Teams

Hace falta un paquete con dos iconos y un `manifest.json`. Éste sirve tal
cual, cambiando los dos sitios donde dice `EL-ID-DE-LA-APLICACION`:

```json
{
  "$schema": "https://developer.microsoft.com/en-us/json-schemas/teams/v1.16/MicrosoftTeams.schema.json",
  "manifestVersion": "1.16",
  "version": "1.0.0",
  "id": "EL-ID-DE-LA-APLICACION",
  "developer": {
    "name": "Hotel Benalma",
    "websiteUrl": "https://partes.miempresa.com",
    "privacyUrl": "https://partes.miempresa.com",
    "termsOfUseUrl": "https://partes.miempresa.com"
  },
  "name": { "short": "HuraKan Tick", "full": "HuraKan Tick · Ticketing" },
  "description": {
    "short": "Avisos de los partes de trabajo",
    "full": "Copia de los avisos de HuraKan Tick. Para responder, entra en el parte."
  },
  "icons": { "color": "color.png", "outline": "outline.png" },
  "accentColor": "#0B5563",
  "bots": [
    {
      "botId": "EL-ID-DE-LA-APLICACION",
      "scopes": ["personal"],
      "isNotificationOnly": true,
      "supportsFiles": false
    }
  ],
  "permissions": ["identity"],
  "validDomains": []
}
```

`isNotificationOnly` es importante: le dice a Teams que esto sólo avisa,
así que no ofrece una caja de texto que no lleva a ningún sitio.

Los iconos: `color.png` de 192×192 y `outline.png` de 32×32, blanco y
transparente. Mete los tres ficheros en un ZIP **sin carpeta dentro**.

Centro de administración de Teams → *Aplicaciones de Teams* →
**Administrar aplicaciones** → *Cargar nueva aplicación*, y sube el ZIP.

### 6. Instalarla para la gente — el paso que se olvida

Un bot no puede escribir a alguien que no lo tiene instalado. Si os salta
esto, es esto.

Centro de administración de Teams → *Aplicaciones de Teams* →
**Directivas de configuración** → `Global` (o una nueva para el personal
del hotel) → *Aplicaciones instaladas* → **Agregar aplicaciones** → HuraKan Tick
→ guardar.

La directiva tarda en aplicarse: Microsoft dice hasta 24 horas, en la
práctica suele ser bastante menos.

---

## Lo que hay que rellenar en HuraKan Tick

**Administración → Configuración → Copia a Microsoft Teams**:

| Ajuste | Qué poner |
|---|---|
| Identificador del tenant | el *Id. de directorio* del paso 1 |
| Identificador de la aplicación | el *Id. de aplicación* del paso 1 |
| Secreto de la aplicación | el **Valor** del paso 2 |
| Dirección de HuraKan Tick para los enlaces | con la que entra la gente, p. ej. `https://partes.miempresa.com` |
| Mandar copia de los avisos a Teams | enciéndelo **al final** |
| Sólo los urgentes | si no quieres llenarle el Teams a nadie |

El secreto no se le enseña a nadie que no sea administrador, ni siquiera
leyendo la configuración por la API.

---

## Comprobar que va

**Administración → Comprobar la copia a Teams.** Va paso a paso y dice en
cuál se rompe:

| Lo que falla | Lo que significa |
|---|---|
| La configuración está completa | falta rellenar algo de la tabla de arriba |
| Azure da el testigo | el secreto está mal o **ha caducado** (lo más habitual) |
| Azure da el testigo de Graph | falta `User.Read.All`, o el consentimiento sin conceder (paso 3) |
| Microsoft reconoce a esa persona | el correo que tiene en HuraKan Tick no es el de su cuenta de Microsoft |
| Se puede abrir su chat | **la aplicación no está instalada para ella** → paso 6 |
| Llega el mensaje | lo rechazó Teams; el detalle lo dice |

Con los tres primeros en verde, pulsa **Probar con una persona**: es el
único modo de saber si el paso 6 está hecho.

---

## Cómo se comporta por dentro

- Cada aviso se apunta en una **bandeja de salida** y se manda después.
  Guardar un parte nunca espera a Microsoft: si Microsoft tarda o no
  contesta, el parte se guarda igual.
- Se manda en tandas de 15, lo urgente primero, cuando alguien abre la
  pantalla de inicio. No hace falta cron.
- Si falla, se **reintenta cuatro veces**. A la cuarta se da por perdido y
  queda apuntado en Administración → Errores con el motivo.
- Queda el rastro de qué se mandó y qué no, que es lo que hace falta
  cuando alguien dice «a mí no me llegó».

## Si alguien no lo quiere

Es por persona: en su ficha, **«Mandarle copia de los avisos a Teams»**.
Quien no tenga correo en HuraKan Tick queda fuera automáticamente, porque sin
correo no se le puede encontrar en Azure.
