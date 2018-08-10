<?php
/*PhpDoc:
  name:  httpcachsql.inc.php
  title: httpcachsql.inc.php - lecture HTTP avec un cache stocké dans une base SQLite - 23/2/2015
  includes: [ httpreqst.inc.php ]
  doc: |
    Un cache est défini dans une base SQLite par:
    - une table (id,content) dont la clé id est le MD5 de l'URL et la valeur content est le contenu bzippé du fichier,
    - une table (dh,message) de messages horodatés d'évènement associés:
      - ouverture/fermeture de la session
      - erreurs rencontrées

    Un objet Cache correspond à une session d'utilisation d'un cache

    Gestion des exceptions:
    Les exceptions provenant de la classe HttpRequest sont interceptées, enregistrées dans le fichier de logs et relancées.
*/

require_once dirname(__FILE__).'/httpreqst.inc.php';

class HttpCacheSQLite {
  private $httpRequest; // objet HttpRequest utilisé pour effectuer les requêtes
  private $db;          // Objet SQLite3
  private $table;       // nom de la table (id,content), utilisé avec le suffixe log pour les messages (dh,message)
  
  function __construct($dbname, $table, $httpparams=[]) {
    $this->httpRequest = new HttpRequest($httpparams);
    $this->db = new SQLite3($dbname);
    $this->db->busyTimeout(10);
    $this->table = $table;
    $this->db->exec("
      create table if not exists $table (id string primary key, content blob);
      create table if not exists ${table}log (dh string, message string);
    ");
  }
  
// écrit un message dans le fichier de logs
  function log($message) {
    $st = $this->db->prepare('insert into '.$this->table.'log(dh,message) values(?,?)');
    $dh = date('Y-m-d').'T'.date('H:i:s');
    $st->bindParam(1, $dh, SQLITE3_TEXT);
    $st->bindParam(2, $message, SQLITE3_TEXT);
    $result = $st->execute();
  }
  
// Traite une erreur
  private function error($message) {
    $this->log($message);
// en mode développement, il est utile d'afficher la trace des appels ayant généré l'erreur
    throw new Exception($message);
// en mode production, il est préférable d'afficher le message d'erreur et de s'arrêter
    echo "<pre>";
    die ($message);
  }
  
// essaie de lire le fichier identifié par l'URL dans le cache, s'il existe renvoie son contenu sinon renvoie NULL
  function readFromCache($url) {
//    echo "getFromCache($url)<br>\n";
    $md5 = md5($url);
    $st = $this->db->prepare("select content from ".$this->table." where id=?;");
    $st->bindParam(1, $md5, SQLITE3_TEXT);
    $result = $st->execute();
    if ($row = $result->fetchArray(SQLITE3_ASSOC))
      return $row['content'];
    else
      return NULL;
  }
  
// Ecrit dans le cache le fichier correspondant à l'URL et ayant le contenu fourni
// Crée le répertoire si nécessaire
  function writeToCache($url, $content) {
    $md5 = md5($url);
//    echo "content=$content<br>\n";
    $st = $this->db->prepare('insert or replace into '.$this->table.'(id,content) values(?,?)');
    $st->bindParam(1, $md5, SQLITE3_TEXT);
    $st->bindParam(2, $content, SQLITE3_TEXT);
    $result = $st->execute();
  }
  
// Supprime un fichier dans le cache, renvoie true en cas de succès, false sinon
  function delfile($geturl) {
    $md5 = md5($geturl);
    $this->db->exec("delete from ".$this->table." where id='$md5';");
  }
  
// Lit une URL en GET
  function get($url) {
    if (!($result = $this->readFromCache($url))) {
//      echo "NOT in cache $url\n";
      try {
        $result = $this->httpRequest->get($url);
      } catch (Exception $e) {
        $this->error($e->getMessage());
      }
      $this->writeToCache($url, $result);
    }
//    else echo "IN cache $url\n";
    return $result;
  }
  
// Lit une URL en POST
// geturl est utilisé uniquement pour identifier le fichier dans le cache
  function post($geturl, $url, $content) {
    if (!($result = $this->readFromCache($geturl))) {
      try {
        $result = $this->httpRequest->post($url, $content);
      } catch (Exception $e) {
        $this->error($e->getMessage());
      }
      $this->writeToCache($geturl, $result);
    }
    return $result;
  }
}

// Code de test unitaire de cette classe
if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;
$httptester = 'http://bdavid.alwaysdata.net/harvest2/httptester.php';

try {
  if (0) {
    $httpcache = new HttpCacheSQLite('cache.db', 'cache', ['timeout'=>3, 'maxNbOfRead'=>3, 'proxyFunction'=>$proxyFunction]);
    echo $httpcache->get($httptester),"<br>\n";
    echo $httpcache->get($httptester),"<br>\n";
    $httpcache->delfile($httptester);
    die();
  }
  
  if (0) {
    $httpcache = new HttpCacheSQLite('cache.db', 'cache', ['timeout'=>3, 'maxNbOfRead'=>3, 'proxyFunction'=>$proxyFunction]);
    $urlGetRecords = 'http://www.geocatalogue.fr/api-public/servicesRest'
                 .'?service=CSW&version=2.0.2&request=GetRecords'
                 .'&ResultType=results'
                 .'&ElementSetName=brief'
                 .'&maxRecords=20'
                 .'&OutputFormat='.rawurlencode('application/xml')
                 .'&OutputSchema='.rawurlencode('http://www.opengis.net/cat/csw/2.0.2')
                 .'&TypeNames='.rawurlencode('csw:Record')
                 .'&constraintLanguage=CQL_TEXT'
                 ;
    header('Content-type: text/xml; charset="utf-8"');
    echo $httpcache->get($urlGetRecords.'&startPosition=1');
    die();
  }
  
  if (0) {
    $httpcache = new HttpCacheSQLite('cache.db', 'cache', ['timeout'=>10, 'maxNbOfRead'=>3, 'proxyFunction'=>$proxyFunction]);
    $urlGetRecordById = 'http://www.geocatalogue.fr/api-public/servicesRest'
                       .'?service=CSW&version=2.0.2&request=GetRecordById'
                       .'&ResultType=results'
                       .'&ElementSetName=full'
                       .'&OutputFormat='.rawurlencode('application/xml')
                       .'&OutputSchema='.rawurlencode('http://www.isotc211.org/2005/gmd')
                       .'&TypeNames='.rawurlencode('gmd:MD_Metadata')
                       .'&id='.rawurlencode('fr-120066022-jdd-12189544-6b5e-4ca7-b348-4d49f3dec441')
                       ;
    header('Content-type: text/xml; charset="utf-8"');
    echo $httpcache->get($urlGetRecordById);
    die();
  }
  
  if (0) {
    $httpcache = new HttpCacheSQLite('cache.db', 'cache',
      [ 'timeout'=>3,
        'maxNbOfRead'=>3,
        'contentType'=>'XML',
        'headerPattern'=>'!^<\?xml version=("1.0"|\'1.0\') encoding=["\'](UTF-8|utf-8)["\'][^>]*>\s*<csw:GetRecordByIdResponse!',
        'exceptionHandler'=>'owsExceptionHandler',
        'proxyFunction'=>$proxyFunction]);
    $postrequestfmt = "<csw:GetRecordById"
                      ." xmlns:csw='http://www.opengis.net/cat/csw/2.0.2'"
                      ." service='CSW'"
                      ." version='2.0.2'"
                      ." resultType='results'"
                      ." outputSchema='http://www.isotc211.org/2005/gmd'>"
                      ."<csw:ElementSetName>full</csw:ElementSetName>"
                      ."<csw:Id>%s</csw:Id>"
                    ."</csw:GetRecordById>";
    $id = 'fr-120066022-jdd-12189544-6b5e-4ca7-b348-4d49f3dec441';
    $postrequest = sprintf($postrequestfmt, str_replace('&','&amp;',$id));
    $result = $httpcache->post($id, 'http://www.geocatalogue.fr/api-public/servicesRest', $postrequest);
    header('Content-type: text/xml; charset="utf-8"');
    echo $result;
    $httpcache->delfile($id);
    die();
  }
  
  if (0) {
    $httpcache = new HttpCacheSQLite('cache.db', 'cache',
      [ 'timeout'=>3,
        'maxNbOfRead'=>3,
        'contentType'=>'XML',
        'headerPattern'=>'!^<\?xml version=("1.0"|\'1.0\') encoding=["\'](UTF-8|utf-8)["\'][^>]*>\s*<csw:GetRecordsResponse!',
        'exceptionHandler'=>'owsExceptionHandler',
        'proxyFunction'=>$proxyFunction]);
    $urlGetRecords = 'http://catalogue.sigloire.fr/geonetwork/srv/fre/csw'
                 .'?service=CSW&version=2.0.2&request=GetRecords'
                 .'&ResultType=results'
                 .'&ElementSetName=brief'
                 .'&maxRecords=20'
                 .'&OutputFormat='.rawurlencode('application/xml')
                 .'&OutputSchema='.rawurlencode('http://www.opengis.net/cat/csw/2.0.2')
                 .'&TypeNames='.rawurlencode('csw:Record')
                 .'&constraintLanguage=CQL_TEXT'
                 ;
    $result = $httpcache->get($urlGetRecords.'&startPosition=1');
    header('Content-type: text/xml; charset="utf-8"');
    echo $result;
    $httpcache->delfile($urlGetRecords.'&startPosition=1');
    die();
  }
  
  if (0) {
    $httpcache = new HttpCacheSQLite('cache.db', 'cache',
        [ 'timeout'=>3,
          'maxNbOfRead'=>3,
          'contentType'=>'XML',
          'proxyFunction'=>$proxyFunction]);
    $postrequestfmt = "<csw:GetRecordById"
                      ." xmlns:csw='http://www.opengis.net/cat/csw/2.0.2'"
                      ." service='CSW'"
                      ." version='2.0.2'"
                      ." resultType='results'"
                      ." outputSchema='http://www.isotc211.org/2005/gmd'>"
                      ."<csw:ElementSetName>full</csw:ElementSetName>"
                      ."<csw:Id>%s</csw:Id>"
                    ."</csw:GetRecordById>";
    $id = 'e0d64f5c-b23d-47e1-91a0-e1dc12009509';
    $postrequest = sprintf($postrequestfmt, str_replace('&','&amp;',$id));
    header('Content-type: text/xml; charset="utf-8"');
    echo $httpcache->post($id, 'http://catalogue.sigloire.fr/geonetwork/srv/fre/csw', $postrequest);
    $httpcache->delfile($id);
    die();
  }
  
// Test de l'utilisation du headerPattern et du exceptionHandler
// GetRecords sur un serveur n'acceptant pas CQL_TEXT avec un herderPattern et un exceptionHandler
  if (1) {
    $httpcache = new HttpCacheSQLite('cache.db', 'cache',
        [ 'maxNbOfRead'=>3,  // httpparams
          'headerPattern'=>'!^<\?xml version=("1.0"|\'1.0\') encoding=["\'](UTF-8|utf-8)["\'][^>]*>\s*<csw:GetRecordsResponse!',
          'exceptionHandler'=>'owsExceptionHandler',
          'proxyFunction'=>$proxyFunction
        ]);
    $httpcache->log("GetRecords sur un serveur n'acceptant pas CQL_TEXT avec un herderPattern et un exceptionHandler");
    $urlGetRecords =  'http://www.geocatalogue.fr/api-public/servicesRest'
                     // 'http://infogeo.ct-corse.fr/geoportal/csw/discovery'
                 .'?service=CSW&version=2.0.2&request=GetRecords'
                 .'&ResultType=results'
                 .'&ElementSetName=brief'
                 .'&maxRecords=20'
                 .'&OutputFormat='.rawurlencode('application/xml')
                 .'&OutputSchema='.rawurlencode('http://www.opengis.net/cat/csw/2.0.2')
                 .'&TypeNames='.rawurlencode('csw:Record')
                 .'&constraintLanguage=CQL_TEXT'
                 ;
    $result = $httpcache->get($urlGetRecords.'&startPosition=1');
    header('Content-type: text/xml; charset="utf-8"');
    die($result);
  }
  
  die("NO TEST !!!");
}
catch (Exception $e) {
  echo "<b>Exception: ",$e->getMessage(),"</b><br>\n";
}
?>