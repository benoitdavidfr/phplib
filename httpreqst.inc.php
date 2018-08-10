<?php
/*PhpDoc:
  name:  httpreqst.inc.php
  title: httpreqst.inc.php - classe HttpRequest pour effectuer simplement des requêtes HTTP
  classes:
  doc: |
    ATTENTION : pour un fonctionnement normal, la directive de configuration track_errors doit être activée
    (elle est désactivée par défaut).
*/

// définition du proxy en fonction du lieu d'exécution
$proxyFunction = null;
// Proxy au MEDDE
if (is_dir('D:/users/benoit.david')) {
  function proxyFunction($url) {
    if (preg_match('!^http://localhost/!', $url))
      return null;
    if (preg_match('!^http://([^/]*)/!', $url, $matches))
      if (preg_match('!\.i2$!', $matches[1]))
        return null;

//    return 'tcp://proxy.ritac.i2:32000';
    return 'tcp://proxy-rie.ac.i2:8080';
  }
  $proxyFunction = 'proxyFunction';
}

/*PhpDoc: classes
name:  HttpRequest
title: class HttpRequest - pour effectuer simplement des requêtes HTTP
methods:
doc: |
  La classe HttpRequest gère des requêtes HTTP GET et POST simples.
  Elle gère les erreurs de retours ainsi qu'un test de validité du message de retour.
  En effet, les web services utilisent des messages XML particuliers pour signaler les erreurs.
  Toutes les erreurs génèrent une exception la plus facile à exploiter possible.
  Dans certains cas d'erreur, la requête est itérée avant de lancer l'exception.
journal: |
  13/3/2016:
  - ajout d'une méthode open() renvoyant le flux ouvert et non le contenu référencé
  - modification de la méthode post() pour traiter le retour d'un flux comme un cas particulier
  7/11/2015: amélioration de la doc PhpDoc
  15/7/2015: ajout du traitement du code d'errur HTTP 301 et 302
  22/2/2015: version initiale
*/
class HttpRequest {
// table de transcodage de type de contenu pour un appel en POST
  private static $contentTypes = [
    'XML' => 'text/xml; charset="utf-8"',
    'HTML'=> 'text/html',
    'TXT' => 'text/plain',
    'FORM'=> 'application/x-www-form-urlencoded', // type utilisé pour les formulaires HTML
  ];
  private $timeout;       // timeout initial
  private $maxNbOfRead;   // Nbre max de lecture en cas d'erreur HTTP
  private $headers;       // Headers HTTP à ajouter
  private $contentType;   // Type de contenu envoyé: une des valeurs de HttpRequest::$contentTypes
  private $headerPattern; // Motif devant être respecté par les messages de retour
  private $exceptionHandler; // nom de la fonction à appeler en cas de non respect du motif qui va générer une exception
  private $waitAlert;     // si vrai alors affichage d'une alerte en cas d'attente

/*PhpDoc: methods
name:  __construct
title: function __construct($params=[])
doc: |
  $params est un tableau KVP. Les paramètres possibles sont:
  - timeout : délai de réponse maximum initial pour la requête (10s par défaut)
  - maxNbOfRead : nbre max de lecture en cas d'erreur (5 par défaut).
      A chaque itération le temps d'attente augmente ainsi que le délai de réponse
  - headers : liste de headers à ajouter
  - contentType : type de contenu envoyé pour les POST, doit être une des valeurs suivantes (clés de HttpRequest::$contentTypes)
    - 'XML' => 'text/xml; charset="utf-8"',
    - 'HTML'=> 'text/html',
    - 'TXT' => 'text/plain',
    - 'FORM'=> 'application/x-www-form-urlencoded', // type utilisé pour les formulaires HTML
  - proxyFunction : fonction : url -> proxy fournissant en fonction de l'URL le proxy à utiliser
                       où le proxy est de la forme 'tcp://proxy-rie.ac.i2:8080'
                          ou null si aucun proxy n'est à utiliser 
  - headerPattern : motif de test de validité du message de retour
  - exceptionHandler : en cas de non validité le handler génère une exception analysée et reformattée
  - noWaitAlert : si défini alors l'alerte en cas d'attente est supprimée
*/
  function __construct($params=[]) {
    $this->timeout = (isset($params['timeout']) ? $params['timeout'] : 10);
    $this->maxNbOfRead = (isset($params['maxNbOfRead']) ? $params['maxNbOfRead'] : 5);
    $this->headers = (isset($params['headers']) ? $params['headers'] : []);
    $this->contentType = ((isset($params['contentType']) and isset(HttpRequest::$contentTypes[$params['contentType']])) ?
                              HttpRequest::$contentTypes[$params['contentType']] : HttpRequest::$contentTypes['XML']);
    $this->proxyFunction = (isset($params['proxyFunction']) ? $params['proxyFunction'] : null);
    $this->headerPattern = (isset($params['headerPattern']) ? $params['headerPattern'] : null);
    $this->exceptionHandler = (isset($params['exceptionHandler']) ? $params['exceptionHandler'] : null);
    $this->waitAlert = (isset($params['noWaitAlert']) ? false : true);
  }
/*PhpDoc: methods
name:  post
title: function post($url, $content=null, $open=false)
doc: |
  Cette méthode effectue une requête HTTP GET si le champ content vaut null ou POST s'il n'est pas null
  Paramètres
  - url: URL d'appel
  - content: pour un POST contenu de la requête
  - open: permet de n'effectuer que l'ouverture du flux et de ne pas lire son contenu

  7 cas sont traités:
  0) retour HTTP avec code 301 ou 302
  1) retour HTTP avec code OK (200) et headerPattern absent ou vérifié
  1bis) retour HTTP avec code OK (200 ou 302) et headerPattern présent et non vérifié
  2) retour HTTP avec code 400,401,402,403 ou 404 : erreur certaine, il est inutile d'itérer
  3) retour HTTP avec code ko (<>200,403,404) : erreur éventuellement temporaire, itération utile 
  4) erreur de file_get_contents(), par exemple host inexistant ou timeout, et configuration track_errors activée
  4bis) erreur de file_get_contents() et configuration track_errors NON activée

  J'itère maxNbOfRead fois l'appel dans les cas 3 et 4.

  Une fois les itérations effectuées dans les cas 3 et 4
    SI le code de retour HTTP est 200 (cas 1) et le contenu renvoyé !== FALSE ALORS
      le contenu renvoyé par la requête HTTP est retourné comme résultat de la méthode
    SINON_SI le code de retour HTTP est défini (cas 2 et 3) ALORS
      une exception est levée avec le message d'erreur de HTTP
    SINON (cas 4 ou timeout)
      une exception est levée avec le message d'erreur de file_get_contents() ou un message par défaut
    
  Pseudo-code:
    SI la requête HTTP s'est bien déroulée ALORS
      SI elle a renvoyé un code HTTP 301 ou 302 ALORS
        Je recherche dans http_response_header la ligne correspondant au code retour de l'adresse vers laquelle le get a été renvoyée
        Je substitue au code retour HTTP ce nouveau code retour
      FIN_SI
      SI le code HTTP == 200 ALORS
        SI (le headerPattern n'est pas initialisé ou SI (headerPattern est initialisé et le retour correspond au motif)) ALORS
          les méthodes renvoient en retour la réponse HTTP
        SINON
          appel du gestionnaire d'exception avec le retour qui génèrera une exception Php
        FIN_SI
      SINON
        une exception est levée avec le message d'erreur HTTP
      FIN_SI
    SINON
      SI la directive de configuration track_errors est activée (elle est désactivée par défaut) ALORS
        une exception est levée avec le message d'erreur de $php_errormsg
      SINON
        une exception est levée avec le message d'erreur "file_get_contents($url): failed to open stream"
      FIN_SI
    FIN_SI

  Remarques:
  1) dans le cas d'un retour HTTP 400,401,402,403 ou 404,
     file_get_contents() supprime le message HTTP pour le remplacer par FALSE pour signifier une erreur
     c'est la raison pour laquelle dès que le code est différent de 200 une exception est levée
  2) en cas de timeout, file_get_contents() renvoie FALSE comme réponse avec un code HTTP 200
    dans ce cas on ne renvoie pas la réponse: on itère ou on lève une exception
*/ 
  function post($url, $content=NULL, $open=false) {
// Ne faut-il pas ajouter des Header comme le Host ?
    $http_context_options = [
      'method'=>($content <> NULL ? 'POST' : 'GET'),
      'timeout' => $this->timeout, // délai de réponse pour la première lecture, il est ensuite doublé à chaque itération
      'header' => '',
//      'request_fulluri' => True, // indispensable pour le proxy du MEDDE, n'est mis qu'en cas d'utilisation du proxy
    ];
    if ($this->headers)
      foreach ($this->headers as $header)
        $http_context_options['header'] .= $header."\r\n";
    if (($proxyFunction = $this->proxyFunction) and ($proxy = $proxyFunction($url))) {
      $http_context_options['proxy'] = $proxy;
      $http_context_options['request_fulluri'] = True; // indispensable pour le proxy du MEDDE, n'est mis qu'en cas d'utilisation du proxy
//      echo "http_context_options[proxy]=$http_context_options[proxy]<br>\n";
    }
    if ($content <> NULL) {
      $http_context_options['header'] .= "Content-Type: ".$this->contentType."\r\n";
      $http_context_options['content'] = $content;
    }
//    echo "header=$http_context_options[header]<br>\n";
    $nbRead = 0;
    $duration = 1;
    while (true) {
//      echo "<pre>http_context_options="; print_r($http_context_options); echo "</pre>\n";
      $context = stream_context_create(['http'=>$http_context_options, 'https'=>$http_context_options]);
//      echo "HttpRequest::post(url=$url)<br>\n";
      if (!$open)
        $returnMessage = @file_get_contents($url, false, $context);
      else
        $returnMessage = @fopen($url, 'r', false, $context);
      $httpErrorMessage = (isset($http_response_header[0]) ? $http_response_header[0] : null);
      $httpErrorCode = ($httpErrorMessage ? (integer)substr($httpErrorMessage, 9, 3) : 0);
//      echo "url=$url<br>\n";
//      echo "http_response_header[0]=$http_response_header[0]<br>\n";
//      echo "httpErrorCode=$httpErrorCode, returnMessage=",($returnMessage==null?'null':$returnMessage),"<br>\n";
      if (in_array($httpErrorCode, [301,302])) { // cas 0
//        echo "<pre>"; print_r($http_response_header);
        $i = 1; // index dans $http_response_header
// j'avance i jusqu'à ce que $http_response_header[$i] corresponde à un code de retour différent de 301 et 302
        while (($i < count($http_response_header))
                and ((strncmp($http_response_header[$i], 'HTTP/1.1 ', 9)<>0)
                  or in_array((integer)substr($http_response_header[$i], 9, 3), [301,302]))) {
//          echo "http_response_header[$i]=",$http_response_header[$i],"<br>\n";
          $i++;
        }
// Si $i est à la fin du tableau, je n'ai pas trouvé de code de retour différent de 301 et 302
        if ($i == count($http_response_header))
          throw new Exception("Error in HttpRequest::post() : httpErrorCode not found");
// Sinon, $i pointe vers un tel code de retour
        $httpErrorMessage = $http_response_header[$i];
        $httpErrorCode = (integer)substr($httpErrorMessage, 9, 3);
      }
      if (($returnMessage !== FALSE) and ($httpErrorCode==200)) {
        if (!$this->headerPattern or preg_match($this->headerPattern, $returnMessage)) // cas 1
          return $returnMessage;
        else {
          $exceptionHandler = $this->exceptionHandler;
          $exceptionHandler($returnMessage, $this->headerPattern, $url, $content);
        }
      }
      if (in_array($httpErrorCode, [400,401,402,403,404])) // cas 2
        throw new Exception($httpErrorMessage);
      $nbRead++;
      if ($nbRead >= $this->maxNbOfRead) {
        if ($httpErrorCode) // cas 3
          throw new Exception($httpErrorMessage);
        elseif (isset($php_errormsg))
          throw new Exception($php_errormsg); // cas 4
        else // cas 4 bis
          throw new Exception("file_get_contents($url): failed to open stream");
      }
      if ($this->waitAlert)
        echo "httpErrorCode=$httpErrorCode, attente $duration s.<br>\n";
      sleep($duration);
      $duration *= 2;
      $http_context_options['timeout'] *= 2;
    }
  }
  
/*PhpDoc: methods
name:  get
title: function get($url) { return $this->post($url); }
doc: |
  Effectue un GET sans passer de contenu POST
*/
  function get($url) { return $this->post($url); }
  
/*PhpDoc: methods
name:  open
title: function open($url) { return $this->post($url, NULL, true); }
doc: |
  Effectue uniquement l'ouverture du flux et renvoie un flux ouvert et non le contenu du fichier
*/
  function open($url) { return $this->post($url, NULL, true); }
};

