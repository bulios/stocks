FROM martkcz/php-alpine:8.0.14-r1

USER root

RUN apk add --no-cache \
    supervisor \
    nginx \
    nginx-mod-http-brotli

COPY conf/nginx/nginx.conf /etc/nginx/
COPY conf/nginx/includes/ /etc/nginx/includes/
COPY conf/supervisor/supervisord.conf /etc/supervisor/conf.d/

## production files
COPY conf/nginx/nginx-production.conf /production/nginx/nginx.conf

RUN chown -R www-data.www-data /var/lib/nginx
RUN chown -R www-data.www-data /var/log/nginx

RUN chmod -R 777 /var/lib/nginx
RUN chmod -R 777 /var/log
RUN chmod -R 777 /app
RUN chmod -R 777 /run
RUN chmod -R 777 /home/www-data

USER www-data

EXPOSE 8080

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
