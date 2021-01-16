<?php
/*PhpDoc:
name: pgsql.inc.php
title: pgsql.inc.php - définition de la classe PgSql facilitant l'utilisation de PostgreSql
classes:
doc: |
  Fork de sql.inc.php permettant d'utiliser cette classe sans Sql
journal: |
  16/1/2021:
    - ajout de champs à PgSql
    - ajout de la méthode PgSql::tableColumns()
    - suppression de la méthode PgSql::server()
    - dév. navigateur serveur / base / schéma / table / description / contenu
  11/8/2020:
    - ajout PgSql::$connection et PgSql::affected_rows()
  18/6/2020:
    - écriture d'une doc
*/

/*PhpDoc: classes
name: PgSql
title: class PgSql implements Iterator - classe facilitant l'utilisation de PostgreSql
methods:
doc: |
  Classe implémentant en statique les méthodes de connexion et de requete
  et générant un objet correspondant à un itérateur permettant d'accéder au résultat

  La méthode statique open() ouvre une connexion PgSql à un {user}@{server}/{dbname}(/{schema})?
  La méthode statique query() lance une requête et retourne un objet itérable
*/
class PgSql implements Iterator {
  static $connection; // ressource de connexion retournée par pg_connect()
  static $server; // le nom du serveur
  static $database; // nom de la base
  static $schema; // nom du schema s'il a été défini dans open() ou null
  protected $sql = null; // la requête conservée pour pouvoir faire plusieurs rewind
  protected $result = null; // l'objet retourné par pg_query()
  protected $first; // indique s'il s'agit du premier rewind
  protected $id; // un no en séquence à partir de 1
  protected $ctuple = false; // le tuple courant ou false
  
  static function open(string $params): void {
    /*PhpDoc: methods
    name: open
    title: static function open(string $params) - ouvre une connexion PgSql
    doc: |
      Le motif des paramètres est:
        - 'host={server}( port={port})? dbname={dbname} user={user}( password={password})?' ou
        - 'pgsql://{user}(:{password})?@{server}(:{port})?/{dbname}(/{schema})?'
      Si le mot de passe n'est pas fourni alors il est recherché dans le fichier secret.inc.php
      Si le schéma est fourni alors il est initialisé après l'ouverture de la base.
    */
    //echo "PgSql::open($connection_string)\n";
    $pattern = '!^host=([^ ]+)( port=([^ ]+))? dbname=([^ ]+) user=([^ ]+)( password=([^ ]+))?$!';
    if (preg_match($pattern, $params, $matches)) {
      $server = $matches[1];
      $port = $matches[3];
      $database = $matches[4];
      $user = $matches[5];
      $passwd = $matches[7] ?? null;
      $schema = null;
      $conn_string = $params;
    }
    elseif (preg_match('!^pgsql://([^@:]+)(:[^@]+)?@([^:/]+)(:\d+)?/([^/]+)(/.*)?$!', $params, $matches)) {
      $user = $matches[1];
      $passwd = $matches[2] ? substr($matches[2], 1) : null;
      $server = $matches[3];
      $port = $matches[4] ? substr($matches[4], 1) : '';
      $database = $matches[5];
      $schema = isset($matches[6]) ? substr($matches[6], 1) : null;
      //print_r($matches); die();
      $conn_string = "host=$server".($port ? " port=$port": '')
        ." dbname=$database user=$user".($passwd ? "password=$passwd": '');
      //echo "conn_string=$conn_string\n";
    }
    else
      throw new Exception("Erreur: dans PgSql::open() params \"".$conn_string."\" incorrect");
    self::$server = $server;
    self::$database = $database;
    self::$schema = $schema;
    if (!$passwd) {
      if (!is_file(__DIR__.'/secret.inc.php'))
        throw new Exception("Erreur: dans PgSql::open($conn_string), fichier secret.inc.php absent");
      else {
        $secrets = require(__DIR__.'/secret.inc.php');
        if (!($passwd = $secrets['sql']["pgsql://$user@$server/"] ?? null))
          throw new Exception("Erreur: dans PgSql::open($params), mot de passe absent de secret.inc.php");
      }
      $conn_string .= " password=$passwd";
      //echo "conn_string=$conn_string\n"; die();
    }
    if (!(self::$connection = pg_connect($conn_string)))
      throw new Exception('Could not connect: '.pg_last_error());
    
    if ($schema)
      self::query("SET search_path TO $schema");
  }
  
