# Utilise l'image officielle PHP avec Apache
FROM php:8.2-apache

# Active le module d'extension PDO MySQL pour la base de données
RUN docker-php-ext-install pdo pdo_mysql

# Copie tous les fichiers de ton projet dans le dossier du serveur web
COPY . /var/www/html/

# Donne les droits de lecture et d'écriture à Apache
RUN chown -R www-data:www-data /var/www/html/

# Expose le port 80 pour que ton site soit accessible sur internet
EXPOSE 80