// transformation d'un texte XML contenant une exception OWS en une exception Php
function owsExceptionHandler($result, $headerPattern, $url, $content) {
  $result = str_replace(['<ows:','</ows:'], ['<','</'], $result);
  $exceptionReportPattern = '!^<\?xml version=("1.0"|\'1.0\') encoding=["\'](UTF-8|utf-8)["\'][^>]*>\s*<ExceptionReport!';
  if (!preg_match($exceptionReportPattern, $result)) {
    echo "headerPattern=$headerPattern\nresult=$result";
    throw new Exception("result don't match pattern in owsExceptionHandler(url=\"$url\", content=\"$content\", headerPattern=\"$headerPattern\")");
  }
  $sxe = new SimpleXMLElement($result);
  $exceptionMessage = 'OwsException: code='.(string)$sxe->Exception['exceptionCode'];
  $exceptionMessage .= ', locator='.(string)$sxe->Exception['locator'];
  foreach ($sxe->Exception->ExceptionText as $text)
    $exceptionMessage .= ", text=\"$text\"";
  throw new Exception($exceptionMessage);
}

// Code de test unitaire de cette classe
//echo '__FILE__=',__FILE__,"<br>\n";
//echo '_SERVER[PHP_SELF]=',$_SERVER['PHP_SELF'],"<br>\n";
if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;