  /*static function server(): string {
    if (!self::$server)
      throw new Exception("Erreur: dans PgSql::server() server non défini");
    return self::$server;
  }*/
  
  static function close(): void { pg_close(); }
  
  static function tableColumns(string $table, ?string $schema=null): ?array { // liste des colonnes de la table
    /*PhpDoc: methods
    name: tableColumns
    title: "static function tableColumns(string $table, ?string $schema=null): ?array"
    doc: |
      Retourne la liste des colonnes d'une table structuré comme:
        [ [
            'ordinal_position'=> ordinal_position,
            'column_name'=> column_name,
            'data_type'=> data_type,
            'character_maximum_length'=> character_maximum_length,
            'udt_name'=> udt_name,
            'constraint_name'=> constraint_name,
        ] ]
      Les 5 premiers champs proviennent de la table INFORMATION_SCHEMA.columns et le dernier d'une jointure gauche
      avec INFORMATION_SCHEMA.key_column_usage
    */
    $base = self::$database;
    if (!$schema)
      $schema = self::$schema;
    $sql = "select c.ordinal_position, c.column_name, c.data_type, c.character_maximum_length, c.udt_name, 
            k.constraint_name
          -- select c.*
          from INFORMATION_SCHEMA.columns c
          left join INFORMATION_SCHEMA.key_column_usage k
            on k.table_catalog=c.table_catalog and k.table_schema=c.table_schema
              and k.table_name=c.table_name and k.column_name=c.column_name
          where c.table_catalog='$base' and c.table_schema='$schema' and c.table_name='$table'";
    $columns = [];
    foreach(PgSql::query($sql) as $tuple) {
      //print_r($tuple);
      $columns[$tuple['column_name']] = $tuple;
    }
    return $columns;
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

  static function query(string $sql) {
    /*PhpDoc: methods
    name: query
    title: static function query(string $sql) - lance une requête et retourne éventuellement un itérateur
    doc: |
      Si la requête renvoit comme résultat un ensemble de n-uplets alors retourne un itérateur donnant accès
      à chacun d'eux sous la forme d'un array [{column_name}=> valeur] (pg_fetch_array() avec PGSQL_ASSOC).
      Sinon renvoit TRUE ssi la requête est Ok
      Sinon en cas d'erreur PgSql génère une exception
    */
    if (!($result = @pg_query(self::$connection, $sql)))
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

  function affected_rows(): int { return pg_affected_rows($this->result); }
};



if ((__FILE__ <> realpath($_SERVER['DOCUMENT_ROOT'].$_SERVER['SCRIPT_NAME'])) && (($argv[0] ?? '') <> basename(__FILE__))) return;
echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>pgsql.inc.php</title></head><body><pre>\n";


if (0) {
  echo "<pre>";
  //PgSql::open('host=172.17.0.4 dbname=postgres user=postgres password=benoit'); 
  PgSql::open('host=172.17.0.4 dbname=postgres user=postgres'); 
  $sql = "select * from INFORMATION_SCHEMA.TABLES where table_schema='public'";
  foreach (PgSql::query($sql) as $tuple) {
    echo "tuple="; print_r($tuple);
  }
}
else { // Navigation dans serveur / schéma / base / table / description / contenu
  if (!($server = $_GET['server'] ?? null)) { // les serveurs définis dans secret.inc.php
    $secrets = require(__DIR__.'/secret.inc.php');
    //print_r($secrets['sql']);
    echo "Servers:\n";
    foreach (array_keys($secrets['sql']) as $userServer) {
      if (substr($userServer, 0, 8) == 'pgsql://')
        echo "  - <a href='?server=",urlencode(substr($userServer, 8)),"'>$userServer</a>\n";
    }
    die();
  }
  elseif (!($base = $_GET['base'] ?? null)) { // les bases du serveur
    echo "Base de pgsql://$server:\n";
    PgSql::open("pgsql://${server}postgres");
    $sql = "select * from pg_database";
    foreach (PgSql::query($sql) as $tuple) {
      echo "  - <a href='?base=$tuple[datname]&amp;server=",urlencode($server),"'>$tuple[datname]</a>\n";
      //print_r($tuple);
    }
    die();
  }
  elseif (!($schema = $_GET['schema'] ?? null)) { // les schémas de la base
    echo "Schémas de la base pgsql://$server$base:\n";
    PgSql::open("pgsql://$server$base");
    $sql = "select distinct table_schema from INFORMATION_SCHEMA.TABLES";
    $url = "base=$base&amp;server=".urlencode($server);
    foreach (PgSql::query($sql) as $tuple) {
      echo "  - <a href='?schema=$tuple[table_schema]&amp;$url'>$tuple[table_schema]</a>\n";
    }
    die();
  }
  elseif (!($table = $_GET['table'] ?? null)) { // les tables de la base
    echo "Tables de pgsql:$server$base/$schema:\n";
    PgSql::open("pgsql://$server$base/$schema");
    $sql = "select table_name from INFORMATION_SCHEMA.TABLES
        where table_catalog='$base' and table_schema='$schema'";
    //$sql = "select * from INFORMATION_SCHEMA.TABLES";
    $url = "schema=$schema&amp;base=$base&amp;server=".urlencode($server);
    foreach (PgSql::query($sql) as $tuple) {
      echo "  - <a href='?table=$tuple[table_name]&amp;$url'>$tuple[table_name]</a>\n";
      //print_r($tuple);
    }
    die();
  }
  elseif (null === ($offset = $_GET['offset'] ?? null)) { // Description de la table
    echo "Table pgsql://$server$base/$schema/$table:\n";
    echo "  - <a href='?offset=0&amp;table=$table&amp;schema=$schema&amp;base=$base",
      "&amp;server=".urlencode($server),"'>Affichage du contenu de la table</a>.\n";
    echo "  - Description de la table:\n";
    PgSql::open("pgsql://$server$base/$schema");
    $sql = "select c.ordinal_position, c.column_name, c.data_type, c.character_maximum_length, k.constraint_name
          from INFORMATION_SCHEMA.columns c
          left join INFORMATION_SCHEMA.key_column_usage k
            on k.table_catalog=c.table_catalog and k.table_schema=c.table_schema
              and k.table_name=c.table_name and k.column_name=c.column_name
          where c.table_catalog='$base' and c.table_schema='$schema' and c.table_name='$table'";
    foreach (PgSql::query($sql) as $tuple) {
      echo "    $tuple[ordinal_position]:\n";
      echo "      id: $tuple[column_name]\n";
      if ($tuple['constraint_name'])
        echo "      constraint: $tuple[constraint_name]\n";
      if ($tuple['data_type']=='character')
        echo "      data_type: $tuple[data_type]($tuple[character_maximum_length])\n";
      else
        echo "      data_type: $tuple[data_type]\n";
      if (0)
        print_r($tuple);
    }
    die();
  }
  else { // affichage du contenu de la table à partir de offset
    $limit = 20;
    PgSql::open("pgsql://$server$base/$schema");
    //print_r(PgSql::tableColumns($table));
    $columns = [];
    foreach (PgSql::tableColumns($table) as $cname => $column) {
      if ($column['udt_name']=='geometry')
        $columns[] = "ST_AsGeoJSON($cname) $cname";
      else
        $columns[] = $cname;
    }
    $url = "table=$table&amp;schema=$schema&amp;base=$base&amp;server=".urlencode($server);
    echo "</pre>",
      "<h2>pgsql://$server$base/$schema/$table</h2>\n",
      "<a href='?$url'>^</a> ",
      ((($offset-$limit) >= 0) ? "<a href='?offset=".($offset-$limit)."&amp;$url'>&lt;</a>" : ''),
      " offset=$offset ",
      "<a href='?offset=".($offset+$limit)."&amp;$url'>&gt;</a>",
      "<table border=1>\n";
    echo "</pre><table border=1>\n";
    $sql = "select ".implode(', ', $columns)." from $table limit $limit offset $offset";
    $no = 0;
    //echo "sql=$sql\n";
    foreach (PgSql::query($sql) as $tuple) {
      if (!$no++)
        echo '<th>', implode('</th><th>', array_keys($tuple)),"</th>\n";
      echo '<tr><td>', implode('</td><td>', $tuple),"</td></tr>\n";
    }
    echo "</table>\n";
    die();
  }
  
  die();
}
