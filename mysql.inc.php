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

  Le script implémente comme test un navigateur dans les serveurs définis dans secret.inc.php
  Pour des raisons de sécurité ces fonctionnalités ne sont disponibles que sur le serveur localhost
  
  Sur MySql, il n'existe qu'un seul catalogue (au sens information_schema) par serveur.
  Le schema information_schema contient notamment les tables:
    - schemata - liste des schema, cad des bases du serveur
    - tables - liste des tables
    - columns - liste des colonnes avec notamment
      - la colonne COLUMN_KEY qui vaut
        - PRI si la colonne est une clé primaire
        - UNI si un index unique a été créé
        - MUL si un index non unique ou spatial a été créé
      Si un index contient plusieurs colonnes alors seule la première est indiquée.
      Je n'ai pas trouvé de moyen d'identfier les indexs non uniques muti-colonnes
    - key_column_usage - liste les clés primaires et index uniques avec semble t'il les colonnes qui y participent

  On peut définir un usage simplifié avec uniquement des index et clés primaines mono-attributs.

  Différences entre les différentes versions et flavors:
    MySql v5 (ancien serveur Docker):
      - noms des champs de information_schema en minuscules
      - fonction REGEXP_SUBSTR() et REGEXP_REPLACE() NON définies
    MariaDB v5 (serveur Alwaysadata):
      - noms des champs de information_schema en minuscules
      - fonction REGEXP_SUBSTR() et REGEXP_REPLACE() définies, paramètres identifiés par \\1, \\2, ...
    MySql v8 (nouveau serveur Docker)
      - noms des champs de information_schema en majuscules
      - fonction REGEXP_SUBSTR() et REGEXP_REPLACE() définies, paramètres idétenfiés par $1, $2, ...

  A faire:
    - faire marcher spatial_extension
journal: |
  6/2/2021:
    - ajout à MySql::query() de l'option 'jsonColumns' indiquant les colonnes à json_décoder
  30/1/2021:
    - amélioration de spatial_extent pour qu'il fonctionne sur MySql 8
  29/1/2021:
    - chgt de version du serveur MySql @Docker, passage en 8 pour disposer de l'extension spatiale
    - nécessité de gérer la géométrie en SRID 0 et pas en SRID 4326
    - ajout de la méthode server_info() pour distinguer les différentes versions et MariaDB de MySql
    - ajout de l'option 'columnNamesInLowercase' pour gérer les écarts entre MySql 5 et 8 sur information_schema
  28/1/2021:
    - chgt des URI
      serveur: mysql://{user}(:{password})?@{server}    sans '/' à la fin
      base:    mysql://{user}(:{password})?@{server}/{dbname}
    - méthode de calcul de l'extension spatiale d'une colonne géométrique
      + pour éviter d'avoir à la recalculer stockage dans une table spatial_extent dans la base courante
  24/1/2021:
    - évol. navigateur avec possibilité de définir une requête sql
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
includes:
  - secret.inc.php
*/

/*PhpDoc: classes
name: MySql
title: class MySql implements Iterator - classe simplifiant l'utilisation de MySql
methods:
doc: |
  D'une part cette classe implémente en statique la connexion au serveur et l'exécution d'une requête Sql.
  D'autre part, pour les requêtes renvoyant un ensemble de n-uplets, un objet de la classe est créé
  qui peut être itéré pour obtenir les n-uplets.
  Plus méthode de calcul de l'extension spatiale d'une colonne géométrique
  et stockage dans une table spatial_extent pour éviter d'avoir à la recalculer.
*/
class MySql implements Iterator {
  const OGC_GEOM_TYPES = ['geometry','point','multipoint','linestring','multilinestring','polygon','multipolygon'];

  static $mysqli=null; // handle MySQL
  static string $server; // serveur MySql
  static ?string $database; // éventuellement la base ouverte
  
