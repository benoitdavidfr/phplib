<?php
namespace Sql;
/*PhpDoc:
name: sqlschema.inc.php
title: sqlschema.inc.php - utilisation du schema Sql
classes:
doc: |
  La classe Schema contient les infos d'un schéma Sql (base MySql ou schéma PgSql).
  Création à partir d'un URI de la forme mysql://serveur/base ou pgsql://serveur/base/schema

  namespace Sql

  le champ Column::dataType devrait être plus standardisé, par exemple utiliser les types JSON ou SQL standards.

  Le script implémente comme test un navigateur dans les serveurs définis dans secret.inc.php
  Pour des raisons de sécurité ces fonctionnalités sont limitées sur un serveur différent de localhost

journal: |
  26/1/2021:
    - modif 2e paramètre de Schema::__construct()
  24-25/1/2021:
    - création
includes:
  - sql.inc.php
  - secret.inc.php
*/
require_once __DIR__.'/../geovect/vendor/autoload.php';
require_once __DIR__.'/sql.inc.php';

use Symfony\Component\Yaml\Yaml;
use Exception;
use Sql;

/*PhpDoc: classes
name: Schema
title: class Schema - contient les infos d'un schéma Sql
doc: |
  La classe Schema contient les infos d'un schéma Sql (base MySql ou schéma PgSql).
  Création à partir d'un URI de la forme mysql://serveur/base ou pgsql://serveur/base/schema

  Les infos sont lues dans les tables information_schema.tables et information_schema.columns
  plus pour PgSql la table pg_catalog.pg_indexes pour connaitre les index secondaires non uniques

  La méthode statique listOfSchemas(string $catalogUri) permet d'obtenir la liste des schémas
  d'un catalogue à partir d'un URI de la forme mysql://serveur ou pgsql://serveur/base

  La méthode statique listOfPgCatalogs(string $serverUri) permet sur PgSql d'obtenir la liste des bases,
  chacune correspondant à un catalogue à partir d'un URI de la forme pgsql://serveur

  Les champs de Schema accessibles en lecture sont:
    - string $uri; // uri du schema de la forme 'mysql://server/base' ou 'pgsql://server/base/schema'
    - string $name; // nom du schema
    - array $tables=[]; // dict. des tables ou vues du schéma indexées par le nom de table

  Un objet Schema peut être utilisé comme string en retournant son URI.

  Un objet Schema peut être transformé en array par la méthode asArray()
methods:
*/
class Schema {
  protected string $uri; // uri du schema de la forme 'mysql://server/base' ou 'pgsql://server/base/schema'
  protected string $name; // nom du schema
  protected array $tables=[]; // dict. des tables ou vues du schéma indexées par le nom de table
  
  // Sur PgSql dictionnaire des catalogues (=bases) du serveur, sur MySql renvoie []
  static function listOfPgCatalogs(string $serverUri): array {
    /*PhpDoc: methods
    name: listOfPgCatalogs
    title: "static function listOfPgCatalogs(string $serverUri): array - Sur PgSql dictionnaire des catalogues (=bases) du serveur"
    doc: |
      Sur PgSql (uri de la forme pgsql://{serveur}) le dictionnaire retourné est sous la forme [{name}=> {uri}],
      sur MySql (uri de la forme mysql://{serveur}) renvoie [].
    */
    if (substr($serverUri, 0, 8) == 'mysql://')
      return [];
    elseif (!preg_match('!^pgsql://[^/]+$!', $serverUri))
      throw new \Exception("Erreur: uri $serverUri incorrecte");
    Sql::open("$serverUri/postgres");
    $dict = [];
    foreach (Sql::query('select datname from pg_database') as $tuple)
      $dict[$tuple['datname']] = "$serverUri/$tuple[datname]";
    return $dict;
  }
  
