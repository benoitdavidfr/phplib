<?php
/*PhpDoc:
name: sql.inc.php
title: sql.inc.php - classes Sql et PgSql utilisées pour exécuter des requêtes Sql
includes: [ mysql.inc.php ]
classes:
doc: |
  Simplification de l'utilisation de MySql et PgSql.
  La méthode statique Sql::open() prend en paramètre les paramètres MySql ou PgSql sous la forme:
    - "mysql://{user}:{passwd}@{host}/{database}" pour "mysql://{user}@{host}/{database}" pour MySql ou
    - "host={host} dbname={database} user={user} password={passwd}" ou
      "host={host} dbname={database} user={user}" pour PgSql
  Si le mot de passe n'est pas fourni alors il doit être défini dans le fichier secret.inc.php
journal: |
  24/5/2019:
    - correction de PgSql::query()
    - amélioration de Sql::query()
  23/5/2019:
    ajout possibilité de ne pas fournir de mot de passe à la connexion s'il est stocké dans le fichier secret.inc.php
  26/4/2019 17:40
    création
*/
require_once __DIR__.'/mysql.inc.php';

/*PhpDoc: classes
name: Sql
title: class Sql - classe Sql utilisée pour exécuter des requêtes Sql
methods:
*/
class Sql {
  static $software = ''; // 'MySql' ou 'PgSql'
  
  /*PhpDoc: methods
  name: sql.inc.php
  title: "static function open(string $params) - ouverture d'une connexion à un serveur de BD"
  doc: |
  */
  static function open(string $params) {
    if (strncmp($params, 'mysql://', 8) == 0)
      self::$software = 'MySql';
    elseif (strncmp($params, 'host=', 5) == 0)
      self::$software = 'PgSql';
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
  
  static function close(): void {
    if (!self::$software)
      throw new Exception('Erreur: dans Sql::close()');
    (self::$software)::close();
  }
  
  /*PhpDoc: methods
  name: sql.inc.php
  title: "static function query($sql) - éxécution d'une requête"
  doc: |
    La requête est soit une chaine soit une liste d'éléments,
    chacun étant une chaine ou un dictionnaire utilisant comme clé l'id du software.
    Cela permet d'écrire de manière relativement claire des requêtes dépendant du soft.
    Si la requête échoue alors renvoie une exception
    sinon si le résultat est un ensemble de n-uplets alors renvoie un objet MySql ou PgSql qui pourra être itéré
      pour obtenit chacun des n-uplets
    sinon renvoie TRUE
  */
  static function query($sql) {
    if (!self::$software)
      throw new Exception('Erreur: dans Sql::query()');
    if (is_string($sql))
      return (self::$software)::query($sql);
    elseif (is_array($sql)) {
      $sqlstr = '';
      foreach($sql as $sqlelt) // je balaye chaque élt de la requete
        // si l'élt est une chaine alors je l'utilise sinon j'en prends l'élément corr. au soft courant
        $sqlstr .= is_string($sqlelt) ? $sqlelt : ($sqlelt[self::$software] ?? '');
      return (self::$software)::query($sqlstr);
    }
    else
      throw new Exception('Erreur: dans Sql::query()');
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
    if (!preg_match('!^host=([^ ]+) dbname=([^ ]+) user=([^ ]+)( password=([^ ]+))?$!', $connection_string, $matches))
      throw new Exception("Erreur: dans PgSql::open() params \"".$connection_string."\" incorrect");
    $server = $matches[1];
    $database = $matches[2];
    $user = $matches[3];
    $passwd = $matches[4] ?? null;
    self::$server = $server;
    if (!$passwd) {
      if (!is_file(__DIR__.'/secret.inc.php'))
        throw new Exception("Erreur: dans PgSql::open($connection_string), fichier secret.inc.php absent");
      else {
        $secrets = require(__DIR__.'/secret.inc.php');
        $passwd = $secrets['sql']["pgsql://$user@$server/"] ?? null;
        if (!$passwd)
          throw new Exception("Erreur: dans PgSql::open($connection_string), mot de passe absent de secret.inc.php");
      }
      $connection_string .= " password=$passwd";
    }
    if (!pg_connect($connection_string))
      throw new Exception('Could not connect: '.pg_last_error());
  }
  
  static function server(): string {
    if (!self::$server)
      throw new Exception("Erreur: dans PgSql::server() server non défini");
    return self::$server;
  }
  
  static function close(): void { pg_close(); }
  
  static function query(string $sql) {
    if (!($result = @pg_query($sql)))
      throw new Exception('Query failed: '.pg_last_error());
    if ($result === TRUE)
      return TRUE;
    else
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


echo "<pre>";
if (0) { // Test MySql
  MySql::open('mysql://root@172.17.0.3/route500');
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
elseif (1) { // Test PgSql
  //PgSql::open('host=172.17.0.4 dbname=postgres user=postgres password=benoit'); 
  PgSql::open('host=172.17.0.4 dbname=postgres user=postgres'); 
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