  private string $sql = ''; // la requête SQL pour pouvoir la rejouer
  private $result = null; // l'objet mysqli_result
  private array $options = []; // options: ['columnNamesInLowercase'=>bool]
  private ?array $ctuple = null; // le tuple courant ou null
  private bool $first = true; // vrai ssi aucun rewind n'a été effectué
  
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
      Sur localhost ou docker, si la base est définie et n'existe pas alors elle est créée.
    */
    if (!preg_match('!^mysql://([^@:]+)(:[^@])?@([^/]+)(/.*)?$!', $params, $matches))
      throw new Exception("Erreur: dans MySql::open() params \"".$params."\" incorrect");
    //print_r($matches);
    $user = $matches[1];
    $passwd = $matches[2] ? substr($matches[2], 1) : null;
    $server = $matches[3];
    $database = isset($matches[4]) ? substr($matches[4], 1) : null;
    if ($passwd === null) {
      if (!is_file(__DIR__.'/secret.inc.php'))
        throw new Exception("Erreur: dans MySql::open($params), fichier secret.inc.php absent");
      $secrets = require(__DIR__.'/secret.inc.php');
      $passwd = $secrets['sql']["mysql://$user@$server"] ?? null;
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
    if ($database && (($server == 'localhost') || preg_match('!^172\.17\.0\.[0-9]$!', $server))
      && ($database <> 'information_schema')) {
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
  
  static function server_info(): string {
    if (!self::$server)
      throw new Exception("Erreur: dans MySql::server_info() server non défini");
    return self::$mysqli->server_info;
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
      Les 5 premiers champs proviennent de la table information_schema.columns et le dernier d'une jointure gauche
      avec information_schema.key_column_usage.
      Le paramètre $base peut être omis s'il a été défini à l'ouverture.
    */
    if (!$base)
      $base = self::$database;
    if (!$base)
      return [];
    
    $sql = "select c.ORDINAL_POSITION, c.column_name, c.COLUMN_COMMENT, c.data_type, c.character_maximum_length,
            k.CONSTRAINT_NAME
          from information_schema.columns c
          left join information_schema.key_column_usage k
            on k.table_schema=c.table_schema and k.table_name=c.table_name and k.column_name=c.column_name
              and constraint_name='PRIMARY'
        where c.table_schema='$base' and c.table_name='$table'";
    $columns = [];
    foreach(MySql::query($sql, ['columnNamesInLowercase'=> true]) as $tuple) {
      //print_r($tuple);
      $columns[$tuple['column_name']] = $tuple;
    }
    return $columns;
  }
  
  // exécute une requête MySQL, soulève une exception ou renvoie le résultat
  static function query(string $sql, array $options=[]) { 
    /*PhpDoc: methods
    name: query
    title: "static function query(string $sql, array $options=[])- exécute une requête MySQL"
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
      return new MySql($sql, $result, $options);
  }
  
  static function getTuples(string $sql): array { // renvoie le résultat d'une requête sous la forme d'un array
    /*PhpDoc: methods
    name: getTuples
    title: "static function getTuples(string $sql): array - renvoie le résultat d'une requête sous la forme d'un array"
    doc: |
      Plus adapté que query() quand on sait que le nombre de n-uplets retournés est faible
    */
    $tuples = [];
    foreach (self::query($sql) as $tuple)
      $tuples[] = $tuple;
    return $tuples;
  }
  
  // Calcul de l'extension spatiale d'une colonne géométrique, retourne [lonmin, latmin, lonmax, latmax]
  static function spatialExtent(string $tableName, string $c): array {
    /*PhpDoc: methods
    name: spatialExtent
    title: "static function spatialExtent(string $tableName, string $c): array - extension spatiale d'une colonne"
    doc: |
      Calcul de l'extension spatiale d'une colonne géométrique, retourne [lonmin, latmin, lonmax, latmax]
      Stocke le résultat dans la table mysql_spatial_extent créée si elle n'existe pas.
      Code complexe du à l'absence de la fonction ST_Extent() présente sur PostGis.
      Utilise la fonction ST_Envelope() sur chaque n-uplet puis une extraction des coordonnées par REGEXP
      et enfin un agrégat sur la table.
      ST_Envelope() ne donnant pas le même résultat sur MariaDB et MySql, le code est différent pour les 2.
    */
    try { // essaie de retrouver le résultat dans la table spatial_extent
      $sql = "select ST_AsText(geom) geom from mysql_spatial_extent\n".
        "where table_name='$tableName' and column_name='$c'";
      $tuples = MySql::getTuples($sql);
      // 3 possibilités
      //   1) la table n'existe pas et il y a une exception
      //   2) la table existe mais ce tuple n'existe pas alors exécution du code après le catch
      //   3) la table existe et ce tuple existe alors le code suivant est exécuté
      if (count($tuples) == 1) {  // cas 3
        $tuple = $tuples[0];
        //echo "$tuple[geom]\n";
        static $pattern = '!^POLYGON\(\(([-\d.]+) ([-\d.]+),[^,]+,([-\d.]+) ([-\d.]+),[^,]+,[^)]+\)\)$!';
        if (!preg_match($pattern, $tuple['geom'], $matches))
          throw new Exception("No match dans MySql::spatialExtent() sur $tuple[geom]");
        return [(float)$matches[1], (float)$matches[2], (float)$matches[3], (float)$matches[4]];
      }
    }
    catch (Exception $e) { // cas 1: Si la table n'existe pas alors elle est créée
      $sql = "create table mysql_spatial_extent(
        id int not null auto_increment primary key comment 'identifiant automatique',
        table_name varchar(130) not null comment 'nom de la table',
        column_name varchar(130) not null comment 'nom de la colonne géométrique',
        geom polygon not null comment 'polygone constituant l''extension de la colonne',
        unique names (table_name, column_name)
      )
      comment='title: Extensions spatiales des autres tables de la base pour accélérer les requêtes  
creator: /phplib/mysql.inc.php#/MySql/spatialExtent  
created: ".date(DATE_ATOM)."'";
      Mysql::query($sql);
    }
    // cas 2: calcul de l'extension spatiale
    // Le code n'est pas le même avec MariaDB et MySql 8 (il ne fonctionne pas du tout avec MySql 5)
    if (preg_match('!MariaDB!', MySql::server_info())) // Serveur 5.5.5-10.4.17-MariaDB
      $param = '\\\\'; // les paramètres sont identifiés par '\\\\1'
    else // MySql 8
      $param = '$'; // les paramètres sont identifiés par '$1'
    try { // code avec agrégat par forme géom. - code correct sur '5.5.5-10.4.17-MariaDB' et MySql 8.0.23
      if (preg_match('!MariaDB!', MySql::server_info())) { // Serveur MariaDB
        $mariaDB = true;
        $param = '\\\\'; // les paramètres sont identifiés par '\\\\1'
      }
      else { // MySql 8
        $mariaDB = false;
        $param = '$'; // les paramètres sont identifiés par '$1'
      }
      { // Première partie, requête sur POLYGON, code commun MariaDB / MySql 8
        $sql = "
          select count($c) count,
            min(REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^POLYGON..([-0-9.]+) ([-0-9.]+),[-0-9. ]+,[-0-9. ]+,[-0-9. ]+,[-0-9. ]+..$',
              '${param}1')+0) xmin,
            min(REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^POLYGON..([-0-9.]+) ([-0-9.]+),[-0-9. ]+,[-0-9. ]+,[-0-9. ]+,[-0-9. ]+..$',
              '${param}2')+0) ymin,
            max(REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^POLYGON..[-0-9. ]+,[-0-9. ]+,([-0-9.]+) ([-0-9.]+),[-0-9. ]+,[-0-9. ]+..$',
              '${param}1')+0) xmax,
            max(REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^POLYGON..[-0-9. ]+,[-0-9. ]+,([-0-9.]+) ([-0-9.]+),[-0-9. ]+,[-0-9. ]+..$',
              '${param}2')+0) ymax
          from $tableName
        "
         .(!$mariaDB ? "where ST_AsText(ST_Envelope($c)) REGEXP '^POLYGON'\n" : ''); 
        $tuple = MySql::getTuples($sql)[0];
        $extent = [];
        if ($tuple['count']) {
          $extent = [(float)$tuple['xmin'], (float)$tuple['ymin'], (float)$tuple['xmax'], (float)$tuple['ymax']];
        }
      }
      if ($mariaDB) {
        $wkt = "POLYGON(($extent[0] $extent[1],$extent[0] $extent[3],$extent[2] $extent[3],"
          ."$extent[2] $extent[1],$extent[0] $extent[1]))";
        $sql = "insert into mysql_spatial_extent(table_name, column_name, geom)\n"
          ."values ('$tableName', '$c',  ST_GeomFromText('$wkt'))";
        MySql::query($sql);
        return $extent;
      }
      // En MySql 8 ST_Envelope() peut donner un LINESTRING ou un POINT
      { // Deuxième partie, requête sur LINESTRING, uniquement MySql 8
        $sql = "
          select count($c) count,
            min(REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^LINESTRING.([-0-9.]+) ([-0-9.]+),[-0-9. ]+.$',
              '${param}1')+0) x1min,
            min(REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^LINESTRING.([-0-9.]+) ([-0-9.]+),[-0-9. ]+.$',
              '${param}2')+0) y1min,
            min(REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^LINESTRING.[-0-9. ]+,([-0-9.]+) ([-0-9.]+).$',
              '${param}1')+0) x2min,
            min(REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^LINESTRING.[-0-9. ]+,([-0-9.]+) ([-0-9.]+).$',
              '${param}2')+0) y2min,
            max(REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^LINESTRING.([-0-9.]+) ([-0-9.]+),[-0-9. ]+.$',
              '${param}1')+0) x1max,
            max(REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^LINESTRING.([-0-9.]+) ([-0-9.]+),[-0-9. ]+.$',
              '${param}2')+0) y1max,
            max(REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^LINESTRING.[-0-9. ]+,([-0-9.]+) ([-0-9.]+).$',
              '${param}1')+0) x2max,
            max(REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^LINESTRING.[-0-9. ]+,([-0-9.]+) ([-0-9.]+).$',
              '${param}2')+0) y2max
          from $tableName
          where ST_AsText(ST_Envelope($c)) REGEXP '^LINESTRING'
        ";
        $tuple = MySql::getTuples($sql)[0];
        if ($tuple['count']) {
          if ($extent) { // combinaison des 2 extents
            $extent[0] = min($extent[0], $tuple['x1min'], $tuple['x2min']);
            $extent[1] = min($extent[1], $tuple['y1min'], $tuple['y2min']);
            $extent[2] = max($extent[2], $tuple['x1max'], $tuple['x2max']);
            $extent[3] = max($extent[3], $tuple['y1max'], $tuple['y2max']);
          }
          else { // utilisation de ce 2nd extent
            $extent = [
              min($tuple['x1min'], $tuple['x2min']),
              min($tuple['y1min'], $tuple['y2min']),
              max($tuple['x1max'], $tuple['x2max']),
              max($tuple['y1max'], $tuple['y2max']),
            ];
          }
        }
      }
      { // Troisième partie, requête sur POINT, uniquement MySql 8
        $sql = "
          select count($c) count,
            min(REGEXP_REPLACE(
              ST_AsText($c),
              '^POINT.([-0-9.]+) ([-0-9.]+).$',
              '${param}1')+0) xmin,
            min(REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^POINT.([-0-9.]+) ([-0-9.]+).$',
              '${param}2')+0) ymin,
            max(REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^POINT.([-0-9.]+) ([-0-9.]+).$',
              '${param}1')+0) xmax,
            max(REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^POINT.([-0-9.]+) ([-0-9.]+).$',
              '${param}2')+0) ymax
          from $tableName
          where ST_AsText(ST_Envelope($c)) REGEXP '^POINT'
        ";
        $tuple = MySql::getTuples($sql)[0];
        if ($tuple['count']) {
          //echo "POINT> $tuple[xmin]; $tuple[ymin]; $tuple[xmax]; $tuple[ymax];\n";
          if ($extent) { // combinaison avec l'extent précédent 
            $extent[0] = min($extent[0], $tuple['xmin']);
            $extent[1] = min($extent[1], $tuple['ymin']);
            $extent[2] = max($extent[2], $tuple['xmax']);
            $extent[3] = max($extent[3], $tuple['ymax']);
            echo 'extent='; print_r($extent);
          }
          else
            $extent = [(float)$tuple['xmin'], (float)$tuple['ymin'], (float)$tuple['xmax'], (float)$tuple['ymax']];
        }
      }
      $wkt = "POLYGON(($extent[0] $extent[1],$extent[0] $extent[3],$extent[2] $extent[3],"
        ."$extent[2] $extent[1],$extent[0] $extent[1]))";
      $sql = "insert into mysql_spatial_extent(table_name, column_name, geom)\n"
        ."values ('$tableName', '$c',  ST_GeomFromText('$wkt'))";
      MySql::query($sql);
      return $extent;
    } catch (Exception $e) { // Probablement fonctions REGEXP non définies en MySql 5
      return [];
    }
  }
  
  function __construct(string $sql, mysqli_result $result, array $options=[]) {
    $this->sql = $sql;
    $this->result = $result;
    $this->options = $options;
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
  
  function current(): array {
    if (!($this->options['columnNamesInLowercase'] ?? null)) {
      $tuple = $this->ctuple;
    }
    else {
      $tuple = [];
      foreach ($this->ctuple as $key => $val)
        $tuple[strtolower($key)] = $val;
    }
    if (isset($this->options['jsonColumns'])) {
      foreach ($this->options['jsonColumns'] as $jsonColumn)
        if (isset($tuple[$jsonColumn]))
          $tuple[$jsonColumn] = json_decode($tuple[$jsonColumn], true);
    }
    return $tuple;
  }
  
  function key(): int { return 0; }
  function next(): void { $this->ctuple = $this->result->fetch_array(MYSQLI_ASSOC); }
  function valid(): bool { return ($this->ctuple <> null); }
};