  // dictionnaire des schemas à partir de l'uri d'un catalogue de la forme 'mysql://server' ou 'pgsql://server/base'
  static function listOfSchemas(string $catalogUri): array {
    /*PhpDoc: methods
    name: listOfSchemas
    title: "static function listOfSchemas(string $catalogUri): array - dictionnaire des schemas"
    doc: |
      dictionnaire des schemas à partir de l'uri d'un catalogue de la forme 'mysql://server' ou 'pgsql://server/base'.
      Le dictionnaire est de la forme [{name}=> {uri}]
    */
    if (!preg_match('!^(mysql://[^/]+|pgsql://[^/]+/[^/]+)$!', $catalogUri))
      throw new \Exception("Erreur: uri $catalogUri incorrecte");
    Sql::open($catalogUri);
    $dict = [];
    $sql = 'select schema_name from information_schema.schemata';
    foreach (Sql::query($sql, ['columnNamesInLowercase'=> true]) as $tuple) {
      //print_r($tuple);
      $dict[$tuple['schema_name']] = "$catalogUri/$tuple[schema_name]";
    }
    return $dict;
  }
  
  function __construct(string $uri, array $options=[]) {
    /*PhpDoc: methods
    name: __construct
    title: "function __construct(string $uri, array $options=[]) - crée un objet Schema à partir d'un URI"
    doc: |
      Le schéma peut être limité à certaines tables avec l'option 'table_names' qui donne une liste de noms de tables
      ou avec l'option 'table_types' qui donne une liste de types de table parmi 'BASE TABLE','SYSTEM VIEW','VIEW'
    */
    if (!preg_match('!^(mysql://[^/]+|pgsql://[^/]+/[^/]+)/([^/]+)$!', $uri, $matches))
      throw new \Exception("Erreur: $uri incorrecte");
    foreach ($options as $key => $option) {
      if (!is_array($option))
        throw new \Exception("Erreur: option $key=".json_encode($option)." incorrecte");
    }
    $this->name = $matches[2];
    $this->uri = $uri;
    $cols = []; // [table_name => [...]]
    Sql::open($uri);
    $tables = [];
    $sql = [
      "select table_name, table_type",
      [
        'MySql'=> ', table_comment',
      ],
      "\nfrom information_schema.tables",
      "\nwhere table_schema='$this->name'"
        .(isset($options['table_names']) ? " and table_name in ('".implode("','", $options['table_names'])."')" : '')
        .(isset($options['table_types']) ? " and table_type in ('".implode("','", $options['table_types'])."')" : ''),
    ];
    foreach (Sql::query($sql, ['columnNamesInLowercase'=> true]) as $tuple) {
      //print_r($tuple);
      $tables[$tuple['table_name']] = $tuple;
    }
    $cols = [];
    $sql = "select *\nfrom information_schema.columns\n"
      ."where table_schema='$this->name'"
      .(isset($options['table_names']) ? " and table_name in ('".implode("','", $options['table_names'])."')" : '');
    foreach (Sql::query($sql, ['columnNamesInLowercase'=> true]) as $tuple) {
      //print_r($tuple);
      if (isset($tables[$tuple['table_name']]))
        $cols[$tuple['table_name']][$tuple['ordinal_position']] = $tuple;
    }
    //print_r($cols);
    $indexes = [];
    if (Sql::software()=='PgSql') {
      $sql = "select * from pg_catalog.pg_indexes where schemaname='$this->name'";
      foreach (Sql::query($sql) as $tuple) {
        //print_r($tuple);
        $indexes[$tuple['tablename']][$tuple['indexname']] = $tuple['indexdef'];
      }
      //print_r($indexes);
    }
    foreach ($cols as $table_name => $tableCols) {
      ksort($tableCols);
      $this->tables[$table_name] = new Table($this, $tables[$table_name], $tableCols, $indexes[$table_name] ?? []);
    }
  }
  
  function __toString(): string { return $this->uri; }
  function __get(string $name) { return isset($this->$name) ? $this->$name : null; }
  
  function asArray(): array {
    foreach ($this->tables as $tname => $table)
      $tables[$tname] = $table->asArray();
    return [
      'name'=> $this->name,
      'tables'=> $tables,
    ];
  }

