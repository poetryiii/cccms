# 生产镜像：PHP 8.3 CLI（alpine）
#
# 与旧版的区别：
#   - 代码与依赖**打进镜像**（不再依赖 volume 挂载源码），可直接部署；
#   - composer install 单独一层，源码变更不会触发重新装依赖；
#   - 修掉了旧版 `docker-php-source delete \` 换行导致 `rm` 被当成参数的问题；
#   - redis 扩展开关化（默认装，纯 predis 方案可关掉）。
#
# 用法见 docs/02-安装部署.md。

# syntax=docker/dockerfile:1

FROM php:8.3-cli-alpine

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && sed -i 's/dl-cdn.alpinelinux.org/mirrors.aliyun.com/g' /etc/apk/repositories

# ---- PHP 扩展 ----
# 1 = 安装 pecl 的 redis 扩展（性能更好）；0 = 仅使用纯 PHP 的 predis（composer 已依赖）
ARG INSTALL_REDIS_EXT=1
RUN set -eux; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers; \
    docker-php-ext-install -j"$(nproc)" pdo pdo_mysql pcntl; \
    docker-php-ext-enable opcache; \
    if [ "$INSTALL_REDIS_EXT" = "1" ]; then \
        pecl install redis; \
        docker-php-ext-enable redis; \
    fi; \
    apk del .build-deps; \
    rm -rf /var/cache/apk/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# ---- 依赖层（利用构建缓存：只有依赖清单变化才重装）----
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
        --no-scripts

# ---- 源码层 ----
COPY . .

# 生产默认关闭 debug（异常详情不外泄）；本地调试可在运行时覆盖为 true
ENV APP_DEBUG=false

EXPOSE 8787

HEALTHCHECK --interval=30s --timeout=3s --start-period=20s --retries=3 \
    CMD wget -q -O- http://127.0.0.1:8787/ping > /dev/null 2>&1 || exit 1

CMD ["php", "start.php", "start"]
