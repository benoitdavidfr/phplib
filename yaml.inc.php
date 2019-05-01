<?php
/*PhpDoc:
name: yaml.inc.php
title: yaml.inc.php - lecture d'un fichier YAML
includes: [ ../spyc/spyc2.inc.php ]
functions:
doc: |
  Les <a href='http://php.net/manual/fr/book.yaml.php'>fonctions yaml_parse() et yaml_emit() sont définies par Php mais sont définies dans une extension</a>.
  Lorsque les fonctions yaml_parse() et yaml_emit() ne sont pas definies alors le module Spyc est utilisé
journal: |
  26/3/2017
    utilisation de spycLoad()
  8/11/2016
    utilisation de la signature de yaml_parse() et de yaml_emit() fournie dans le manuel
  1/11/2016
    ajout de yaml_emit()
*/
/*PhpDoc: functions
name: yaml_parse
title: function yaml_parse($string) - analyse un text YAML et retourne un tableau
doc: |
  Si la fonction yaml_parse() n'est pas déjà définie alors le module Spyc est utilisé
*/
if (!function_exists('yaml_parse')) {
  require_once __DIR__.'/../spyc/spyc2.inc.php';
  function yaml_parse($input, $pos=0, &$ndocs=null, $callbacks=null) {
    return spycLoad($input);
  }
}

/*PhpDoc: functions
name: yaml_emit
title: function yaml_emit($string) - Retourne une chaîne représentant une valeur YAML
doc: |
  Si la fonction yaml_emit() n'est pas déjà définie alors le module Spyc est utilisé
*/
if (!function_exists('yaml_emit')) {
  require_once __DIR__.'/../spyc/spyc2.inc.php';
  function yaml_emit($data, $encoding=YAML_ANY_ENCODING, $linebreak=YAML_ANY_BREAK, $callbacks=null) {
    return Spyc::YAMLDump($data, 2, 80, true);
  }
}
