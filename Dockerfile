# Utilise l'image officielle PHP avec Apache
FROM php:8.2-apache

# Met à jour les paquets et installe les extensions PDO MySQL nécessaires
RUN apt-get update && apt-get install -y --no-install-recommends \
    libzip-dev \
    && docker-php-ext-install pdo pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Active le module de réécriture d'URL (utile pour le routing PHP)
RUN a2enmod rewrite

# Copie tous les fichiers de ton projet dans le dossier du serveur web
COPY . /var/www/html/

# Donne les droits de lecture et d'écriture à Apache
RUN chown -R www-data:www-data /var/www/html/

# Script qui adapte Apache au port fourni dynamiquement par la plateforme d'hébergement (ex: Render)
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Port par défaut si la plateforme n'en fournit pas
ENV PORT=80
EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
