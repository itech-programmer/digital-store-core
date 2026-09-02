FROM php:8.3-cli-alpine

WORKDIR /app

COPY supplier-stub/public/index.php /app/public/index.php

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t public"]
