# Package Php contenant différents classes et fonctions Php utiles

Ce package comprend différentes classes et fonctions Php, notamment :

  - la classe statique SQL qui simplifie l'utilisation d'une base de données Sql (MySql ou PgSql).
    Elle expose notamment 2 méthodes:
    
      - `static open(string $params): void` qui ouvre la connexion MySQL en fonction des paramètres
        sous la forme d'un URI respectant les motifs:
        - pour MySql "mysql://{user}(:{passwd})?@{host}(/{database})?"
        - pour PgSql
          - "pgsql://{user}(:{passwd})?@{host}(:{port})?/{database}(/{schema})?" ou
          - "host={host} dbname={database} user={user}( password={passwd})?"
        Si le mot de passe n'est pas fourni alors il doit être défini dans le fichier secret.inc.php
      - `static query(string|array $sql, array $options=[])` qui exécute une requête SQL et renvoie soit TRUE
        si la requête ne renvoie pas de n-uplets, soit un objet qui peut être itéré pour obtenir les n-uplets.
  
    En cas d'erreur une exception est lancée.
    
  - la classe Schema (dans l'espace de nom Sql) qui simplifie la consultation du schéma d'une base MySql/PgSql.
    Un schéma est construit en utilisant la méthode __construct(string $uri, array $options=[])
    où le paramètre $uri doit respecter un des motifs:
      - 'mysql://{user}(:{passwd})?@{host}/{database}'
      - 'pgsql://{user}(:{passwd})?@{host}(:{port})?/{database}/{schema}'
  
  - le composant [ophir.php](https://github.com/benoitdavidfr/phplib/blob/master/ophir.php) permet d'afficher en HTML
    un document ODT,
    il est extrait de [https://github.com/j6s/ophir.php](https://github.com/j6s/ophir.php).  
    Il est appelé au travers de la fonction :
    
      - `odt2html(string $path): string` qui prend en paramètre le chemin du document ODT et renvoie le document HTML.
      