try {
//  $httptester = 'http://localhost/geocat3/harvest2/httptester.php';
  $httptester = 'http://bdavid.alwaysdata.net/phplib/httptester.php';
//  $httptester = 'http://geoinformations.metier.i2/';
//  $httptester = 'https://forge.brgm.fr/';
 
// Test avec httptester
// Récupération OK
  if (0) {
    $httpReq = new HttpRequest(['maxNbOfRead'=>3, 'proxyFunction'=>$proxyFunction]);
    $message = $httpReq->get($httptester);
    die("message=\"$message\"<br>\n");
  }

// GetCapabilities
  if (0) {
    $httpReq = new HttpRequest(['maxNbOfRead'=>3, 'proxyFunction'=>$proxyFunction]);
    $url = "http://wxs.ign.fr/uxyi51ozcgwpy6s2nd6mutdw/inspire/csw?service=CSW&request=GetCapabilities";
    $message = $httpReq->get($url);
    header('Content-type: text/xml; charset="utf-8"');
    die($message);
  }
  
// GetRecords
  if (0) {
    $start = 1;
    $httpReq = new HttpRequest(['maxNbOfRead'=>3, 'proxyFunction'=>$proxyFunction]);
    $url = 'http://wxs.ign.fr/uxyi51ozcgwpy6s2nd6mutdw/inspire/csw'
           .'?service=CSW&version=2.0.2&request=GetRecords'
           .'&ResultType=results'
           .'&ElementSetName=brief'
           .'&maxRecords=20'
           .'&OutputFormat='.rawurlencode('application/xml')
           .'&OutputSchema='.rawurlencode('http://www.opengis.net/cat/csw/2.0.2')
           .'&TypeNames='.rawurlencode('csw:Record')
//           .($this->options['needsParameterConstraintLanguage']?'&constraintLanguage=CQL_TEXT':'')
           .'&startPosition='.$start;
    $message = $httpReq->get($url);
    header('Content-type: text/xml; charset="utf-8"');
    die($message);
  }
  
// GetRecordById
  if (0) {
    $httpReq = new HttpRequest(['maxNbOfRead'=>3, 'proxyFunction'=>$proxyFunction]);
    $id = 'IGNF_GPAOLSGazeteer.xml';
    $id = 'IGNF_INSPIRE_V_WMS.xml';
    $url = 'http://wxs.ign.fr/uxyi51ozcgwpy6s2nd6mutdw/inspire/csw'
           .'?SERVICE=CSW&VERSION=2.0.2&REQUEST=GetRecordById'
           .'&ELEMENTSETNAME=full'
           .'&OUTPUTFORMAT='.rawurlencode('application/xml')
           .'&OUTPUTSCHEMA='.rawurlencode('http://www.isotc211.org/2005/gmd')
           .'&ID='.rawurlencode($id);
    $message = $httpReq->get($url);
    header('Content-type: text/xml; charset="utf-8"');
    die($message);
  }
  
// Récupération code HTTP 404
  if (0) {
    $httpReq = new HttpRequest(['maxNbOfRead'=>3]);
    $message = $httpReq->get("$httptester?test=error");
    die("message=\"$message\"<br>\n");
  }

// Récupération code HTTP 403
  if (0) {
    $httpReq = new HttpRequest(['maxNbOfRead'=>3]);
    $message = $httpReq->get("$httptester?test=403");
    die("message=\"$message\"<br>\n");
  }

// Récupération code HTTP 302
  if (0) {
    $httpReq = new HttpRequest(['maxNbOfRead'=>3]);
    $message = $httpReq->get("$httptester?test=302");
    die("message=\"$message\"<br>\n");
  }

// Récupération code HTTP 302
  if (0) {
    echo "Récupération code HTTP 302<br>\n";
    $httpReq = new HttpRequest(['maxNbOfRead'=>3]);
//    $message = $httpReq->get('https://sig.agglo-rennesmetropole.fr/geoserver/bdu.donnees_gen/wms?SERVICE=WMS&REQUEST=GetCapabilities');
    $message = $httpReq->get('http://www.cg24.fr/?SERVICE=WMS&REQUEST=GetCapabilities');
    print_r($http_response_header);
    die("message=\"$message\"<br>\n");
  }

// Récupération code HTTP 301
  if (0) {
    echo "Récupération code HTTP 301<br>\n";
    $httpReq = new HttpRequest(['maxNbOfRead'=>3]);
//    $message = $httpReq->get("$httptester?test=301");
    $message = $httpReq->get('http://metadata.carmen.developpement-durable.gouv.fr/geosource/27/fre/metadata.show?uuid=bbb27bd3-26c2-4a8e-b3c7-3ec61533df00&SERVICE=WMS&REQUEST=GetCapabilities');
    print_r($http_response_header);
    die("message=\"$message\"<br>\n");
  }

// Récupération code HTTP 301
  if (0) {
    echo "Enchainement de plusieurs code HTTP 301/302<br>\n";
    $httpReq = new HttpRequest(['maxNbOfRead'=>3]);
    $message = $httpReq->get('http://metadata.carmen.developpement-durable.gouv.fr/geosource-17/srv/fre/metadata.show?uuid=eafa45c2-726e-4808-a0c7-8ac68039188c&SERVICE=WMS&REQUEST=GetCapabilities');
    print_r($http_response_header);
    die("message=\"$message\"<br>\n");
  }

// Récupération sleep
// le webservice simule un traitement plus long que le timeout, réponse correcte à la 3e itération
  if (0) {
    $httpReq = new HttpRequest(['timeout'=>3, 'maxNbOfRead'=>3]);
    $message = $httpReq->get("$httptester?test=sleep7s");
    die("message=\"$message\"<br>\n");
  }

// Récupération sleep
// le webservice simule un traitement plus long que le timeout, erreur
  if (0) {
    $httpReq = new HttpRequest(['timeout'=>3, 'maxNbOfRead'=>1]);
    die($httpReq->get("$httptester?test=sleep7s"));
  }

  if (0) {
    $httpReq = new HttpRequest(['maxNbOfRead'=>3]);
    die($httpReq->get('http://xxx/harvest2/httptester.php'));
  }
  
// GetCapabilities
  if (0) {
    $httpReq = new HttpRequest(['maxNbOfRead'=>3]);
    header('Content-type: text/xml; charset="utf-8"');
    die($httpReq->get('http://catalogue.sigloire.fr/geonetwork/srv/fre/csw?service=CSW&request=GetCapabilities'));
  }

// GetCapabilities erroné
  if (0) {
    $httpReturnCode = 0;
    $httpReq = new HttpRequest(['maxNbOfRead'=>3]);
    header('Content-type: text/xml; charset="utf-8"');
    die($httpReq->get('http://catalogue.sigloire.fr/geonetwork/srv/fre/csw?service=CSW&request=GetCapabilitie'));
  }

// liste des fiches
  if (0) {
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
    $httpReq = new HttpRequest(['maxNbOfRead'=>3]);
    header('Content-type: text/xml; charset="utf-8"');
    die($httpReq->get($urlGetRecords.'&startPosition=1'));
  }

// recherche d'une fiche en POST par son id
  if (0) {
    $httpReq = new HttpRequest(['maxNbOfRead'=>3, 'contentType'=>'XML']);
//    echo "<pre>httpReq="; print_r($httpReq); echo "</pre>\n";
    $postrequestfmt = "<csw:GetRecordById xmlns:gmd='http://www.isotc211.org/2005/gmd'"
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
    header('Content-type: text/xml; charset="utf-8"');
    die($httpReq->post('http://www.geocatalogue.fr/api-public/servicesRest', $postrequest));
  }
  
// Test de l'utilisation du headerPattern et du exceptionHandler
// GetRecords sur un seveur n'acceptant pas CQL_TEXT
  if (0) {
    $urlGetRecords = 'http://infogeo.ct-corse.fr/geoportal/csw/discovery'
                 .'?service=CSW&version=2.0.2&request=GetRecords'
                 .'&ResultType=results'
                 .'&ElementSetName=brief'
                 .'&maxRecords=20'
                 .'&OutputFormat='.rawurlencode('application/xml')
                 .'&OutputSchema='.rawurlencode('http://www.opengis.net/cat/csw/2.0.2')
                 .'&TypeNames='.rawurlencode('csw:Record')
                 .'&constraintLanguage=CQL_TEXT'
                 ;
    $httpReq = new HttpRequest([
      'maxNbOfRead'=>3,
      'headerPattern'=>'!^<\?xml version=("1.0"|\'1.0\') encoding=["\'](UTF-8|utf-8)["\'][^>]*>\s*<csw:GetRecordsResponse!',
      'exceptionHandler'=>'owsExceptionHandler',
    ]);
    $result = $httpReq->get($urlGetRecords.'&startPosition=1');
    header('Content-type: text/xml; charset="utf-8"');
    die($result);
  }
  
  if (1) {
    $httpparams = [
      'maxNbOfRead'=>1,
      'exceptionHandler' => 'owsExceptionHandler',
//      'headerPattern' => '!^<\?xml version=("1.0"|\'1.0\') encoding=["\'](UTF-8|utf-8|ISO-8859-1)["\'][^>]*>\s*<(csw:|)GetRecordsResponse!',
    ];
    $url = 'https://www.cigalsace.org/geonetwork/srv/eng/csw?service=CSW&version=2.0.2&request=GetRecords&ResultType=results&ElementSetName=brief&maxRecords=20&OutputFormat=application%2Fxml&OutputSchema=http%3A%2F%2Fwww.opengis.net%2Fcat%2Fcsw%2F2.0.2&TypeNames=csw%3ARecord&constraintLanguage=CQL_TEXT&startPosition=1'
                 ;
    $httpReq = new HttpRequest($httpparams);
    $result = $httpReq->get($url);
    header('Content-type: text/xml; charset="utf-8"');
    die($result);
  }
  
  die("NO TEST in ".__FILE__." line ".__LINE__);
}
catch (Exception $e) {
  echo "<b>Exception: ",$e->getMessage(),"</b><br>\n";
}
?>
