<?php
/**
 * La contraseña de las cuentas de demostración. Una sola vez, aquí.
 *
 * Estaba escrita a mano en tres sitios del código —la demo, el
 * instalador y el texto que lo anuncia— y en una docena de pruebas.
 * Cambiarla obligaba a acordarse de todos, y el que se olvidara no
 * fallaba: sencillamente dejaba de comprobar.
 *
 * La leen:
 *   · tools/demo.php y tools/instalar.php, para crear las cuentas;
 *   · tools/diagnostico.php, para avisar si un administrador sigue
 *     entrando con ella —que es como entrar con la contraseña escrita en
 *     el manual—;
 *   · pruebas/entorno.py, para que la batería entre con la de verdad.
 *
 * Es pública por definición: va en el manual. Sirve para probar, nunca
 * para trabajar. Quien instale con --demo en el servidor del hotel tiene
 * que cambiarla en cuanto entre; el diagnóstico sale en rojo hasta que
 * lo haga.
 */
return 'Selenta2026+';
