<?php
/*PhpDoc:
name:  httpcache.inc.php
title: httpcache.inc.php - lecture HTTP avec un cache - 22/2/2015
includes: [ httpreqst.inc.php ]
classes:
*/
require_once __DIR__.'/httpreqst.inc.php';

/*PhpDoc: classes
name:  HttpCache
title: HttpCache - lecture HTTP avec un cache - 22/2/2015
uses: [ 'httpreqst.inc.php?HttpRequest' ]
methods:  
doc: |
  La classe HttpCache gère un cache des requêtes HTTP stockant dans des fichiers les résultats des requêtes qu'elle voit passer.
  Ces fichiers sont organisés dans un répertoire contenant des sous-répertoires contenant eux-mêmes les fichiers en cache.
  Pour chaque URL demandée un nom de fichier est généré par le MD5 de l'URL suivi de l'extension.
  Les sous-répertoires sont définis par les subdirLength premiers caractères du md5 de l'URL.

  Un objet Cache correspond à une session d'utilisation d'un cache.

  Pour chaque session, un journal est créé pour contenir les logs explicitement créés et les messages d'erreur.

  La classe HttpCache s'appuie sur la classe HttpRequest pour exécuter les requêtes HTTP.

  Seuls les retours considérés comme valides sont stockés, les erreurs sont gérées par des exceptions.
  Les exceptions provenant de la classe HttpRequest sont interceptées, enregistrées dans le fichier de logs et relancées.
  Outre les erreurs HTTP, la classe HttpRequest considère comme erreurs les retours qui ne respectent pas un certain motif attendu.
  Cela permet de gérer les erreurs des web services qui utilisent des messages XML particuliers pour les signaler.

journal: |
  22/2/2015:
    HttpCache ne gère plus la vérification du header de retour qui est transférée dans HttpRequest
*/
class HttpCache {
  private static $subdirLength=3;  // longueur de la partie du MD5 utilisée comme sous-répertoire => max 4096 répertoires
  private $httpRequest; // objet HttpRequest utilisé pour effectuer les requêtes
  private $cachedir; // le répertoire utilisé pour le cache
  private $logfilename; // nom du fichier de log utilisé pour la session
  private $fileext; // extension à ajouter aux noms des fichiers dans le cache
  
/*PhpDoc: methods
name: __construct
title: function __construct($cachedir, $fileext, $httpparams=[])
parameters:
  - name: cachedir
    type: string
    doc: répertoire utilisé pour le cache
  - name: fileext
    type: string
    doc: extension à ajouter aux noms des fichiers dans le cache
  - name: httpparams
    doc: paramètres pour HttpRequest
*/
  function __construct($cachedir, $fileext, $httpparams=[]) {
    $this->httpRequest = new HttpRequest($httpparams);
    $this->cachedir = $cachedir;
    $this->logfilename = $cachedir.'_harvest_errors'.date('Y-m-d').'T'.date('H-i-s').'.txt';
    $this->fileext = $fileext;
  }
  
/*PhpDoc: methods
name: log
title: function log($message) - écrit un message dans le fichier de logs
*/
  function log($message) {
    $logfile = fopen($this->logfilename, 'a')
     or die("Erreur d'ouverture du fichier de logs $logfilename dans HttpCache");
    fwrite($logfile, date('Y-m-d').'T'.date('H:i:s')." : $message\n")
     or die("Erreur d'ecriture dans le fichier de logs dans HttpCache");
    fclose($logfile);
  }
  
// Traite une erreur
  private function error($message) {
    $this->log($message);
// en mode développement, il est utile d'afficher la trace des appels ayant généré l'erreur
    throw new Exception($message);
// en mode production, il est préférable d'afficher le message d'erreur et de s'arrêter
/* Ce n'est pas acceptable, car il faut pouvoir continuer un traitement malgré une erreur sur une requête
    echo "<pre>";
    die ($message);
*/
  }
  
/*PhpDoc: methods
name: cachedir
title: function cachedir() { return $this->cachedir; }
*/  
  function cachedir() { return $this->cachedir; }
  
// essaie de lire le fichier identifié par l'URL dans le cache, s'il existe renvoie son contenu sinon renvoie NULL
  function readFromCache($url) {
//    echo "getFromCache($url)<br>\n";
    $md5 = md5($url);
    $subdir = $this->cachedir.'/'.substr($md5, 0, HttpCache::$subdirLength);
    $cachedfilename = $subdir.'/'.$md5.'.'.$this->fileext;
    if (!file_exists($cachedfilename)) {
//      echo "  return NULL<br>\n";
      return NULL;
    }
    if (!($result = file_get_contents($cachedfilename)))
      $this->error("Ouverture de $cachedfilename impossible");
    return $result;
  }
  
// Ecrit dans le cache le fichier correspondant à l'URL et ayant le contenu fourni
// Crée le répertoire si nécessaire
  function writeToCache($url, $content) {
    $md5 = md5($url);
    $subdir = $this->cachedir.'/'.substr($md5, 0, HttpCache::$subdirLength);
    if (!file_exists($subdir)) {
//      echo "Creation de $subdir<br>\n";
      mkdir($subdir);
    }
    $cachedfilename = $subdir.'/'.$md5.'.'.$this->fileext;
    if (!file_put_contents($cachedfilename, $content))
      $this->error("Erreur lors de l'écriture dans \"$cachedfilename\"");
  }
  
// Supprime un fichier dans le cache, renvoie true en cas de succès, false sinon
  function delfile($geturl) {
    $md5 = md5($geturl);
    $subdir = $this->cachedir.'/'.substr($md5, 0, HttpCache::$subdirLength);
    $cachedfilename = $subdir.'/'.$md5.'.'.$this->fileext;
    if (file_exists($cachedfilename))
      return unlink($cachedfilename);
    return false;
  }
  
/*PhpDoc: methods
name: get
title: function get($url) - Lit une URL en GET
parameters:
  - name: url
    type: string
    doc: URL a lire
*/
  function get($url) {
//    echo "HttpCache::get(url=$url)<br>\n";
    if (!($result = $this->readFromCache($url))) {
      try {
        $result = $this->httpRequest->get($url);
      } catch (Exception $e) {
        $this->error($e->getMessage()." on \"$url\" in HttpCache::get()");
      }
      $this->writeToCache($url, $result);
    }
//    echo "result=$result<br>\n";
    return $result;
  }
  
/*PhpDoc: methods
name: post
title: function post($geturl, $url, $content) - Lit une URL en POST
parameters:
  - name: geturl
    type: string
    doc: chaine utilisée pour identifier le fichier dans le cache
  - name: url
    type: string
    doc: URL a lire
  - name: content
    type: string
    doc: contenu à transmettre en POST
*/
  function post($geturl, $url, $content) {
    if (!($result = $this->readFromCache($geturl))) {
      try {
        $result = $this->httpRequest->post($url, $content);
      } catch (Exception $e) {
        $this->error($e->getMessage()
                     ." on \"$url\" with \""
                     .str_replace(['&','<','>'],['&amp;','&lt;','&gt;'],$content)
                     ."\"");
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
    $httpcache = new HttpCache('cachedir', 'txt', ['timeout'=>3, 'maxNbOfRead'=>3, 'proxyFunction'=>$proxyFunction]);
    echo $httpcache->get($httptester),"<br>\n";
    echo $httpcache->get($httptester),"<br>\n";
    $httpcache->delfile($httptester);
  }
  if (0) {
    $httpcache = new HttpCache('getRecords', 'xml', ['timeout'=>3, 'maxNbOfRead'=>3]);
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
  }
  if (0) {
    $httpcache = new HttpCache('GetRecordById', 'xml', ['timeout'=>3, 'maxNbOfRead'=>3]);
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
  }
  if (0) {
    $httpcache = new HttpCache('GetRecordById', 'xml', ['timeout'=>3, 'maxNbOfRead'=>3, 'contentType'=>'XML']);
    $headerPattern = '!<\?xml version="1.0" encoding="UTF-8" [^?]*\?>\s*<csw:GetRecordByIdResponse!';
    $postrequestfmt = "<csw:GetRecordById"
//                      ." xmlns:gmd='http://www.isotc211.org/2005/gmd'"
                      ." xmlns:csw='http://www.opengis.net/cat/csw/2.0.2'"
//                      ." xmlns:ows='http://www.opengis.net/ows'"
//                      ." xmlns:dc='http://purl.org/dc/elements/1.1/'"
//                      ." xmlns:ogc='http://www.opengis.net/ogc'"
//                      ." xmlns:dct='http://purl.org/dc/terms/'"
//                      ." xmlns:apiso='http://www.opengis.net/cat/csw/apiso/1.0'"
//                      ." xmlns:gml='http://www.opengis.net/gml'"
                      ." service='CSW'"
                      ." version='2.0.2'"
                      ." resultType='results'"
                      ." outputSchema='http://www.isotc211.org/2005/gmd'>"
                      ."<csw:ElementSetName>full</csw:ElementSetName>"
                      ."<csw:Id>%s</csw:Id>"
                    ."</csw:GetRecordById>";
    $id = 'fr-120066022-jdd-12189544-6b5e-4ca7-b348-4d49f3dec441';
    $postrequest = sprintf($postrequestfmt, str_replace('&','&amp;',$id));
    $result = $httpcache->post($id, 'http://www.geocatalogue.fr/api-public/servicesRest', $postrequest, $headerPattern);
    header('Content-type: text/xml; charset="utf-8"');
    echo $result;
    $httpcache->delfile($id);
  }
  if (0) {
    $httpcache = new HttpCache('getRecords', 'xml', ['timeout'=>3, 'maxNbOfRead'=>3]);
    $headerPattern = '!<\?xml version="1.0" encoding="UTF-8"[^?]*\?>\s*<csw:GetRecordsResponse!';
//    $headerPattern = null;
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
    $result = $httpcache->get($urlGetRecords.'&startPosition=1', $headerPattern);
    header('Content-type: text/xml; charset="utf-8"');
    echo $result;
    $httpcache->delfile($urlGetRecords.'&startPosition=1');
  }
  if (0) {
    $httpcache = new HttpCache('GetRecordById', 'xml', ['timeout'=>3, 'maxNbOfRead'=>3, 'contentType'=>'XML']);
//    $httpReq = new HttpRequest(['maxNbOfRead'=>3, 'contentType'=>'XML']);
    $postrequestfmt = "<csw:GetRecordById"
//                      ."xmlns:gmd='http://www.isotc211.org/2005/gmd'"
                      ." xmlns:csw='http://www.opengis.net/cat/csw/2.0.2'"
//                      ." xmlns:ows='http://www.opengis.net/ows'"
//                      ." xmlns:dc='http://purl.org/dc/elements/1.1/'"
//                      ." xmlns:ogc='http://www.opengis.net/ogc'"
//                      ." xmlns:dct='http://purl.org/dc/terms/'"
//                      ." xmlns:apiso='http://www.opengis.net/cat/csw/apiso/1.0'"
//                      ." xmlns:gml='http://www.opengis.net/gml'"
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
  }
  
// Test de l'utilisation du headerPattern et du exceptionHandler
// GetRecords sur un serveur n'acceptant pas CQL_TEXT avec un herderPattern et un exceptionHandler
  if (1) {
    $httpcache = new HttpCache(
        'cachedir/',   // cachedir
        'xml',        // fileext
        [ 'maxNbOfRead'=>3,  // httpparams
          'headerPattern'=>'!^<\?xml version=("1.0"|\'1.0\') encoding=["\'](UTF-8|utf-8)["\'][^>]*>\s*<csw:GetRecordsResponse!',
          'exceptionHandler'=>'owsExceptionHandler',
        ]);
    $httpcache->log("GetRecords sur un serveur n'acceptant pas CQL_TEXT avec un herderPattern et un exceptionHandler");
    $urlGetRecords = // 'http://www.geocatalogue.fr/api-public/servicesRest'
                      'http://infogeo.ct-corse.fr/geoportal/csw/discovery'
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