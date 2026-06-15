#!/bin/bash
set -e

# Substitue la variable PORT dans la configuration Apache au démarrage du conteneur,
# car les plateformes comme Render fournissent un port dynamique.
if [ -n "$PORT" ]; then
    sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
    sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
fi

exec "$@"
