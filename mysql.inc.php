<?php
/*PhpDoc:
name: mysql.inc.php
title: mysql.inc.php - classe MySql simplifiant l'utilisation de MySql
classes:
doc: |
  L'ouverture de la connexion s'effectue par la méthode statique MySql::open()
  L'exécution d'une requête Sql s'effectue au travers de la méthode statique MySql::query() qui:
    - génère une exception en cas d'erreur
    - pour les requêtes renvoyant un ensemble de n-uplets renvoie un objet MySql pouvant être itéré pour obtenir
      chacun des n-uplets
    - pour les autres requêtes renvoie TRUE
journal: |
  16/1/2021:
    - ajout MySql::database() et MySql::tableColumns()
    - dév. navigateur serveur / base / table / description / contenu
  23/5/2019:
    ajout possibilité de ne pas fournir le mot de passe
    ajout création de la base si elle n'existe pas sur localhost ou docker
  26/4/2019:
    restructuration
  3/8/2018 15:00
    ajout MySql::server()
  3/8/2018
    création
*/

/*PhpDoc: classes
name: MySql
title: class MySql implements Iterator - classe simplifiant l'utilisation de MySql
methods:
doc: |
  D'une part cette classe implémente en statique la connexion au serveur et l'exécution d'une requête Sql.
  D'autre part, pour les requêtes renvoyant un ensemble de n-uplets, un objet de la classe est créé
  qui peut être itéré pour obtenir les n-uplets.
*/
class MySql implements Iterator {
  static $mysqli=null; // handle MySQL
  static $server=null; // serveur MySql
  static $database=null; // éventuellement la base ouverte
  private $sql = ''; // la requête SQL pour pouvoir la rejouer
  private $result = null; // l'objet mysqli_result
  private $ctuple = null; // le tuple courant ou null
  private $first = true; // vrai ssi aucun rewind n'a été effectué
  
  static function open(string $params): void {
    /*PhpDoc: methods
    name: open
    title: "static function open(string $params): void - ouvre une connexion MySql"
    doc: |
      ouvre une connexion MySQL et enregistre le handle en variable statique
      Il est nécessaire de passer en paramètre les paramètres de connexion MySQL selon le motif:
        'mysql://{user}(:{password})?@{server}(/{dbname})?'
      S'ils ne contiennent pas le mot de passe alors ce dernier doit être présent dans le fichier secret.inc.php
      Si le nom de la base est défini alors elle est sélectionnée.
      Si la base est définie et n'existe pas sur localhost ou docker alors elle est créée.
    */
    if (!preg_match('!^mysql://([^@:]+)(:[^@])?@([^/]+)/(.*)$!', $params, $matches))
      throw new Exception("Erreur: dans MySql::open() params \"".$params."\" incorrect");
    //print_r($matches);
    $user = $matches[1];
    $passwd = $matches[2] ? substr($matches[2], 1) : null;
    $server = $matches[3];
    $database = $matches[4] ?? null;
    if ($passwd === null) {
      if (!is_file(__DIR__.'/secret.inc.php'))
        throw new Exception("Erreur: dans MySql::open($params), fichier secret.inc.php absent");
      $secrets = require(__DIR__.'/secret.inc.php');
      $passwd = $secrets['sql']["mysql://$user@$server/"] ?? null;
      if (!$passwd)
        throw new Exception("Erreur: dans MySql::open($params), mot de passe absent de secret.inc.php");
    }
    self::$mysqli = new mysqli($server, $user, $passwd);
    self::$server = $server;
    self::$database = $database;
    // La ligne ci-dessous ne s'affiche pas correctement si le serveur est arrêté !!!
    //    throw new Exception("Connexion MySQL impossible pour $server_name : ".mysqli_connect_error());
    if (mysqli_connect_error())
      throw new Exception("Erreur: dans MySql::open() connexion MySQL impossible sur $params");
    if ($database && (($server == 'localhost') || preg_match('!^172\.17\.0\.[0-9]$!', $server))) {
      $sql_cr_db = "CREATE DATABASE IF NOT EXISTS `$database` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci";
      if (!(self::$mysqli->query($sql_cr_db)))
        //throw new Exception ("Requete \"".$sql_cr_db."\" invalide: ".$mysqli->error);
        echo "Requete \"".$sql_cr_db."\" invalide: ".self::$mysqli->error."\n";
    }
    if (!self::$mysqli->set_charset ('utf8'))
      throw new Exception("Erreur: dans MySql::open() mysqli->set_charset() impossible : ".self::$mysqli->error);
    if ($database)
      if (!self::$mysqli->select_db($database))
        throw new Exception ("select_db($database) invalide: ".self::$mysqli->error);
  }
    
  static function close() { self::$mysqli->close(); }
  
