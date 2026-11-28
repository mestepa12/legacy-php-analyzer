# syntax=docker/dockerfile:1

# ---- build: install locked production dependencies with the runtime PHP ----
FROM php:8.5-cli-alpine@sha256:b9d7db51a2b83c41420ba04de0b90e6078d4b6440e5cc480fc4dc51b18e0b6db AS build

COPY --from=composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/bin/composer

RUN apk add --no-cache unzip

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-ansi --no-autoloader --no-scripts

COPY bin ./bin
COPY src ./src
COPY resources ./resources
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction

# ---- runtime: PHP + app only, as non-root ----
FROM php:8.5-cli-alpine@sha256:b9d7db51a2b83c41420ba04de0b90e6078d4b6440e5cc480fc4dc51b18e0b6db

RUN apk upgrade --no-cache \
 && addgroup -S app && adduser -S -G app app

COPY --from=build /app /app

USER app

# Mount the project to analyze here: docker run --rm -v "$PWD:/project:ro" <image> /project
WORKDIR /project

ENTRYPOINT ["php", "/app/bin/analyze"]
