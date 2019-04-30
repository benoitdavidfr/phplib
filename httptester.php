<?php
/*PhpDoc:
name:  httptester.php
title: httptester.php - serveur de test
doc: |
  Permet de simuler différentes situations pour tester le traitement dans httprequest:
  1) retour HTTP OK
  2) retour d'un code d'erreur HTTP
  3) pas de retour, délai très long
*/

if (!isset($_GET['test'])) {
  header('Content-type: text/plain');
  echo "Test OK\n";
}
elseif ($_GET['test']=='403') {
  header('HTTP/1.1 403 Forbidden');
  header('Content-type: text/plain');
  echo "FORBIDDEN\n";
}
elseif ($_GET['test']=='error') {
  header('HTTP/1.1 404 Not Found');
  header('Content-type: text/plain');
  echo "NOT FOUND\n";
}
elseif ($_GET['test']=='301') {
  header('HTTP/1.1 301 Moved Permanently');
  header("Location: http://bdavid.alwaysdata.net/");
  exit();
}
elseif ($_GET['test']=='302') {
  header("Location: http://bdavid.alwaysdata.net/");
  exit();
}
elseif ($_GET['test']=='sleep7s') {
  sleep(7); // la durée est définie à environ 2,5 fois la durée initiale 
  header('Content-type: text/plain');
  echo "Test OK apres 7 secondes d'endormissement\n";
}
else {
  header('Content-type: text/plain');
  echo "Test non prévu\n";
}
?>