<?php
/*PhpDoc:
name:  openmysql.inc.php
title: openmysql.inc.php - ouverture de la base MySQL - 22/8/2014 12:00
includes: [ srvr_name.inc.php ]
functions:
journal: |
  15/12/2018:
    sur localhost création de la base si elle n'existe pas
*/

/*PhpDoc: functions
  name: openMySQL
  title: function openMySQL($mysql_params) - ouverture de la base MySQL définie par $mysql_params
  parameters:
    - name: mysql_params
      type:
        "serveur web hébergeant l'application ($_SERVER['SERVER_NAME'])":
          server:    nom du serveur de la base de données
          user:      login dans la base de données
          passwd:    mot de passe associé au login
          database:  nom de la base de données       
  uses:
    - srvr_name.inc.php?server_name
  doc: |
    Lors de l'ouverture, les paramètres utilisés dépendent du serveur sur lequel s'exécute le script.
    Lors d'un fonctionnement avec Apache, la variable _SERVER[SERVER_NAME] indique le serveur d'exécution.
    En ligne de commandes (CLI SAPI), cette variable n'existe pas et le fichier 'srvr_name.inc.php' est utilisé pour définir
    le serveur d'exécution qui vaut localhost ou alwaysdata
*/
function openMySQL($mysql_params): mysqli {
//  echo "<pre>mysql_params="; print_r($mysql_params); echo "</pre>\n";
  if (php_sapi_name()<>'cli') {
//    echo "NOT CLI<br>\n";
	  $server_name = $_SERVER['SERVER_NAME'];
  } else {
//		echo "CLI<br>\n";
// lecture du nom du serveur dans un fichier spécifique au serveur
    require __DIR__.'/srvr_name.inc.php';
  }
	if (!isset($mysql_params[$server_name]))
    throw new Exception("Paramètres MySQL non définis pour $server_name");
//  echo "<pre>param="; print_r($param); echo "</pre>\n";
  $param = $mysql_params[$server_name];
  $mysqli = @new mysqli($param['server'], $param['user'], $param['passwd']);
  if (mysqli_connect_error())
    throw new Exception("Connexion MySQL impossible pour $server_name");
    // La ligne ci-dessous ne s'affiche pas correctement si le serveur est arrêté !!!
    // throw new Exception("Connexion MySQL impossible pour $server_name : ".mysqli_connect_error());
  if ($server_name == 'localhost') {
    $sql_cr_db = "CREATE DATABASE IF NOT EXISTS `$param[database]` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci";
    if (!($mysqli->query($sql_cr_db)))
      throw new Exception ("Requete \"".$sql_cr_db."\" invalide: ".$mysqli->error);
  }
  if (!$mysqli->set_charset ('utf8'))
    throw new Exception("mysqli->set_charset() impossible pour $server_name : ".$mysqli->error);
  if (!$mysqli->select_db($param['database']))
    throw new Exception ("select_db $param[database] invalide: ".$mysqli->error);
  return $mysqli;
}
