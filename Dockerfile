FROM php:8.3-apache

RUN a2enmod rewrite headers \
    && sed -ri 's#/var/www/html#/var/www/html/public#g' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\nServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/shiftpay.conf \
    && a2enconf shiftpay \
    && printf 'expose_php=0\nsession.use_cookies=0\n' > /usr/local/etc/php/conf.d/shiftpay.ini

WORKDIR /var/www/html
COPY src ./src
COPY migrations ./migrations
COPY bin ./bin
COPY public ./public
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
    && chown -R root:root /var/www/html \
    && chmod -R a+rX /var/www/html

ENV TZ=Europe/Prague \
    DATA_DIR=/data

VOLUME /data
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/healthz") === "ok" ? 0 : 1);'

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
