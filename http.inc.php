<?php
/*PhpDoc:
name: http.inc.php
title: http.inc.php - gestion de requêtes Http
doc: |
  Code simplifié par rapport à httpreqst.inc.php

  L'utilisation de l'option ignore_errors ne semble pas fonctionner systématiquement.
journal: |
  1/11/2021:
    - prise en compte de l'option timeout
  15/2/2021:
    - création
*/

/*PhpDoc: classes
name: Http
title: class Http -- gestion des requêtes Http avec les options
methods:
doc: |
*/
class Http {
  /*PhpDoc: methods
  name: buildHttpContext
  title: static function buildHttpContext(array $options) - construit le contexte http pour l'appel à file_get_contents()
  doc: |
    Les options sont celles définies pour request() ; renvoie un context
  */
  static private function buildHttpContext(array $options) {
    if (!$options)
      return null;
    $header = '';
    foreach (['referer','Content-Type','Accept','Accept-Language','Cookie','Authorization'] as $key) {
      if (isset($options[$key]))
        $header .= "$key: ".$options[$key]."\r\n";
    }
    $httpOptions = $header ? ['header'=> $header] : [];
    foreach (['method','proxy','timeout','ignore_errors','content'] as $key) {
      if (isset($options[$key]))
        $httpOptions[$key] = $options[$key];
    }
    //print_r($httpOptions);
    return stream_context_create(['http'=> $httpOptions]);
  }
  
  /*PhpDoc: methods
  name: request
  title: "static function request(string $url, array $options=[]): array"
  doc: |
    Renvoie un array constitué d'un champ 'headers' et d'un champ 'body'
    En cas d'erreur HTTP
      Si l'option ignore_errors est définie à true alors retourne l'erreur dans le headers
      sinon lève une exception.
  
    Les options possibles sont:
      'referer'=> referer à utiliser
      'Content-Type'=> Content-Type à utiliser pour les méthodes POST et PUT
      'Accept'=> liste des types MIME demandés, ex 'application/json,application/geo+json'
      'Accept-Language'=> langage demandé, ex 'en'
      'Cookie' => cookie défini
      'Authorization' => en-tête HTTP Authorization contenant permettant l'authentification d'un utilisateur
      'method'=> méthode HTTP à utiliser, par défaut 'GET'
      'proxy'=> proxy à utiliser
      'timeout'=> Délai maximal d'attente pour la lecture, sous la forme d'un nombre décimal (e.g. 10.5)
      'ignore_errors' => true // pour éviter la génération d'une exception
      'content'=> texte à envoyer en POST ou PUT
  */
  static function request(string $url, array $options=[]): array {
    //echo "Http::request($url)\n";
    if (($body = @file_get_contents($url, false, self::buildHttpContext($options))) === false) {
      if (0 && isset($http_response_header)) {
        echo "http_response_header="; var_dump($http_response_header);
      }
      throw new Exception("Erreur '".($http_response_header[0] ?? 'unknown')."' dans Http::query() : sur url=$url");
    }
    return [
      'headers'=> $http_response_header,
      'body'=> $body,
    ];
  }
  
  // Retourne le code d'erreur qui est dans la première ligne des headers
  // sauf en cas de redirection où if faut rechercher le code d'erreur dans la suite du header
  // Voir https://www.php.net/manual/fr/context.http.php
  static function errorCode(array $headers): int {
    //print_r($headers[0]); echo "\n";
    $errorCode = (int)substr($headers[0], 9, 3); // code d'erreur http dans la première ligne des headers
    if (!in_array($errorCode, [301, 302]))
      return $errorCode;
    
    // en cas de redirection, if faut rechercher le code d'erreur dans la suite du header
    for ($i = 1; $i < count($headers); $i++) {
      if (preg_match('!HTTP/1\.. (\d+)!', $headers[$i], $matches) && !in_array($matches[1], [301,302])) {
        return $matches[1];
      }
    }
    throw new Exception("Erreur, code d'erreur Http non trouvé dans Http::errorCode()");
  }
  
  /*PhpDoc: methods
  name: contentType
  title: "static function contentType(array $headers): string - extrait des headers le Content-Type"
  doc: |
  */
  static function contentType(array $headers): string {
    foreach ($headers as $header) {
      if (substr($header, 0, 14) == 'Content-Type: ')
        return substr($header, 14);
    }
    return '';
  }
};