  static function server(): string {
    if (!self::$server)
      throw new Exception("Erreur: dans MySql::server() server non défini");
    return self::$server;
  }
  
  static function database(): ?string {
    if (!self::$server)
      throw new Exception("Erreur: dans MySql::server() server non défini");
    return self::$database;
  }
  
  static function tableColumns(string $table, ?string $base=null): ?array {
    /*PhpDoc: methods
    name: tableColumns
    title: "static function tableColumns(string $table, ?string $base=null): ?array"
    doc: |
      Retourne la liste des colonnes d'une table structuré comme:
        [ [
            'ordinal_position'=> ordinal_position,
            'column_name'=> column_name,
            'COLUMN_COMMENT'=> COLUMN_COMMENT,
            'data_type'=> data_type,
            'character_maximum_length'=> character_maximum_length,
            'constraint_name'=> constraint_name,
        ] ]
      Les 5 premiers champs proviennent de la table INFORMATION_SCHEMA.columns et le dernier d'une jointure gauche
      avec INFORMATION_SCHEMA.key_column_usage
    */
    if (!$base)
      $base = self::$database;
    if (!$base)
      return [];
    
    $sql = "select c.ORDINAL_POSITION, c.column_name, c.COLUMN_COMMENT, c.DATA_TYPE, c.CHARACTER_MAXIMUM_LENGTH,
            k.CONSTRAINT_NAME
          from INFORMATION_SCHEMA.columns c
          left join INFORMATION_SCHEMA.key_column_usage k
            on k.table_schema=c.table_schema and k.table_name=c.table_name and k.column_name=c.column_name
              and constraint_name='PRIMARY'
        where c.table_schema='$base' and c.table_name='$table'";
    $columns = [];
    foreach(MySql::query($sql) as $tuple) {
      //print_r($tuple);
      $columns[$tuple['column_name']] = $tuple;
    }
    return $columns;
  }
  
