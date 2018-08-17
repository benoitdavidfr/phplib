# Package Php contenant différents classes et fonctions Php utiles

Ce package comprend différentes classes et fonctions Php, notamment :

  - la [classe statique MySQL](https://github.com/benoitdavidfr/phplib/blob/master/openmysql.inc.php)
    qui simplifie l'interface avec MySQL.  
    Elle expose principalement les 2 méthodes suivantes :
    
      - `static open(string $mysqlParams): void` qui ouvre la connexion MySQL en fonction des paramètres
        fournis sous la forme 'mysql://{login}:{mot_de_passe}@{serveurMySql}/{base_de_données}'
        
      - `static query(string $sql)` qui exécute une requête SQL et renvoie soit TRUE si la requête ne renvoie pas
        de n-uplets, soit un objet MySqlResult qui peut être itéré pour obtenir les n-uplets.
        
    En cas d'erreur une exception est lancée.
    
  - le composant [ophir.php](https://github.com/benoitdavidfr/phplib/blob/master/ophir.php) permet d'afficher en HTML
    un document ODT,
    il est extrait de [https://github.com/j6s/ophir.php](https://github.com/j6s/ophir.php).  
    Il est appelé au travers de la fonction :
    
      - `odt2html(string $path): string` qui prend en paramètre le chemin du document ODT et renvoie le document HTML.
      
