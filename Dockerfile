# syntax=docker/dockerfile:1

# ---- build: install locked production dependencies with the runtime PHP ----
FROM php:8.3-cli-alpine@sha256:25bcb4f1ce4f13651c0f89a6a2db2b14ac5a75555d3553fa03c83dff6d6966a8 AS build

COPY --from=composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc /usr/bin/composer /usr/bin/composer

RUN apk add --no-cache unzip

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-ansi --no-autoloader --no-scripts

COPY bin ./bin
COPY src ./src
COPY resources ./resources
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction

# ---- runtime: PHP + app only, as non-root ----
FROM php:8.3-cli-alpine@sha256:25bcb4f1ce4f13651c0f89a6a2db2b14ac5a75555d3553fa03c83dff6d6966a8

RUN apk upgrade --no-cache \
 && addgroup -S app && adduser -S -G app app

COPY --from=build /app /app

USER app

# Mount the project to analyze here: docker run --rm -v "$PWD:/project:ro" <image> /project
WORKDIR /project

ENTRYPOINT ["php", "/app/bin/analyze"]
