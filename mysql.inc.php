<?php
/*PhpDoc:
name: mysql.inc.php
title: mysql.inc.php - classe MySql simplifiant l'utilisation de MySql
classes:
doc: |
  L'ouverture de la connexion s'effectue par la méthode statique MySql::open() qui prend en paramètre les paramètres
  de connexion MySql sous la forme mysql://{user}:{passwd}@{host}/{database} ou mysql://{user}@{host}/{database}
  Dans ce dernier cas, le mot de passe doit être enregistré dans le fichier secret.inc.php
  Le paramètre {database} est optionnel.
  Sur localhost ou docker, si la base est définie et n'existe pas alors elle est créée. 
  L'exécution d'une requête Sql s'effectue au travers de la méthode statique MySql::query() qui:
    - génère une exception en cas d'erreur
    - pour les requêtes renvoyant un ensemble de n-uplets renvoie un objet MySql pouvant être itéré pour obtenir
      chacun des n-uplets
    - pour les autres requêtes renvoie TRUE
journal: |
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
  private $sql = ''; // la requête SQL pour pouvoir la rejouer
  private $result = null; // l'objet mysqli_result
  private $ctuple = null; // le tuple courant ou null
  private $first = true; // vrai ssi aucun rewind n'a été effectué
  
  /*PhpDoc: methods
  name: open
  title: "static function open(string $params): void - ouvre une connexion MySql"
  doc: |
    ouvre une connexion MySQL et enregistre le handle en variable statique
    Il est nécessaire de passer en paramètre les paramètres de connexion MySQL
    S'ils ne contiennent pas le mot de passe alors ce dernier doit être présent dans le fichier secret.inc.php
    Si la base est définie et n'existe pas sur localhost ou docker alors elle est créée
  */
  static function open(string $params): void {
    if (!preg_match('!^mysql://([^@]+)@([^/]+)/(.*)$!', $params, $matches))
      throw new Exception("Erreur: dans MySql::open() params \"".$params."\" incorrect");
    //print_r($matches);
    $user = $matches[1]; // "{user}" ou "{user}:{passwd}"
    $server = $matches[2];
    $database = $matches[3];
    if (preg_match('!^([^:]+):(.*)$!', $user, $matches)) { // cas où le mot de passe est passé dans les paramètres
      $user = $matches[1];
      $passwd = $matches[2];
    }
    elseif (!is_file(__DIR__.'/secret.inc.php'))
      throw new Exception("Erreur: dans MySql::open($params), fichier secret.inc.php absent");
    else {
      $secrets = require(__DIR__.'/secret.inc.php');
      $passwd = $secrets['sql']["mysql://$user@$server/"] ?? null;
      if (!$passwd)
        throw new Exception("Erreur: dans MySql::open($params), mot de passe absent de secret.inc.php");
    }
    self::$mysqli = new mysqli($server, $user, $passwd);
    self::$server = $server;
    // La ligne ci-dessous ne s'affiche pas correctement si le serveur est arrêté !!!
    //    throw new Exception("Connexion MySQL impossible pour $server_name : ".mysqli_connect_error());
    if (mysqli_connect_error())
      throw new Exception("Erreur: dans MySql::open() connexion MySQL impossible sur $mysqlParams");
    if ($database && (($server == 'localhost') || preg_match('!^172\.17\.0\.[0-9]$!', $server))) {
      $sql_cr_db = "CREATE DATABASE IF NOT EXISTS `$database` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci";
      if (!(self::$mysqli->query($sql_cr_db)))
        throw new Exception ("Requete \"".$sql_cr_db."\" invalide: ".$mysqli->error);
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
  
  /*PhpDoc: methods
  name: query
  title: "static function query(string $sql)- exécute une requête MySQL, soulève une exception ou renvoie le résultat"
  doc: |
    exécute une requête MySQL, soulève une exception en cas d'erreur, sinon renvoie le résultat soit TRUE
    soit un objet MySql qui peut être itéré pour obtenir chaque n-uplet
  */
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


echo "<pre>";
//MySql::open('mysql://root:htpqrs28@172.17.0.3/route500');
//MySql::open('mysql://root@172.17.0.3/route500');
MySql::open('mysql://root@172.17.0.3/');
$sql = "select * from INFORMATION_SCHEMA.TABLES
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
