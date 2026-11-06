# El certificado: por qué no es opcional

Sin HTTPS, HuraKan Tick funciona **a medias** y de una forma que engaña: desde el
propio servidor parece que todo va bien, y desde los móviles del hotel
faltan dos cosas centrales.

Comprobado, abriendo la aplicación por la IP de la red sin certificado:

```
isSecureContext:        false
navigator.serviceWorker: no existe
```

El navegador sólo entrega el *service worker* en `https` —o en
`localhost`, y de ahí la trampa—. Y de él cuelgan:

| Lo que se pierde | Por qué |
|---|---|
| **Los avisos al móvil** | No se puede ni pedir permiso. Nadie puede suscribirse. |
| **Trabajar sin cobertura** | La aplicación no se instala ni carga sin red. |
| **Las contraseñas** | Viajan en claro por la wifi del hotel. |

Los avisos siguen guardándose y se ven al abrir la aplicación. Lo que no
hay es que el móvil suene.

> La copia a Teams **sí funciona sin certificado**: la manda el servidor a
> Microsoft, no el navegador. Es el único canal de avisos que se puede
> tener en marcha mientras no haya certificado.

---

## Qué opción elegir

Hay tres, y para un hotel sólo una es sensata.

### 1. Certificado real con dominio propio — la recomendada

Funciona aunque el servidor esté en la red interna y **no** sea accesible
desde internet, porque la validación se hace por DNS, no visitando el
servidor.

Hace falta:

- Un dominio (vale un subdominio de uno que ya tengáis:
  `partes.miempresa.com`).
- Un registro DNS que apunte a la **IP interna** del servidor
  (`192.168.x.x`). Esto es legal y normal: el nombre es público, la IP es
  privada, así que sólo resuelve dentro del hotel.
- Acceso al panel de DNS para la validación.

Con **Caddy** se hace solo y se renueva solo. Fichero `Caddyfile`:

```caddy
partes.miempresa.com {
    # El reto va por DNS: el servidor no necesita estar abierto a internet.
    tls {
        dns <vuestro proveedor> <el token del proveedor>
    }

    root * /ruta/a/hurakantick
    php_fastcgi unix//run/php/php8.2-fpm.sock
    file_server
    encode gzip

    # El sitio arranca en www/
    redir / /www/ 302
}
```

Caddy pide el certificado a Let's Encrypt, lo renueva cada 60 días y no
hay que volver a tocarlo. Es la única opción que no genera trabajo
recurrente ni avisos de seguridad en los móviles.

### 2. Certificado propio del hotel (autofirmado) — sólo si no hay dominio

Funciona, pero **hay que instalar el certificado en los 50 aparatos**, uno
por uno. Y en Android reciente eso es cada vez más incómodo. Si no se
instala, el navegador avisa de sitio no seguro y —esto es lo importante—
**sigue sin dar service worker**, así que no arregla los avisos.

No lo recomiendo con 50 personas. Lo pongo porque es lo que suele
proponerse y conviene saber que no sirve para lo que hace falta.

### 3. Un proxy delante con el certificado

Si ya tenéis un servidor con certificado (una web, un portal), se puede
poner delante y que hable con HuraKan Tick por dentro. El efecto para los móviles
es el mismo que la opción 1. Vuestro informático sabrá si eso existe.

---

## Después de ponerlo

1. Enciende el ajuste: **Administración → Configuración → Seguridad →
   «Forzar HTTPS»**. Así una visita por `http` se redirige en vez de
   quedarse a medias.
2. Comprueba que está todo:

   ```bash
   php tools/diagnostico.php
   php tools/avisos.php
   ```

   El diagnóstico deja de marcar el http como FALLA.
3. Dile a la gente que active los avisos una vez, desde
   **Ajustes → Sonido, vibración y avisos**. Hasta que cada persona lo
   haga, su móvil no suena.
4. Para comprobar que llegan de verdad:

   ```bash
   php tools/avisos.php --probar <id de la persona>
   ```

---

## Lo que no hay que hacer

- **No cambies las claves de aviso** (`push_vapid_*`) una vez generadas.
  Si se cambian, las 50 suscripciones dejan de valer y hay que volver a
  pedir permiso en cada móvil.
- **No pongas el servidor abierto a internet** sólo para conseguir el
  certificado. La opción 1 no lo necesita.