  // Cherche un couple (table,colonne géométrique) dont la concaténation des noms correspond à $table_geom_name
  function concatTableGeomNames(string $table_geom_name, string $sep): ?Column {
    foreach ($this->tables as $tname => $table) {
      foreach ($table->columns as $cname => $column) {
        if (($column->dataType == 'geometry') && ($tname.$sep.$cname == $table_geom_name))
          return $column;
      }
    }
    return null; 
  }
};

/*PhpDoc: classes
name: Table
title: class Table -  contient les infos d'une table d'un schéma Sql
doc: |
  La classe Table contient les infos d'une table d'un schéma Sql.

  Les champs accessibles en lecture sont:
    - Schema $schema; // schema auquel appartient la table
    - string $name;
    - string $type; // 'BASE TABLE' | 'VIEW' | 'SYSTEM VIEW'
    - string $comment; // commentaire associé, uniquement en MySql
    - array $columns; // dict. des colonnes indexé par le nom de colonne dans l'ordre de définition

  Un objet peut être transformé en array par la méthode asArray()
methods:
*/
class Table {
  protected Schema $schema; // schema auquel appartient la table
  protected string $name;
  protected string $type; // 'BASE TABLE' | 'VIEW' | 'SYSTEM VIEW'
  protected string $comment; // commentaire associé, uniquement en MySql
  protected array $columns; // dict. des colonnes indexé par le nom de colonne dans l'ordre de définition
  
  function __construct(Schema $schema, array $tableInfo, array $cols, array $indexes) {
    $this->schema = $schema;
    $this->name = $tableInfo['table_name'];
    $this->type = $tableInfo['table_type'];
    $this->comment = $tableInfo['table_comment'] ?? '';
    foreach ($cols as $col)
      $column_key[$col['column_name']] = '';
    foreach ($indexes as $indexName => $indexDef) { /*PgSql*/
      //echo "$indexName: $indexDef\n";
      // CREATE UNIQUE INDEX cote_frontiere_pkey ON public.cote_frontiere USING btree (ogc_fid)
      // CREATE INDEX noeud_routier_wkb_geometry_geom_idx ON public.noeud_routier USING gist (wkb_geometry)
      // CREATE INDEX nature_energie ON public.troncon_voie_ferree USING btree (nature, energie)
      // CREATE INDEX lim2_eid ON public.lim2 USING hash (eid)
      $pattern = '!^CREATE (UNIQUE )?INDEX ([^ ]+) ON [^ ]+ USING (btree|gist|hash) \(([^)]+)\)$!';
      if (!preg_match($pattern, $indexDef, $matches))
        throw new Exception("No match for index $indexDef");
      $unique = $matches[1];
      $indexName = $matches[2];
      $indexType = $matches[3];
      $column_name = explode(', ', $matches[4])[0];
      //echo "column_name=$this->name.$column_name\n";
      if (substr($indexName, -5) == '_pkey')
        $column_key[$column_name] = 'PRI';
      else
        $column_key[$column_name] = $unique ? 'UNI' : 'MUL';
    }
    foreach ($cols as $col) {
      if (!isset($col['column_key']))
        $col['column_key'] = $column_key[$col['column_name']];
      //echo "col="; print_r($col);
      $this->columns[$col['column_name']] = new Column($this, $col['column_name'], $col);
    }
  }
  
  function __toString(): string { return $this->schema.'/'.$this->name; }
  function __get(string $name) { return isset($this->$name) ? $this->$name : null; }

  function asArray(): array {
    foreach ($this->columns as $cname => $column)
      $cols[$cname] = $column->asArray();
    $array = ['type'=> $this->type];
    if ($this->comment)
      $array['comment'] = $this->comment;
    $array['columns'] = $cols;
    return $array;
  }
  
