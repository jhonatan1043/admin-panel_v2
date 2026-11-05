FROM httpd:2.4

COPY ./ /usr/local/apache2/htdocs/

COPY apache.conf /usr/local/apache2/conf/extra/apache.conf

RUN sed -i \
    's/#LoadModule proxy_module/LoadModule proxy_module/' \
    /usr/local/apache2/conf/httpd.conf && \
    sed -i \
    's/#LoadModule proxy_http_module/LoadModule proxy_http_module/' \
    /usr/local/apache2/conf/httpd.conf && \
    echo "Include conf/extra/apache.conf" >> /usr/local/apache2/conf/httpd.conf

EXPOSE 80