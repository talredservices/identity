FROM nginx:1.27-alpine

COPY docker/identity-server/nginx.conf /etc/nginx/conf.d/default.conf
COPY apps/identity-server/public /var/www/html/public

RUN rm -f /var/www/html/public/storage \
    && ln -s ../storage/app/public /var/www/html/public/storage
