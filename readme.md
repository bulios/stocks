# Nginx-1.20.2 PHP-8.0.14 alpine

https://hub.docker.com/repository/docker/martkcz/php-nginx-alpine

## Production
```dockerfile
USER root

# PHP
RUN cp /production/php/99_settings.ini /etc/php8/conf.d/
    
# PHP-fpm    
RUN cp /production/php-fpm/www.conf /etc/php8/php-fpm.d/

# Nginx
RUN cp /production/nginx/nginx.conf /etc/nginx/

# Enables brotli
RUN cp /etc/nginx/includes/_nginx-http-brotli.conf /etc/nginx/includes/nginx-http-brotli.conf
RUN cp /etc/nginx/includes/_nginx-module-brotli.conf /etc/nginx/includes/nginx-module-brotli.conf

# Enables gzip
RUN cp /etc/nginx/includes/_nginx-http-gzip.conf /etc/nginx/includes/nginx-http-gzip.conf
# Enables asset caching
RUN cp /etc/nginx/includes/_nginx-server-cache.conf /etc/nginx/includes/nginx-server-cache.conf
# Enables http -> https
RUN cp /etc/nginx/includes/_nginx-server-https.conf /etc/nginx/includes/nginx-server-https.conf
# Enables www -> 
RUN cp /etc/nginx/includes/_nginx-server-non-www.conf /etc/nginx/includes/nginx-server-non-www.conf

USER www-data
```

## Swoole uninstall

```dockerfile
USER root

# Remove swoole
RUN rm /usr/lib/php8/modules/swoole.so && rm /etc/php8/conf.d/00_swoole.ini
    
USER www-data
```