  static function query(string $sql) { // exécute une requête MySQL, soulève une exception ou renvoie le résultat 
  /*PhpDoc: methods
  name: query
  title: "static function query(string $sql)- exécute une requête MySQL, soulève une exception ou renvoie le résultat"
  doc: |
    exécute une requête MySQL, soulève une exception en cas d'erreur, sinon renvoie le résultat soit TRUE
    soit un objet MySql qui peut être itéré pour obtenir chaque n-uplet
  */
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


if ((__FILE__ <> realpath($_SERVER['DOCUMENT_ROOT'].$_SERVER['SCRIPT_NAME'])) && (($argv[0] ?? '') <> basename(__FILE__))) return;
echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>mysql.inc.php</title></head><body><pre>\n";


if (0) {  // Test 2 rewind 
  MySql::open('mysql://root@172.17.0.3/');
  $sql = "select * from INFORMATION_SCHEMA.TABLES
  where table_schema<>'information_schema' and table_schema<>'mysql'";
  $result = MySql::query($sql);
  foreach ($result as $tuple) {
    print_r($tuple);
  }
  echo "relance\n";
  foreach ($result as $tuple) {
    print_r($tuple);
  }
}
else { // Navigation dans serveur / base / table / description / contenu
  if (!($server = $_GET['server'] ?? null)) { // les serveurs définis dans secret.inc.php
    $secrets = require(__DIR__.'/secret.inc.php');
    //print_r($secrets['sql']);
    echo "Servers:\n";
    foreach (array_keys($secrets['sql']) as $userServer) {
      if (substr($userServer, 0, 8) == 'mysql://')
        echo "  - <a href='?server=",urlencode(substr($userServer, 8)),"'>$userServer</a>\n";
    }
    die();
  }
  elseif (!($base = $_GET['base'] ?? null)) { // les bases du serveur
    echo "Bases de mysql://$server:\n";
    MySql::open("mysql://$server");
    $sql = "select distinct table_schema from INFORMATION_SCHEMA.TABLES";
    $url = "server=$server&amp;base";
    foreach (MySql::query($sql) as $tuple) {
      echo "  - <a href='?$url=$tuple[table_schema]'>$tuple[table_schema]</a>\n";
    }
    die();
  }
  elseif (!($table = $_GET['table'] ?? null)) { // les tables de la base
    echo "Tables de mysql:$server$base:\n";
    MySql::open("mysql://$server$base");
    $sql = "select table_name from INFORMATION_SCHEMA.TABLES where table_schema='$base'";
    $url = "server=".urlencode($server)."&amp;base=$base&amp;table";
    foreach (MySql::query($sql) as $tuple) {
      echo "  - <a href='?$url=$tuple[table_name]'>$tuple[table_name]</a>\n";
    }
    die();
  }
  elseif (null === ($offset = $_GET['offset'] ?? null)) { // Description de la table
    echo "Table mysql://$server$base/$table:\n";
    echo "  - <a href='?server=".urlencode($_GET['server'])."&amp;base=$base&amp;table=$table&amp;offset=0'>",
      "Affichage du contenu de la table</a>.\n";
    echo "  - Description de la table:\n";
    MySql::open("mysql://$server$base");
    $sql = "select c.ORDINAL_POSITION, c.column_name, c.COLUMN_COMMENT, c.DATA_TYPE, c.CHARACTER_MAXIMUM_LENGTH,
            k.CONSTRAINT_NAME
          from INFORMATION_SCHEMA.columns c
          left join INFORMATION_SCHEMA.key_column_usage k
            on k.table_schema=c.table_schema and k.table_name=c.table_name and k.column_name=c.column_name
              and constraint_name='PRIMARY'
        where c.table_schema='$base' and c.table_name='$table'";
    foreach (MySql::query($sql) as $tuple) {
      $primary_key = ($tuple['CONSTRAINT_NAME'] == 'PRIMARY') ? ' (primary key)' : '';
      echo "    $tuple[ORDINAL_POSITION]:\n";
      echo "      id: $tuple[column_name]$primary_key\n";
      echo $tuple['COLUMN_COMMENT'] ? "      description: $tuple[COLUMN_COMMENT]\n" : '';
      if ($tuple['DATA_TYPE']=='varchar')
        echo "      DATA_TYPE: $tuple[DATA_TYPE]($tuple[CHARACTER_MAXIMUM_LENGTH])\n";
      else
        echo "      DATA_TYPE: $tuple[DATA_TYPE]\n";
      if (0)
        print_r($tuple);
    }
    die();
  }
  else { // affichage du contenu de la table à partir de offset
    $limit = 20;
    MySql::open("mysql://$server$base");
    $columns = [];
    foreach (MySql::tableColumns($table) as $cname => $column) {
      if ($column['DATA_TYPE']=='geometry')
        $columns[] = "ST_AsGeoJSON($cname) $cname";
      else
        $columns[] = $cname;
    }
    $url = "server=".urlencode($_GET['server'])."&amp;base=$base&amp;table=$table";
    echo "</pre>",
      "<h2>mysql://$server$base/$table</h2>\n",
      "<a href='?$url'>^</a> ",
      ((($offset-$limit) >= 0) ? "<a href='?offset=".($offset-$limit)."&amp;$url'>&lt;</a>" : ''),
      " offset=$offset ",
      "<a href='?offset=".($offset+$limit)."&amp;$url'>&gt;</a>",
      "<table border=1>\n";
    echo "</pre><table border=1>\n";
    $sql = "select ".implode(', ', $columns)." from $table limit $limit offset $offset";
    $no = 0;
    foreach (MySql::query($sql) as $tuple) {
      if (!$no++)
        echo '<th>', implode('</th><th>', array_keys($tuple)),"</th>\n";
      echo '<tr><td>', implode('</td><td>', $tuple),"</td></tr>\n";
    }
    echo "</table>\n";
    die();
  }
  
  die();
}
/*
-- Tables de test - MySql - base test
drop table if exists unchampstretunegeom;
CREATE TABLE `unchampstretunegeom` (
  `champstr` VARCHAR(80) NOT NULL ,
  `geom` GEOMETRY NOT NULL )
ENGINE = InnoDB
COMMENT = 'table de tests';

insert into unchampstretunegeom(champstr, geom) values
('une valeur pour le champ', ST_GeomFromText('POINT(1 1)'));

drop table if exists deuxchampstret2geom;
CREATE TABLE `deuxchampstret2geom` (
  id int not null auto_increment primary key,
  `champstr` VARCHAR(80) NOT NULL ,
  `geom1` GEOMETRY NOT NULL,
  `geom2` GEOMETRY NOT NULL )
ENGINE = InnoDB
COMMENT = 'table de tests';

insert into deuxchampstret2geom(champstr,geom1,geom2) values
('une valeur pour le champ', ST_GeomFromText('POINT(1 1)'), ST_GeomFromText('POINT(1 1)'));

drop table if exists unchampjsonetunegeom;
CREATE TABLE `unchampjsonetunegeom` (
  `json` JSON NOT NULL ,
  `geom` GEOMETRY NOT NULL );

insert into unchampjsonetunegeom(json, geom) values
('{"a": "b"}', ST_GeomFromText('POINT(1 1)'));

drop table if exists unchampstretpasdegeom;
CREATE TABLE `unchampstretpasdegeom` (
  id int not null auto_increment primary key,
  `champstr` VARCHAR(80) NOT NULL);

insert into unchampstretpasdegeom(champstr) values
('une valeur pour le champ');
*/
