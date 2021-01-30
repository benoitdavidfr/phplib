<?php
/*PhpDoc:
name: sql.inc.php
title: sql.inc.php - classe Sql utilisée pour exécuter des requêtes Sql sur MySql ou PgSql
includes: [ mysql.inc.php, pgsql.inc.php ]
classes:
doc: |
  Simplification de l'utilisation de MySql et PgSql.

  MySql et PgSql ont des modèles de données différents:
    - MySql est structuré en serveur / base / table
    - PgSql est structuré en serveur / base / schéma / table

  Le concept standardisé en Sql de schéma, qui est un ensemble de tables, correspond en MySql à une base
  et en PgSql à un schéma.
  Le concept de catalogue, qui est un ensemble de schémas, correspond en MySql à un serveur et en PgSql à une base.

  Une vision homogène entre les 2 logiciels est définie en s'appuyant sur ces notion de schéma et de catalogue.
  Les URI pour un schéma respectent un des motifs:
    - pour MySql "mysql://{user}(:{passwd})?@{host}/{database}"
    - pour PgSql "pgsql://{user}(:{passwd})?@{host}(:{port})?/{database}/{schema}"
  Les URI pour un catalogue respectent un des motifs:
    - pour MySql "mysql://{user}(:{passwd})?@{host}"
    - pour PgSql "pgsql://{user}(:{passwd})?@{host}(:{port})?/{database}"

  L'articulation avec les serveurs auxquels sont attachés les user/passwd est différente entre les 2 logiciels.
  
  Sql, MySql et PgSql sont des classes statiques ce qui implique qu'un script ne peut travailler simultanément
  avec 2 catalogues différents.
journal: |
  25/1/2021:
    - amélioration utilisation des URI
  1-15/1/2021:
    - évol motif "pgsql://{user}(:{passwd})?@{host}(:{port})?/{database}(/{schema})?" de connexion
    - création Sql::toString()
  24/5/2019:
    - correction de PgSql::query()
    - amélioration de Sql::query()
  23/5/2019:
    ajout possibilité de ne pas fournir de mot de passe à la connexion s'il est stocké dans le fichier secret.inc.php
  26/4/2019 17:40
    création
*/
require_once __DIR__.'/mysql.inc.php';
require_once __DIR__.'/pgsql.inc.php';

/*PhpDoc: classes
name: Sql
title: class Sql - classe Sql utilisée pour exécuter des requêtes Sql
methods:
*/
class Sql {
  static $software = ''; // 'MySql' ou 'PgSql'
  
  /*PhpDoc: methods
  name: open
  title: "static function open(string $params) - ouverture d'une connexion à un serveur de BD"
  doc: |
    La méthode statique Sql::open() prend en paramètre les paramètres MySql ou PgSql respectant les motifs:
      - pour MySql "mysql://{user}(:{passwd})?@{host}(/{database})?"
      - pour PgSql
        - "pgsql://{user}(:{passwd})?@{host}(:{port})?/{database}(/{schema})?" ou
        - "host={host} dbname={database} user={user}( password={passwd})?"
    Si le mot de passe n'est pas fourni alors il doit être défini dans le fichier secret.inc.php
  */
  static function open(string $params) {
    if (strncmp($params, 'mysql://', 8) == 0)
      self::$software = 'MySql';
    elseif (strncmp($params, 'pgsql://', 8) == 0)
      self::$software = 'PgSql';
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
  
  static function toString(array $sql): string {
    $sqlstr = '';
    foreach($sql as $sqlelt) // je balaye chaque élt de la requete
      // si l'élt est une chaine alors je l'utilise sinon j'en prends l'élément corr. au soft courant
      $sqlstr .= is_string($sqlelt) ? $sqlelt : ($sqlelt[self::$software] ?? '');
    return $sqlstr;
  }
    
  /*PhpDoc: methods
  name: query
  title: "static function query(string|array $sql, array $options=[]) - éxécution d'une requête"
  doc: |
    La requête est soit une chaine soit une liste d'éléments,
    chacun étant une chaine ou un dictionnaire utilisant comme clé l'id du software.
    Cela permet d'écrire de manière relativement claire des requêtes dépendant du soft.
    Si la requête échoue alors renvoie une exception
    sinon si le résultat est un ensemble de n-uplets alors renvoie un objet MySql ou PgSql qui pourra être itéré
    pour obtenir chacun des n-uplets
    sinon renvoie TRUE
  */
  static function query(string|array $sql, array $options=[]) {
    if (!self::$software)
      throw new Exception('Erreur: dans Sql::query(), software non défini');
    if (is_string($sql))
      return (self::$software)::query($sql, $options);
    elseif (is_array($sql))
      return (self::$software)::query(self::toString($sql), $options);
    else
      throw new Exception('Erreur: dans Sql::query()');
  }

  static function getTuples(string|array $sql): array { // renvoie le résultat d'une requête sous la forme d'un array
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
};


if ((__FILE__ <> realpath($_SERVER['DOCUMENT_ROOT'].$_SERVER['SCRIPT_NAME'])) && (($argv[0] ?? '') <> basename(__FILE__))) return;
echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>sql.inc.php</title></head><body><pre>\n";


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
elseif (0) { // Test PgSql
  PgSql::open('pgsql://docker@172.17.0.4/gis');
  $sql = "select * from INFORMATION_SCHEMA.TABLES where table_schema='public'";
  foreach (PgSql::query($sql) as $tuple) {
    echo "tuple="; print_r($tuple);
  }
}
else { // Test Sql
  Sql::open('host=172.17.0.4 dbname=gis user=docker'); 
  $sql = "select * from INFORMATION_SCHEMA.TABLES where table_schema='public'";
  foreach (Sql::query($sql) as $tuple) {
    echo "tuple="; print_r($tuple);
  }
}