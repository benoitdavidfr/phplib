<?php
/*PhpDoc:
name: sql.inc.php
title: sql.inc.php - classes Sql et PgSql utilisées pour exécuter des requêtes Sql
includes: [ mysql.inc.php ]
classes:
doc: |
  Simplification de l'utilisation de MySql et PgSql.
  La méthode statique Sql::open() prend en paramètre les paramètres MySql ou PgSql
  sous la forme "mysql://{user}:{passwd}@{host}/{database}"
  ou 'host=172.17.0.4 dbname=postgres user=postgres password=benoit'
  Voir utilisation en fin de fichier
journal: |
  26/4/2019 17:40
    création
*/
require_once __DIR__.'/mysql.inc.php';

class Sql {
  static $software = ''; // 'MySql' ou 'PgSql'
  
  static function open(string $params) {
    if (preg_match('!^mysql://([^:]+):([^@]+)@([^/]+)/(.*)$!', $params)) {
      self::$software = 'MySql';
    }
    elseif (preg_match('!^host=!', $params)) {
      self::$software = 'PgSql';
    }
    else
      throw new Exception("Erreur: dans Sql::open() params=\"$params\" incorrect");
    (self::$software)::open($params);
  }
  
  static function software(): string {
    if (!self::$software)
      throw new Exception('Erreur: dans Sql::software()');
    return self::$software;
  }
  
  static function server(): string {
    if (!self::$software)
      throw new Exception('Erreur: dans Sql::server()');
    return (self::$software)::server();
  }
  
  static function query(string $sql) {
    if (!self::$software)
      throw new Exception('Erreur: dans Sql::query()');
    return (self::$software)::query($sql);
  }
};

// classe implémentant en statique les méthodes de connexion et de requete
// et générant un objet correspondant à un itérateur permettant d'accéder au résultat
class PgSql implements Iterator {
  static $server; // le nom du serveur
  private $sql = null; // la requête conservée pour pouvoir faire plusieurs rewind
  private $result = null; // l'objet retourné par pg_query()
  private $first; // indique s'il s'agit du premier rewind
  private $id; // un no en séquence à partir de 1
  private $ctuple = false; // le tuple courant ou false
  
  static function open(string $connection_string) {
    if (!preg_match('!^host=([^ ]+) dbname=([^ ]+) user=([^ ]+) password=([^ ]+)$!', $connection_string, $matches))
      throw new Exception("Erreur: dans PgSql::open() params \"".$connection_string."\" incorrect");
    self::$server = $matches[1];
    if (!pg_connect($connection_string))
      throw new Exception('Could not connect: '.pg_last_error());
  }
  
  static function server(): string {
    if (!self::$server)
      throw new Exception("Erreur: dans PgSql::server() server non défini");
    return self::$server;
  }
  
  static function query(string $sql) {
    if (!($result = @pg_query($sql)))
      throw new Exception('Query failed: '.pg_last_error());
    return new PgSql($sql, $result);
  }

  function __construct(string $sql, $result) { $this->sql = $sql; $this->result = $result; $this->first = true; }
  
  function rewind(): void {
    if ($this->first) // la première fois ne pas faire de pg_query qui a déjà été fait
      $this->first = false;
    elseif (!($this->result = @pg_query($this->sql)))
      throw new Exception('Query failed: '.pg_last_error());
    $this->id = 0;
    $this->next();
  }
  
  function next(): void {
    $this->ctuple = pg_fetch_array($this->result, null, PGSQL_ASSOC);
    $this->id++;
  }
  
  function valid(): bool { return $this->ctuple <> false; }
  function current(): array { return $this->ctuple; }
  function key(): int { return $this->id; }
}


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;

if (0) { // Test MySql
  MySql::open('mysql://root:htpqrs28@172.17.0.3/route500');
  $sql = "select *
    from INFORMATION_SCHEMA.TABLES
    where table_schema<>'information_schema'
      and table_schema<>'mysql'";
  if (1) {  // Test 2 rewind 
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
}
elseif (0) { // Test PgSql
  PgSql::open('host=172.17.0.4 dbname=postgres user=postgres password=benoit'); 
  $sql = "select * from INFORMATION_SCHEMA.TABLES where table_schema='public'";
  foreach (PgSql::query($sql) as $tuple) {
    echo "tuple="; print_r($tuple);
  }
}
else { // Test Sql
  Sql::open('host=172.17.0.4 dbname=postgres user=postgres password=benoit'); 
  $sql = "select * from INFORMATION_SCHEMA.TABLES where table_schema='public'";
  foreach (Sql::query($sql) as $tuple) {
    echo "tuple="; print_r($tuple);
  }
}