  // liste des colonnes d'un des types sous la forme [{name} => \Sql\Column]
  function listOfColumnOfOneOfTheTypes(array $dataTypes): array {
    $list = [];
    foreach ($this->columns as $cname => $column) {
      if (in_array($column->dataType, $dataTypes))
        $list[$cname] = $column;
    }
    return $list;
  }
  
  // liste des colonnes géométriques
  function listOfGeometryColumns(): array { return $this->listOfColumnOfOneOfTheTypes(Column::OGC_GEOM_TYPES); }
  
  function pkeyCol(): ?Column {
    foreach ($this->columns as $cname => $column) {
      if ($column->indexed == 'primary')
        return $column;
    }
    return null;
  }
};

/*PhpDoc: classes
name: Column
title: class Column
doc: |
  La classe Column contient les infos d'une colonne d'une table d'un schéma Sql.

  Les champs accessibles en lecture sont:
    - Table $table; // table à laquelle la colonne appartient
    - string $name;
    - string $dataType; // motif à définir
    - ?int $character_maximum_length;
    - ?int $numeric_precision;
    - ?int $numeric_scale;
    - bool $is_nullable;
    - ?string $indexed=null; // 'primary'|'unique'|'multiple'|'none'|null
    - ?string $comment; // uniquement MySql

  Un objet peut être transformé en array par la méthode asArray()
methods:
*/
class Column {
  // Types géométriques pour MySql
  const OGC_GEOM_TYPES = ['geometry','point','multipoint','linestring','multilinestring','polygon','multipolygon'];

  protected Table $table; // table à laquelle la colonne appartient
  protected string $name;
  protected string $dataType; // motif à définir
  protected ?int $character_maximum_length;
  protected ?int $numeric_precision;
  protected ?int $numeric_scale;
  protected bool $is_nullable;
  protected ?string $indexed=null; // 'primary'|'unique'|'multiple'|'none'|null
  protected ?string $comment; // uniquement MySql
  protected array $info;
  
  function __construct(Table $table, string $name, array $info) {
    $this->table = $table;
    $this->name = $name;
    $this->dataType = $info['udt_name'] ?? $info['data_type'];
    $this->character_maximum_length = $info['character_maximum_length'];
    //$this->numeric_precision = $info['numeric_precision_radix'] ?? $info['numeric_precision'];
    $this->numeric_precision = $info['numeric_precision'];
    $this->numeric_scale = $info['numeric_scale'];
    $this->comment = $info['column_comment'] /*MySql*/ ?? null /*PgSql*/;
    $this->is_nullable = ($info['is_nullable'] == 'YES');
    if (isset($info['column_key'])) {
      if ($info['column_key'] == 'PRI')
        $this->indexed = 'primary';
      elseif ($info['column_key'] == 'UNI')
        $this->indexed = 'unique';
      elseif ($info['column_key'] == 'MUL')
        $this->indexed = 'multiple';
      elseif ($info['column_key'] == '')
        $this->indexed = 'none';
      else
        throw new Exception("valeur $info[column_key] non prévue");
    }
    $this->info = $info;
    //print_r($info);
  }
  
  function __get(string $name) { return isset($this->$name) ? $this->$name : null; }

  function asArray(): array {
    return [
      //'name'=> $this->name,
      'dataType'=> $this->dataType
        .match($this->dataType) {
          'varchar'=> "($this->character_maximum_length)",
          'decimal'=> "($this->numeric_precision,$this->numeric_scale)",
          'numeric'=> "($this->numeric_precision,$this->numeric_scale)",
          default => '',
        },
    ]
    + ($this->comment ? ['comment'=> $this->comment] : [])
    + [
      'nullable'=> $this->is_nullable ? 'YES' : 'NO',
      'indexed'=> $this->indexed ?? 'unknown',
      //'info'=> $this->info,
    ];
  }
  
  function hasGeometryType(): bool { return in_array($this->dataType, self::OGC_GEOM_TYPES); }
};


