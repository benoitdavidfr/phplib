<?php
/*PhpDoc:
name: mysql.inc.php
title: mysql.inc.php - classes MySql et MySqlResult utilisées pour exécuter des requêtes MySql
classes:
doc: |
  Simplification de l'utilisation de MySql.
  La méthode statique MySql::open() prend en paramètre les paramètres MySql
  sous la forme mysql://{user}:{passwd}@{host}/{database}
  Voir utilisation en fin de fichier
journal: |
  26/4/2019:
    restructuration
  3/8/2018 15:00
    ajout MySql::server()
  3/8/2018
    création
*/

// la classe MySql implémente en statique une connexion
// Un objet est créé par résultat d'une requête pour itérer dessus
class MySql implements Iterator {
  static $mysqli=null; // handle MySQL
  static $server=null; // serveur MySql
  private $sql = ''; // la requête SQL pour pouvoir la rejouer
  private $result = null; // l'objet mysqli_result
  private $ctuple = null; // le tuple courant ou null
  private $first = true; // vrai ssi aucun rewind n'a été effectué
  
  // ouvre une connexion MySQL et enregistre le handle en variable statique
  // Il est nécessaire de passer en paramètre les paramètres MySQL
  static function open(string $mysqlParams): void {
    if (!preg_match('!^mysql://([^:]+):([^@]+)@([^/]+)/(.*)$!', $mysqlParams, $matches))
      throw new Exception("Erreur: dans MySql::open() params \"".$mysqlParams."\" incorrect");
    //print_r($matches);
    self::$mysqli = new mysqli($matches[3], $matches[1], $matches[2], $matches[4]);
    self::$server = $matches[3];
    // La ligne ci-dessous ne s'affiche pas correctement si le serveur est arrêté !!!
    //    throw new Exception("Connexion MySQL impossible pour $server_name : ".mysqli_connect_error());
    if (mysqli_connect_error())
      throw new Exception("Erreur: dans MySql::open() connexion MySQL impossible sur $mysqlParams");
    if (!self::$mysqli->set_charset ('utf8'))
      throw new Exception("Erreur: dans MySql::open() mysqli->set_charset() impossible : ".self::$mysqli->error);
  }
  
  static function server(): string {
    if (!self::$server)
      throw new Exception("Erreur: dans MySql::server() server non défini");
    return self::$server;
  }
  
  // exécute une requête MySQL, soulève une exception en cas d'erreur, renvoie le résultat
  // soit TRUE soit un itérateur
  static function query(string $sql) {
    if (!self::$mysqli)
      throw new Exception("Erreur: dans MySql::query() mysqli non défini");
    if (!($result = self::$mysqli->query($sql))) {
      //echo "sql:$sql\n";
      if (strlen($sql) > 1000)
        $sql = substr($sql, 0, 800)." ...";
      throw new Exception("Req. \"$sql\" invalide: ".self::$mysqli->error);
    }
    if ($result === TRUE)
      return TRUE;
    else
      return new MySql($sql, $result);
  }
  
  function __construct(string $sql, mysqli_result $result) {
    $this->sql = $sql;
    $this->result = $result;
    $this->first = true;
  }
  
  function rewind(): void {
    if ($this->first)
      $this->first = false;
    elseif (!($this->result = self::$mysqli->query($this->sql))) {
      if (strlen($this->sql) > 1000)
        $sql = substr($this->sql, 0, 800)." ...";
      throw new Exception("Req. \"$sql\" invalide: ".self::$mysqli->error);
    }
    $this->next();
  }
  
  function current(): array { return $this->ctuple; }
  function key(): int { return 0; }
  function next(): void { $this->ctuple = $this->result->fetch_array(MYSQLI_ASSOC); }
  function valid(): bool { return ($this->ctuple <> null); }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;

MySql::open('mysql://root:htpqrs28@172.17.0.3/route500');
$sql = "select *
from INFORMATION_SCHEMA.TABLES
where table_schema<>'information_schema' and table_schema<>'mysql'";
if (0) {  // Test 2 rewind 
  $result = MySql::query($sql);
  foreach ($result as $tuple) {
    print_r($tuple);
  }
  echo "relance\n";
  foreach ($result as $tuple) {
    print_r($tuple);
  }
}
else {
  foreach (MySql::query($sql) as $tuple) {
    print_r($tuple);
  }
} 
