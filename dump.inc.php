<?php
/*PhpDoc:
  name:  dump.inc.php
  title: dump.inc.php - function affichant la chaine en séparant chaque caractère et en affichant aussi le code hexa correspondant
  functions:
*/

/*PhpDoc: functions
  name:  dump
  title: function dump($string) - function affichant la chaine en séparant chaque caractère et en affichant aussi le code hexa correspondant
*/
function dump($string) {
  echo "<table border=1>";
  $line = 0;
  while($line*16 < strlen($string)) {
    echo "<tr><td>$line</td>";
    for ($i=0; $i<16; $i++)
      echo "<td>",substr($string,$line*16+$i,1),"</td>";
    echo "<td></td>";
    echo "<td></td>";
    echo "<td></td>";
    for ($i=0; $i<16; $i++)
      printf("<td>%0x</td>", ord(substr($string,$line*16+$i,1)));
    echo "</tr>\n";
    $line++;
  }
}
