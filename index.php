<?php
/**
 * Redirección a la aplicación. Se conserva index.php en la raíz para
 * que las direcciones antiguas guardadas por el personal sigan valiendo.
 */
header('Location: www/index.html', true, 302);
echo '<!doctype html><meta charset="utf-8">'
   . '<title>HuraKan Tick</title>'
   . '<p>Abriendo la aplicación… <a href="www/index.html">Entrar</a></p>';