if ((__FILE__ <> realpath($_SERVER['DOCUMENT_ROOT'].$_SERVER['SCRIPT_NAME'])) && (($argv[0] ?? '') <> basename(__FILE__))) return;
echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>sqlschema.inc.php</title></head><body><pre>\n";


if (0) { // Tests de base
  $table_names = [];
  //$table_names = ['noeud_ferre'];
  $mySqlSchema = new Schema('mysql://bdavid@mysql-bdavid.alwaysdata.net/bdavid_route500', $table_names);
  $pgSqlSchema = new Schema('pgsql://benoit@db207552-001.dbaas.ovh.net:35250/route500/public', $table_names);

  //print_r($schema);
  echo Yaml::dump(['mySql'=> $mySqlSchema->asArray()], 10, 2);
  echo Yaml::dump(['pgSql'=> $pgSqlSchema->asArray()], 10, 2);
  //echo Yaml::dump(['mySql'=> $mySqlSchema->tables['communication_restreinte']->asArray()], 10, 2);
  //echo Yaml::dump(['pgSql'=> $pgSqlSchema->tables['communication_restreinte']->asArray()], 10, 2);
}
elseif (0) {
  // liste les types trouvés
  /*
    oid: 2848
    int2: 236
    bool: 1279
    float4: 92
    int4: 1718
    _float4: 56
    anyarray: 64
    name: 4888
    char: 452
    regproc: 408
    pg_node_tree: 156
    text: 1185
    _aclitem: 168
    _oid: 108
    timestamptz: 448
    abstime: 8
    _text: 254
    pg_lsn: 152
    int8: 2052
    oidvector: 60
    _char: 32
    xid: 132
    _int2: 24
    int2vector: 60
    pg_ndistinct: 12
    pg_dependencies: 12
    bytea: 16
    _name: 32
    regtype: 12
    _regtype: 12
    interval: 60
    inet: 24
    float8: 112
    varchar: 3981
    _float8: 18
    _bool: 10
    geometry: 76
    bpchar: 23
    cheflieu_source: 1
    jsonb: 8
    statutentite: 2
    numeric: 59
  */
  $dataTypes = [];
  $secrets = require(__DIR__.'/secret.inc.php');
  foreach (array_keys($secrets['sql']) as $server) {
    if (in_array($server, ['pgsql://bdavid@postgresql-bdavid.alwaysdata.net'])) {
      echo "serveur $server skipped\n";
      continue;
    }
    echo "server $server\n";
    foreach (($catalogs = Schema::listOfPgCatalogs($server) ?? [$server]) as $catalog) {
      echo "$server\n";
      try {
        foreach (Schema::listOfSchemas($catalog) as $name => $schemaUri) {
          echo "$schemaUri\n";
          $schema = new Schema($schemaUri);
          foreach ($schema->tables as $table_name => $table) {
            echo "table $table\n";
            foreach ($table->columns as $cname => $column) {
              echo "  $cname -> $column->dataType\n";
              if (!isset($dataTypes[$column->dataType]))
                $dataTypes[$column->dataType] = 1;
              else
                $dataTypes[$column->dataType]++;
            }
          }
        }
      }
      catch (Exception $e) {
        echo "Erreur sur $server ",$e->getMessage(),"\n";
      }
    }
  }
  echo Yaml::dump($dataTypes);
  die();
}
else { // Navigation dans serveur / catalogue / schéma / table / description / contenu 
  if (!($schema = $_GET['schema'] ?? null)) { // choix d'un schéma
    if (!($catalog = $_GET['catalog'] ?? null)) { // choix d'un catalogue 
      if (!($server = $_GET['server'] ?? null)) { // choix d'un des serveurs définis dans secret.inc.php
        $secrets = require(__DIR__.'/secret.inc.php');
        //print_r($secrets['sql']);
        echo "Servers:\n";
        foreach (array_keys($secrets['sql']) as $userServer) {
          //$userServer = substr($userServer, 0, -1); // j'enlève le / final
          echo "  - <a href='?server=",urlencode($userServer),"'>$userServer</a>\n";
        }
        die();
      }
      // sur PgSql choix d'une des bases=catalogues du serveur
      elseif ($catalogs = Schema::listOfPgCatalogs($server)) {
        echo "Bases de $server:\n";
        foreach ($catalogs as $catName => $catUri) {
          echo "  - <a href='?catalog=",urlencode($catUri),"'>$catName</a>\n";
        }
        die();
      }
      // sur MySql il n'y a qu'un seul catalogue par serveur
      else {
        $catalog = $server;
      }
    }
    echo "Schémas du catalogue (serveur MySql ou base PgSql) $catalog:\n";
    foreach (Schema::listOfSchemas($catalog) as $name => $schemaUri)
      echo "  - <a href='?schema=".urlencode($schemaUri)."'>$name</a>\n";
    die();
  }
  elseif (!($table = $_GET['table'] ?? null)) { // choix d'une des tables du schema
    echo "Tables de $schema:\n";
    $schema = new Schema($schema);
    foreach ($schema->tables as $table_name => $table)
      echo "  - <a href='?table=$table_name&amp;schema=".urlencode($_GET['schema'])."'>$table_name</a>\n";
    //print_r($schema);
    die();
  }
  elseif (null === ($offset = $_GET['offset'] ?? null)) { // Description de la table
    echo "Table $schema/$table:\n";
    echo "<a href='?offset=0&amp;limit=20&amp;table=$table&amp;schema=",urlencode($schema),"'>",
        "Affichage du contenu de la table</a>.\n";
    $schema = new Schema($schema, ['table_names'=> [$table]]);
    echo Yaml::dump(['schema'=> $schema->tables[$table]->asArray()], 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    die();
  }
  else { // affichage du contenu de la table à partir de offset
    $limit = (int)($_GET['limit'] ?? 20);
    if (!($sql = $_GET['sql'] ?? null) || ($_SERVER['HTTP_HOST'] <> 'localhost')) {
      // uniquement sur localhost possibilité de fournir une requête Sql 
      $schema = new Schema($schema, ['table_names'=> [$table]]);
      $columns = [];
      foreach ($schema->tables[$table]->columns as $cname => $column) {
        if ($column->hasGeometryType())
          $columns[] = "ST_AsGeoJSON($cname) $cname";
        else
          $columns[] = $cname;
      }
      $sql = "select ".implode(', ', $columns)."\nfrom $table";
    }
    else {
      Sql::open($schema);
    }
    //echo "sql=$sql\n";
    $url = "table=$table&amp;schema=".urlencode($schema);
    echo "</pre><h2>$schema/$table</h2>\n";
    if ($_SERVER['HTTP_HOST'] == 'localhost') // uniquement sur localhost possibilité de fournir une requête Sql
      echo "<form><table border=1><tr>",
        "<input type='hidden' name='offset' value='0'>",
        "<input type='hidden' name='limit' value='$limit'>",
        "<td><textarea name='sql' rows='5' cols='130'>$sql</textarea></td>",
        "<input type='hidden' name='table' value='$table'>",
        "<input type='hidden' name='schema' value='$schema'>",
        "<td><input type=submit value='go'></td>",
        "</tr></table></form>\n";
    echo
      "<a href='?$url'>^</a> ",
      ((($offset-$limit) >= 0) ? "<a href='?offset=".($offset-$limit)."&amp;$url'>&lt;</a>" : ''),
      " offset=$offset ",
      "<a href='?offset=".($offset+$limit)."&amp;$url'>&gt;</a>",
      "<table border=1>\n";
    echo "</pre><table border=1>\n";
    $no = 0;
    //echo "sql=$sql\n";
    foreach (Sql::query("$sql\nlimit $limit offset $offset") as $tuple) {
      if (!$no++)
        echo '<th>', implode('</th><th>', array_keys($tuple)),"</th>\n";
      echo '<tr><td>', implode('</td><td>', $tuple),"</td></tr>\n";
    }
    echo "</table>\n";
    die();
  }
  die();
}