if ((__FILE__ <> realpath($_SERVER['DOCUMENT_ROOT'].$_SERVER['SCRIPT_NAME'])) && (($argv[0] ?? '') <> basename(__FILE__))) return;
echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>mysql.inc.php</title></head><body><pre>\n";


if (0) {  // Test 2 rewind 
  //MySql::open('mysql://root@172.17.0.3/');
  MySql::open('mysql://root@mysqlserver/');
  $sql = "select * from information_schema.TABLES
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
// Navigation dans serveur=catalogue / schema=base / table / description / contenu (uniquement sur localhost)
elseif ($_SERVER['HTTP_HOST'] == 'localhost') {
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
  elseif (!($schema = $_GET['schema'] ?? null)) { // les schemas (=base) du serveur
    echo "mysql://$server:\n";
    MySql::open("mysql://$server");
    echo "  server_info: ", MySql::server_info(),"\n";
    echo "  Schemas/base:\n";
    $sql = "select schema_name from information_schema.schemata";
    $url = "&amp;server=$server";
    foreach (MySql::query($sql, ['columnNamesInLowercase'=> true]) as $tuple) {
      //print_r($tuple);
      echo "    - <a href='?schema=$tuple[schema_name]$url'>$tuple[schema_name]</a>\n";
    }
    die();
  }
  elseif (!($table = $_GET['table'] ?? null)) { // les tables du schema
    echo "Tables de mysql:$server/$schema:\n";
    MySql::open("mysql://$server/$schema");
    $sql = "select table_name from information_schema.tables where table_schema='$schema'";
    $url = "&amp;schema=$schema&amp;server=".urlencode($server);
    foreach (MySql::query($sql, ['columnNamesInLowercase'=> true]) as $tuple) {
      echo "  - <a href='?table=$tuple[table_name]$url'>$tuple[table_name]</a>\n";
    }
    die();
  }
  elseif (!isset($_GET['offset']) && !isset($_GET['action'])) { // Description de la table
    echo "Table mysql://$server/$schema/$table:\n";
    echo "  - <a href='?offset=0&amp;limit=20&amp;table=$table",
      "&amp;schema=$schema&amp;server=".urlencode($_GET['server'])."'>",
      "Affichage du contenu de la table</a>.\n";
    echo "  - Description de la table:\n";
    MySql::open("mysql://$server/$schema");
    $sql = "select c.ordinal_position, c.column_name, c.column_comment, c.data_type, c.character_maximum_length,
            k.constraint_name
          from information_schema.columns c
          left join information_schema.key_column_usage k
            on k.table_schema=c.table_schema and k.table_name=c.table_name and k.column_name=c.column_name
              and constraint_name='PRIMARY'
        where c.table_schema='$schema' and c.table_name='$table'";
    foreach (MySql::query($sql, ['columnNamesInLowercase'=> true] ) as $tuple) {
      $primary_key = ($tuple['constraint_name'] == 'PRIMARY') ? ' (primary key)' : '';
      echo "    $tuple[ordinal_position]:\n";
      echo "      id: $tuple[column_name]$primary_key\n";
      echo $tuple['column_comment'] ? "      description: $tuple[column_comment]\n" : '';
      if ($tuple['data_type']=='varchar')
        echo "      data_type: $tuple[data_type]($tuple[character_maximum_length])\n";
      elseif (in_array($tuple['data_type'], MySql::OGC_GEOM_TYPES))
        echo "      data_type: $tuple[data_type] ",
          "(<a href='?action=extent&amp;column=$tuple[column_name]&amp;table=$table",
          "&amp;schema=$schema&amp;server=".urlencode($_GET['server'])."'>extent</a>)\n";
      else
        echo "      data_type: $tuple[data_type]\n";
      if (0)
        print_r($tuple);
    }
    die();
  }
  elseif (($_GET['action'] ?? null) == 'extent') {
    MySql::open("mysql://$server/$schema");
    //echo MySql::getTuples("select count(*) count from $_GET[table]")[0]['count'],"\n"; die();
    // Divers tests pour calculer le spatial_extent
    if (0) { // explosion mémoire 
      $sql = "select ST_AsGeoJSON(ST_Envelope($_GET[column])) enveloppe from $_GET[table]";
      $no = 0;
      foreach (MySql::query($sql) as $tuple) {
        //echo "$tuple[enveloppe]\n";
        $envCoords = json_decode($tuple['enveloppe'], true)['coordinates'];
        //echo "xmin=",$envCoords[0][0][0],", ymin=",$envCoords[0][0][1],", ",
        //     "xmax=",$envCoords[0][2][0],", ymax=",$envCoords[0][2][1],"\n";
        if ($no++ === 0) {
          $xmin = $envCoords[0][0][0];
          $ymin = $envCoords[0][0][1];
          $xmax = $envCoords[0][2][0];
          $ymax = $envCoords[0][2][1];
        }
        else {
          if ($envCoords[0][0][0] < $xmin)
            $xmin = $envCoords[0][0][0];
          if ($envCoords[0][0][1] < $ymin)
            $ymin = $envCoords[0][0][1];
          if ($envCoords[0][2][0] > $xmax)
            $xmax = $envCoords[0][2][0];
          if ($envCoords[0][2][1] > $ymax)
            $ymax = $envCoords[0][2][1];
        }
        //if ($no >= 10) die();
      }
      echo "ext = [$xmin, $ymin, $xmax, $ymax]\n";
    }
    elseif (0) { // sans agrégat, différentes formes géom.
      echo "Test ",MySql::server_info(),"\n";
      $c = $_GET['column'];
      if (preg_match('!MariaDB!', MySql::server_info())) // Serveur 5.5.5-10.4.17-MariaDB
        $param = '\\\\'; // les paramètres sont identifiés par '\\\\1'
      else // MySql 8
        $param = '$'; // les paramètres sont identifiés par '$1'
      $sql = "
        ( select id_rte500, ST_AsText(ST_Envelope($c)) enveloppe,
            REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^POLYGON..([-0-9.]+) ([-0-9.]+),[-0-9. ]+,[-0-9. ]+,[-0-9. ]+,[-0-9. ]+..$',
              '${param}1') xmin,
            REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^POLYGON..([-0-9.]+) ([-0-9.]+),[-0-9. ]+,[-0-9. ]+,[-0-9. ]+,[-0-9. ]+..$',
              '${param}2') ymin,
            REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^POLYGON..[-0-9. ]+,[-0-9. ]+,([-0-9.]+) ([-0-9.]+),[-0-9. ]+,[-0-9. ]+..$',
              '${param}1') xmax,
            REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^POLYGON..[-0-9. ]+,[-0-9. ]+,([-0-9.]+) ([-0-9.]+),[-0-9. ]+,[-0-9. ]+..$',
              '${param}2') ymax
          from $_GET[table]
          where ST_AsText(ST_Envelope($c)) REGEXP '^POLYGONxx'
        )
        union
        ( select id_rte500, ST_AsText(ST_Envelope($c)) enveloppe,
            REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^LINESTRING.([-0-9.]+) ([-0-9.]+),[-0-9. ]+.$',
              '${param}1') xmin,
            REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^LINESTRING.([-0-9.]+) ([-0-9.]+),[-0-9. ]+.$',
              '${param}2') ymin,
            REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^LINESTRING.[-0-9. ]+,([-0-9.]+) ([-0-9.]+).$',
              '${param}1') xmax,
            REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^LINESTRING.[-0-9. ]+,([-0-9.]+) ([-0-9.]+).$',
              '${param}2') ymax
          from $_GET[table]
          where ST_AsText(ST_Envelope($c)) REGEXP '^LINESTRING'
        )
        union
        ( select id_rte500, ST_AsText(ST_Envelope($c)) enveloppe,
            REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^LINESTRING.[-0-9. ]+,([-0-9.]+) ([-0-9.]+).$',
              '${param}1') xmin,
            REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^LINESTRING.[-0-9. ]+,([-0-9.]+) ([-0-9.]+).$',
              '${param}2') ymin,
            REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^LINESTRING.([-0-9.]+) ([-0-9.]+),[-0-9. ]+.$',
              '${param}1') xmax,
            REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^LINESTRING.([-0-9.]+) ([-0-9.]+),[-0-9. ]+.$',
              '${param}2') ymax
          from $_GET[table]
          where ST_AsText(ST_Envelope($c)) REGEXP '^LINESTRING'
        )
        union
        ( select id_rte500, ST_AsText(ST_Envelope($c)) enveloppe,
            REGEXP_REPLACE(
              ST_AsText($c),
              '^POINT.([-0-9.]+) ([-0-9.]+).$',
              '${param}1') xmin,
            REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^POINT.([-0-9.]+) ([-0-9.]+).$',
              '${param}2') ymin,
            REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^POINT.([-0-9.]+) ([-0-9.]+).$',
              '${param}1') xmax,
            REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^POINT.([-0-9.]+) ([-0-9.]+).$',
              '${param}2') ymax
          from $_GET[table]
          where ST_AsText($c) REGEXP '^POINT'
        )
        limit 500";
      echo "$sql\n";
      $n=0;
      foreach (MySql::query($sql) as $tuple) {
        echo "**$n; $tuple[id_rte500]; $tuple[enveloppe]; ",
             "$tuple[xmin]; $tuple[ymin]; $tuple[xmax]; $tuple[ymax];\n";
        $n++;
      }
    }
    elseif (0) { // avec agrégat sur table dérivée, complexe et buggé 
      echo "Test pour MySql 8 / ",MySql::server_info(),"\n";
      if (preg_match('!MariaDB!', MySql::server_info())) // Serveur 5.5.5-10.4.17-MariaDB
        $param = '\\\\'; // les paramètres sont identifiés par '\\\\1'
      else // MySql 8
        $param = '$'; // les paramètres sont identifiés par '$1'
      $c = $_GET['column'];
      $sql = "
        select min(bbox.xmin) xmin, min(bbox.ymin) ymin, max(bbox.xmax) xmax, max(bbox.ymax) ymax
        from
        (
          ( select id_rte500, ST_AsText(ST_Envelope($c)) enveloppe,
              REGEXP_REPLACE(
                ST_AsText(ST_Envelope($c)),
                '^POLYGON..([-0-9.]+) ([-0-9.]+),[-0-9. ]+,[-0-9. ]+,[-0-9. ]+,[-0-9. ]+..$',
                '${param}1') xmin,
              REGEXP_REPLACE(
                ST_AsText(ST_Envelope($c)),
                '^POLYGON..([-0-9.]+) ([-0-9.]+),[-0-9. ]+,[-0-9. ]+,[-0-9. ]+,[-0-9. ]+..$',
                '${param}2') ymin,
              REGEXP_REPLACE(
                ST_AsText(ST_Envelope($c)),
                '^POLYGON..[-0-9. ]+,[-0-9. ]+,([-0-9.]+) ([-0-9.]+),[-0-9. ]+,[-0-9. ]+..$',
                '${param}1') xmax,
              REGEXP_REPLACE(
                ST_AsText(ST_Envelope($c)),
                '^POLYGON..[-0-9. ]+,[-0-9. ]+,([-0-9.]+) ([-0-9.]+),[-0-9. ]+,[-0-9. ]+..$',
                '${param}2') ymax
            from $_GET[table]
            where ST_AsText(ST_Envelope($c)) REGEXP '^POLYGON'
          )
          union
          ( select id_rte500, ST_AsText(ST_Envelope($c)) enveloppe,
              REGEXP_REPLACE(
                ST_AsText(ST_Envelope($c)),
                '^LINESTRING.([-0-9.]+) ([-0-9.]+),[-0-9. ]+.$',
                '${param}1') xmin,
              REGEXP_REPLACE(
                ST_AsText(ST_Envelope($c)),
                '^LINESTRING.([-0-9.]+) ([-0-9.]+),[-0-9. ]+.$',
                '${param}2') ymin,
              REGEXP_REPLACE(
                ST_AsText(ST_Envelope($c)),
                '^LINESTRING.[-0-9. ]+,([-0-9.]+) ([-0-9.]+).$',
                '${param}1') xmax,
              REGEXP_REPLACE(
                ST_AsText(ST_Envelope($c)),
                '^LINESTRING.[-0-9. ]+,([-0-9.]+) ([-0-9.]+).$',
                '${param}2') ymax
            from $_GET[table]
            where ST_AsText(ST_Envelope($c)) REGEXP '^LINESTRING'
          )
          union
          ( select id_rte500, ST_AsText(ST_Envelope($c)) enveloppe,
              REGEXP_REPLACE(
                ST_AsText(ST_Envelope($c)),
                '^LINESTRING.[-0-9. ]+,([-0-9.]+) ([-0-9.]+).$',
                '${param}1') xmin,
              REGEXP_REPLACE(
                ST_AsText(ST_Envelope($c)),
                '^LINESTRING.[-0-9. ]+,([-0-9.]+) ([-0-9.]+).$',
                '${param}2') ymin,
              REGEXP_REPLACE(
                ST_AsText(ST_Envelope($c)),
                '^LINESTRING.([-0-9.]+) ([-0-9.]+),[-0-9. ]+.$',
                '${param}1') xmax,
              REGEXP_REPLACE(
                ST_AsText(ST_Envelope($c)),
                '^LINESTRING.([-0-9.]+) ([-0-9.]+),[-0-9. ]+.$',
                '${param}2') ymax
            from $_GET[table]
            where ST_AsText(ST_Envelope($c)) REGEXP '^LINESTRING'
          )
          union
          ( select id_rte500, ST_AsText(ST_Envelope($c)) enveloppe,
              REGEXP_REPLACE(
                ST_AsText($c),
                '^POINT.([-0-9.]+) ([-0-9.]+).$',
                '${param}1') xmin,
              REGEXP_REPLACE(
                ST_AsText(ST_Envelope($c)),
                '^POINT.([-0-9.]+) ([-0-9.]+).$',
                '${param}2') ymin,
              REGEXP_REPLACE(
                ST_AsText(ST_Envelope($c)),
                '^POINT.([-0-9.]+) ([-0-9.]+).$',
                '${param}1') xmax,
              REGEXP_REPLACE(
                ST_AsText(ST_Envelope($c)),
                '^POINT.([-0-9.]+) ([-0-9.]+).$',
                '${param}2') ymax
            from $_GET[table]
            where ST_AsText($c) REGEXP '^POINTxx'
          )
        ) bbox";
      foreach (MySql::query($sql) as $tuple) {
        echo "$tuple[xmin]; $tuple[ymin]; $tuple[xmax]; $tuple[ymax];\n";
      }
    }
    elseif (0) { // avec agrégat par forme géom. - code correct sur '5.5.5-10.4.17-MariaDB' et MySql 8.0.23
      echo "Test 3 ",MySql::server_info(),"\n";
      $c = $_GET['column'];
      if (preg_match('!MariaDB!', MySql::server_info())) // Serveur 5.5.5-10.4.17-MariaDB
        $param = '\\\\'; // les paramètres sont identifiés par '\\\\1'
      else // MySql 8
        $param = '$'; // les paramètres sont identifiés par '$1'
      $sql = "
        select count($c) count,
          min(REGEXP_REPLACE(
            ST_AsText(ST_Envelope($c)),
            '^POLYGON..([-0-9.]+) ([-0-9.]+),[-0-9. ]+,[-0-9. ]+,[-0-9. ]+,[-0-9. ]+..$',
            '${param}1')+0) xmin,
          min(REGEXP_REPLACE(
            ST_AsText(ST_Envelope($c)),
            '^POLYGON..([-0-9.]+) ([-0-9.]+),[-0-9. ]+,[-0-9. ]+,[-0-9. ]+,[-0-9. ]+..$',
            '${param}2')+0) ymin,
          max(REGEXP_REPLACE(
            ST_AsText(ST_Envelope($c)),
            '^POLYGON..[-0-9. ]+,[-0-9. ]+,([-0-9.]+) ([-0-9.]+),[-0-9. ]+,[-0-9. ]+..$',
            '${param}1')+0) xmax,
          max(REGEXP_REPLACE(
            ST_AsText(ST_Envelope($c)),
            '^POLYGON..[-0-9. ]+,[-0-9. ]+,([-0-9.]+) ([-0-9.]+),[-0-9. ]+,[-0-9. ]+..$',
            '${param}2')+0) ymax
        from $_GET[table]
        where ST_AsText(ST_Envelope($c)) REGEXP '^POLYGON'
      ";
      $extent = [];
      $tuple = MySql::getTuples($sql)[0];
      if ($tuple['count']) {
        echo "POLYGON> $tuple[xmin]; $tuple[ymin]; $tuple[xmax]; $tuple[ymax];\n";
        $extent = $tuple;
      }
      else
        echo "No Polygon\n";
      $sql = "
        select count($c) count,
          min(REGEXP_REPLACE(
            ST_AsText(ST_Envelope($c)),
            '^LINESTRING.([-0-9.]+) ([-0-9.]+),[-0-9. ]+.$',
            '${param}1')+0) x1min,
          min(REGEXP_REPLACE(
            ST_AsText(ST_Envelope($c)),
            '^LINESTRING.([-0-9.]+) ([-0-9.]+),[-0-9. ]+.$',
            '${param}2')+0) y1min,
          min(REGEXP_REPLACE(
            ST_AsText(ST_Envelope($c)),
            '^LINESTRING.[-0-9. ]+,([-0-9.]+) ([-0-9.]+).$',
            '${param}1')+0) x2min,
          min(REGEXP_REPLACE(
            ST_AsText(ST_Envelope($c)),
            '^LINESTRING.[-0-9. ]+,([-0-9.]+) ([-0-9.]+).$',
            '${param}2')+0) y2min,
          max(REGEXP_REPLACE(
            ST_AsText(ST_Envelope($c)),
            '^LINESTRING.([-0-9.]+) ([-0-9.]+),[-0-9. ]+.$',
            '${param}1')+0) x1max,
          max(REGEXP_REPLACE(
            ST_AsText(ST_Envelope($c)),
            '^LINESTRING.([-0-9.]+) ([-0-9.]+),[-0-9. ]+.$',
            '${param}2')+0) y1max,
          max(REGEXP_REPLACE(
            ST_AsText(ST_Envelope($c)),
            '^LINESTRING.[-0-9. ]+,([-0-9.]+) ([-0-9.]+).$',
            '${param}1')+0) x2max,
          max(REGEXP_REPLACE(
            ST_AsText(ST_Envelope($c)),
            '^LINESTRING.[-0-9. ]+,([-0-9.]+) ([-0-9.]+).$',
            '${param}2')+0) y2max
        from $_GET[table]
        where ST_AsText(ST_Envelope($c)) REGEXP '^LINESTRING'
      ";
      $tuple = MySql::getTuples($sql)[0];
      if ($tuple['count']) {
        echo "LINESTRING> $tuple[x1min]; $tuple[y1min]; $tuple[x2min]; $tuple[y2min];",
             " $tuple[x1max]; $tuple[y1max]; $tuple[x2max]; $tuple[y2max];\n";
        if ($extent) {
          $extent['xmin'] = min($extent['xmin'], $tuple['x1min'], $tuple['x2min']);
          $extent['ymin'] = min($extent['ymin'], $tuple['y1min'], $tuple['y2min']);
          $extent['xmax'] = max($extent['xmax'], $tuple['x1max'], $tuple['x2max']);
          $extent['ymax'] = max($extent['ymax'], $tuple['y1max'], $tuple['y2max']);
        }
        else {
          $extent['xmin'] = min($tuple['x1min'], $tuple['x2min']);
          $extent['ymin'] = min($tuple['y1min'], $tuple['y2min']);
          $extent['xmax'] = max($tuple['x1max'], $tuple['x2max']);
          $extent['ymax'] = max($tuple['y1max'], $tuple['y2max']);
        }
        echo 'extent='; print_r($extent);
      }
      else
        echo "No LINESTRING\n";
      $sql = "
        select count($c) count,
          min(REGEXP_REPLACE(
            ST_AsText($c),
            '^POINT.([-0-9.]+) ([-0-9.]+).$',
            '${param}1')+0) xmin,
          min(REGEXP_REPLACE(
            ST_AsText(ST_Envelope($c)),
            '^POINT.([-0-9.]+) ([-0-9.]+).$',
            '${param}2')+0) ymin,
          max(REGEXP_REPLACE(
            ST_AsText(ST_Envelope($c)),
            '^POINT.([-0-9.]+) ([-0-9.]+).$',
            '${param}1')+0) xmax,
          max(REGEXP_REPLACE(
            ST_AsText(ST_Envelope($c)),
            '^POINT.([-0-9.]+) ([-0-9.]+).$',
            '${param}2')+0) ymax
        from $_GET[table]
        where ST_AsText(ST_Envelope($c)) REGEXP '^POINT'
      ";
      $tuple = MySql::getTuples($sql)[0];
      if ($tuple['count']) {
        echo "POINT> $tuple[xmin]; $tuple[ymin]; $tuple[xmax]; $tuple[ymax];\n";
        if ($extent) {
          $extent['xmin'] = min($extent['xmin'], $tuple['xmin']);
          $extent['ymin'] = min($extent['ymin'], $tuple['ymin']);
          $extent['xmax'] = max($extent['xmax'], $tuple['xmax']);
          $extent['ymax'] = max($extent['ymax'], $tuple['ymax']);
          echo 'extent='; print_r($extent);
        }
        else
          $extent = $tuple;
      }
      else
        echo "No POINT\n";
      echo 'extent='; print_r($extent);
    }
    elseif (0) { // uniquement sur MariaDB - utilisation de REGEXP_SUBSTR() et REGEXP_REPLACE()
      // pour extraire xmin,ymin,xmax,ymax de ST_AsText(ST_Envelope()) 
      // Test des expressions tuple par tuple
      $c = $_GET['column'];
      $sql = "
        select ST_AsText(ST_Envelope($c)) enveloppe,
          REGEXP_SUBSTR(ST_AsText(ST_Envelope($c)), '[0-9.-]+') xmin,
          REGEXP_REPLACE(
            ST_AsText(ST_Envelope($c)),
            '^POLYGON..[-0-9.]+ ([-0-9.]+),[-0-9. ]+,[-0-9. ]+,[-0-9. ]+,[-0-9. ]+..$',
            '\\\\1') ymin,
          REGEXP_REPLACE(
            ST_AsText(ST_Envelope($c)),
            '^POLYGON..[-0-9. ]+,[-0-9. ]+,([-0-9.]+) ([-0-9.]+),[-0-9. ]+,[-0-9. ]+..$',
            '\\\\1') xmax,
          REGEXP_REPLACE(
            ST_AsText(ST_Envelope($c)),
            '^POLYGON..[-0-9. ]+,[-0-9. ]+,([-0-9.]+) ([-0-9.]+),[-0-9. ]+,[-0-9. ]+..$',
            '\\\\2') ymax
        from $_GET[table]
        limit 50";
      foreach (MySql::query($sql) as $tuple) {
        echo "$tuple[enveloppe]; $tuple[xmin]; $tuple[ymin]; $tuple[xmax]; $tuple[ymax];\n";
      }
    }
    elseif (0) { // uniquement sur MariaDB, utilisation des expressions avec une agrégation pour obtenir un résultat
      $c = $_GET['column'];
      $sqlagg = "
        select 
          min(REGEXP_SUBSTR(ST_AsText(ST_Envelope($c)), '[-0-9.]+')+0) xmin,
            min(REGEXP_REPLACE(
              ST_AsText(ST_Envelope($c)),
              '^POLYGON..[-0-9.]+ ([-0-9.]+),[-0-9. ]+,[-0-9. ]+,[-0-9. ]+,[-0-9. ]+..$',
              '\\\\1')+0) ymin,
          max(REGEXP_REPLACE(
            ST_AsText(ST_Envelope($c)),
            '^POLYGON..[-0-9. ]+,[-0-9. ]+,([-0-9.]+) ([-0-9.]+),[-0-9. ]+,[-0-9. ]+..$',
            '\\\\1')+0) xmax,
          max(REGEXP_REPLACE(
            ST_AsText(ST_Envelope($c)),
            '^POLYGON..[-0-9. ]+,[-0-9. ]+,([-0-9.]+) ([-0-9.]+),[-0-9. ]+,[-0-9. ]+..$',
            '\\\\2')+0) ymax
        from $_GET[table]";
      $tuple = MySql::getTuples($sqlagg)[0];
      echo "$tuple[xmin];$tuple[xmin2];$tuple[ymin];$tuple[xmax];$tuple[ymax];\n";
    }
    elseif (1) { // utilisation de la fonction définitive
      echo 'extension=',json_encode(MySql::spatialExtent($_GET['table'], $_GET['column']));
    }
    die();
  }
  else { // affichage du contenu de la table à partir de offset
    $offset = (int) $_GET['offset'];
    $limit = (int) ($_GET['limit'] ?? 20);
    MySql::open("mysql://$server/$schema");
    $columns = [];
    foreach (MySql::tableColumns($table) as $cname => $column) {
      if (in_array($column['data_type'], MySql::OGC_GEOM_TYPES))
        $columns[] = "ST_AsGeoJSON($cname) $cname";
      else
        $columns[] = $cname;
    }
    $sql = $_GET['sql'] ?? "select ".implode(', ', $columns)."\nfrom $table";
    $url = "table=$table&amp;schema=$schema&amp;server=".urlencode($_GET['server']);
    echo "</pre>",
      "<h2>mysql://$server$schema/$table</h2>\n",
      "<form><table border=1><tr>",
      "<input type='hidden' name='offset' value='0'>",
      "<input type='hidden' name='limit' value='$limit'>",
      "<td><textarea name='sql' rows='5' cols='130'>$sql</textarea></td>",
      "<input type='hidden' name='table' value='$table'>",
      "<input type='hidden' name='schema' value='$schema'>",
      "<input type='hidden' name='server' value='$server'>",
      "<td><input type=submit value='go'></td>",
      "</tr></table></form>\n",
      "<a href='?$url'>^</a> ",
      ((($offset-$limit) >= 0) ? "<a href='?offset=".($offset-$limit)."&amp;limit=$limit"
        ."&amp;sql=".urlencode($sql)."&amp;$url'>&lt;</a>" : ''),
      " offset=$offset ",
      "<a href='?offset=".($offset+$limit)."&amp;limit=$limit"
        ."&amp;sql=".urlencode($sql)."&amp;$url'>&gt;</a>",
      "<table border=1>\n";
    echo "</pre><table border=1>\n";
    $no = 0;
    foreach (MySql::query("$sql\nlimit $limit offset $offset") as $tuple) {
      if (!$no++)
        echo '<th>no</th><th>', implode('</th><th>', array_keys($tuple)),"</th>\n";
      echo "<tr><td>$no</td><td>", implode('</td><td>', $tuple),"</td></tr>\n";
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
