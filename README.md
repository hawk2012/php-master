# PHP Framework Installer

Одностраничный установщик PHP-фреймворков: один файл `install.php`, который сам определяет последнюю и доступные версии пакета на Packagist, проверяет совместимость с текущей версией PHP, ставит Composer и разворачивает проект. Работает как из консоли, так и через браузер.

Автор: **Михаил Губин**

---

## Возможности

- **Один файл, без зависимостей.** Не требует Composer, curl-расширения или сторонних библиотек — всё необходимое скачивается и проверяется самостоятельно.
- **Автоопределение версий.** Запрашивает метаданные Packagist, определяет последнюю стабильную версию, последнюю совместимую с вашим PHP и список актуальных веток.
- **23 готовых фреймворка** и возможность установить любой Composer-пакет (`vendor/package`).
- **Два режима работы:** CLI (включая интерактивный) и веб-интерфейс с защитой по ключу.
- **Проверка подписи Composer.** Инсталлятор `getcomposer.org` сверяется с SHA-384 из `composer.github.io/installer.sig`.
- **Диагностика окружения** — 11 проверок: PHP, HTTPS-клиент, `proc_open`, права записи, место на диске, Composer, расширения, HTTPS веб-интерфейса, файл блокировки, ключ доступа.
- **Обнаружение установленных проектов** — сканирует каталог и определяет фреймворк и версию по `composer.lock`, `vendor/composer/installed.php` или `.installer.json`.
- **Устойчивая работа в ограниченных окружениях.** Если расширения `curl`/`openssl` выключены, HTTP выполняется через системный `curl.exe`, `wget` или потоки.

## Требования

| Параметр | Значение |
|---|---|
| PHP | 8.1 или новее (CLI или веб-сервер) |
| Обязательные функции | `proc_open` (или `shell_exec`), `json`, `Phar` |
| Права | запись в каталог установщика |
| Сеть | доступ к `repo.packagist.org`, `getcomposer.org`, `composer.github.io` |
| Диск | от 200 МБ (Magento и Shopware — больше) |

Расширения `zip`, `mbstring`, `curl`, `openssl` желательны, но не обязательны для самого установщика. Многие фреймворки (Laravel, Symfony, CakePHP) требуют `mbstring` уже после установки — `doctor` предупредит об этом заранее.

## Быстрый старт

```bash
# 1. Список доступных фреймворков
php install.php list

# 2. Версии конкретного пакета
php install.php versions laravel/laravel

# 3. Установка (версия по умолчанию — последняя совместимая)
php install.php install laravel --dir=my-app --yes

# 4. Проверка окружения
php install.php doctor

# 5. Поиск установленных проектов
php install.php detect .
```

Без аргументов в терминале запускается интерактивный режим: выбор фреймворка по номеру, выбор версии и папки.

## Команды CLI

| Команда | Назначение |
|---|---|
| `php install.php` | интерактивная установка |
| `php install.php list [--json]` | каталог фреймворков |
| `php install.php versions <пакет> [--all] [--prerelease] [--refresh]` | версии пакета из Packagist |
| `php install.php detect [путь] [--depth=N] [--json]` | найти установленные проекты |
| `php install.php install <пакет> [опции]` | установить фреймворк |
| `php install.php composer` | установить и проверить Composer |
| `php install.php doctor [--json]` | диагностика окружения |
| `php install.php http <url>` | проверить HTTP(S)-доступность ресурса |
| `php install.php key` | показать ключ веб-доступа |
| `php install.php cache:clear` | очистить кэш метаданных Packagist |
| `php install.php --version` | версия установщика |
| `php install.php help` | справка |

### Опции команды `install`

| Опция | Описание |
|---|---|
| `--version=<версия>` | конкретная версия пакета (по умолчанию — последняя совместимая) |
| `--dir=<папка>` | целевая папка относительно каталога установщика |
| `--no-dev` | без dev-зависимостей |
| `--no-scripts` | не выполнять Composer-скрипты |
| `--no-plugins` | не загружать Composer-плагины |
| `--prefer-source` | устанавливать из исходников вместо архивов |
| `--ignore-platform-reqs` | игнорировать требования платформы |
| `--no-audit` | пропустить аудит безопасности Composer |
| `--no-blocking` | отключить блокировки политик Composer |
| `--cleanup` | удалить папку при неудачной установке |
| `--yes` | без подтверждения |

Примеры:

```bash
php install.php install symfony/skeleton --version=8.1.99 --dir=sym-app
php install.php install codeigniter --no-dev --no-scripts --cleanup --yes
php install.php versions yiisoft/app --all
php install.php install vendor/package --dir=custom-app
```

## Веб-интерфейс

Запуск локально:

```bash
php -S 127.0.0.1:8000 install.php
```

Откройте `http://127.0.0.1:8000/`. При первом обращении установщик создаёт ключ доступа; путь к файлу показывается на странице входа. Получить ключ можно и из консоли:

```bash
php install.php key
```

Интерфейс содержит три вкладки:

- **Установка** — карточки фреймворков, выбор версии, папки и опций, консоль с потоковым выводом Composer.
- **Проекты** — результаты сканирования каталога установщика.
- **Диагностика** — проверки окружения, журнал, очистка кэша, установка Composer.

### Защита веб-режима

- Вход по ключу с `hash_equals`, сессия `SameSite=Strict`, `HttpOnly`, `Secure` при HTTPS.
- Ограничение попыток входа: 5 за 10 минут на IP.
- CSRF-токен для всех изменяющих запросов.
- CSP с nonce, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`.
- Блокировка веб-интерфейса файлом `install.lock` — создайте его после завершения настройки.
- Отключение веб-режима целиком: `INSTALL_WEB=0`.

### API

Все методы требуют авторизованной сессии. Изменяющие методы — `POST` с заголовком `X-CSRF-Token`.

| Метод | Назначение |
|---|---|
| `GET ?api=registry` | каталог фреймворков |
| `GET ?api=versions&package=vendor/package` | версии и совместимость |
| `GET ?api=detect` | установленные проекты |
| `GET ?api=doctor` | диагностика и журнал |
| `GET ?api=log` | журнал установщика |
| `POST ?api=clear-cache` | очистка кэша |
| `POST ?api=composer` | установка Composer (NDJSON-поток) |
| `POST ?api=install` | установка проекта (NDJSON-поток) |

Тело запроса установки:

```json
{
  "package": "slim/slim-skeleton",
  "version": "4.5.0",
  "target": "slim-app",
  "options": { "no_dev": true, "no_scripts": true, "no_plugins": true }
}
```

## Поддерживаемые фреймворки

| ID | Фреймворк | Пакет |
|---|---|---|
| `laravel` | Laravel | `laravel/laravel` |
| `symfony` | Symfony | `symfony/skeleton` |
| `codeigniter` | CodeIgniter 4 | `codeigniter4/appstarter` |
| `yii2-basic` | Yii 2 Basic | `yiisoft/yii2-app-basic` |
| `yii2-advanced` | Yii 2 Advanced | `yiisoft/yii2-app-advanced` |
| `yii3` | Yii 3 | `yiisoft/app` |
| `cakephp` | CakePHP | `cakephp/app` |
| `slim` | Slim 4 | `slim/slim-skeleton` |
| `api-platform` | API Platform | `api-platform/api-platform` |
| `laminas` | Laminas MVC | `laminas/laminas-mvc-skeleton` |
| `mezzio` | Mezzio | `mezzio/mezzio-skeleton` |
| `nette` | Nette | `nette/web-project` |
| `spiral` | Spiral | `spiral/app` |
| `flight` | Flight | `flightphp/skeleton` |
| `fatfree` | Fat-Free | `bcosca/fatfree` |
| `drupal` | Drupal | `drupal/recommended-project` |
| `craft` | Craft CMS | `craftcms/craft` |
| `statamic` | Statamic | `statamic/statamic` |
| `contao` | Contao | `contao/managed-edition` |
| `typo3` | TYPO3 | `typo3/cms-base-distribution` |
| `shopware` | Shopware 6 | `shopware/production` |
| `pimcore` | Pimcore | `pimcore/skeleton` |
| `magento` | Magento 2 | `magento/project-community-edition` |

Вместо ID можно указать имя пакета напрямую — установщик проверит его на Packagist:

```bash
php install.php versions roots/wordpress
php install.php install roots/wordpress --dir=wp
```

## Как определяется версия

1. Запрос `https://repo.packagist.org/p2/{vendor}/{package}.json`.
2. Разворачивание minified-формата Composer 2.0 (каждая запись дополняется предыдущей).
3. Отбрасывание нестабильных версий (если не указан `--prerelease`) и веток вида `dev-*`.
4. Проверка `require.php` через собственный матчер ограничений: `^`, `~`, `>=`, `<=`, `>`, `<`, `!=`, `==`, диапазоны `X - Y`, wildcard `*` и `.x`.
5. Выбор последней совместимой версии как значения по умолчанию; в списке веток показываются 13.x, 12.x и т. д.

Кэш метаданных живёт 900 секунд (`INSTALL_CACHE_TTL`). При недоступности Packagist используется устаревший кэш возрастом до 7 дней, о чём выводится предупреждение. Сбросить кэш: `php install.php cache:clear`.

## Переменные окружения

| Переменная | По умолчанию | Назначение |
|---|---|---|
| `INSTALL_BASE_DIR` | каталог `install.php` | корень, внутри которого создаются проекты |
| `INSTALL_TOOLS_DIR` | системный temp | каталог для Composer, кэша, ключа и журнала |
| `INSTALL_KEY` | — | ключ веб-доступа (переопределяет файл) |
| `INSTALL_KEY_FILE` | `<tools>/install.key` | путь к файлу ключа |
| `INSTALL_LOG` | `<tools>/install.log` | журнал |
| `INSTALL_LOCK_FILE` | `<base>/install.lock` | файл блокировки веб-интерфейса |
| `INSTALL_CACHE_TTL` | `900` | время жизни кэша Packagist, секунд |
| `INSTALL_SCAN_DEPTH` | `3` | глубина сканирования проектов (1–6) |
| `INSTALL_TIMEOUT` | `1800` | таймаут Composer, секунд |
| `INSTALL_CAFILE` | — | путь к CA-файлу для HTTPS |
| `INSTALL_INSECURE` | `0` | отключить проверку TLS-сертификата |
| `INSTALL_WEB` | `1` | `0` — полностью отключить веб-интерфейс |
| `INSTALL_ALLOW_LOCKED` | `0` | игнорировать `install.lock` |
| `INSTALL_TRUST_PROXY` | `0` | доверять `X-Forwarded-Proto` |
| `INSTALL_TZ` | `Europe/Moscow` | часовой пояс |
| `INSTALL_DEBUG` | `0` | трассировка ошибок в CLI |
| `COMPOSER_BINARY` | — | путь к существующему `composer.phar` |

Пример:

```powershell
$env:INSTALL_BASE_DIR = 'D:\sites'
$env:INSTALL_TIMEOUT = '3600'
php install.php install laravel --dir=shop --yes
```

## Служебные файлы

| Файл | Где | Назначение |
|---|---|---|
| `install.key` | tools-каталог | ключ веб-доступа, права `0600` |
| `install.log` | tools-каталог | журнал операций и ошибок |
| `composer.phar` | tools-каталог | Composer с проверенной подписью |
| `install-process.lock` | tools-каталог | защита от параллельных установок |
| `.installer.json` | каталог проекта | метаданные установки: пакет, версия, опции, дата |
| `install.lock` | корень установщика | блокировка веб-интерфейса |

`.installer.json` используется командой `detect` как приоритетный источник версии — он точнее, чем разбор `composer.lock`.

## Ограничения

- Установка возможна только в папку внутри `INSTALL_BASE_DIR`; абсолютные пути и `..` отклоняются.
- Имена папок проверяются на зарезервированные слова Windows (`con`, `nul`, `com1` и др.) и на конфликт со служебными файлами установщика.
- Одновременно выполняется только одна установка — защита через `flock`.
- Магазинные платформы (Magento, Shopware, Pimcore) требуют существенно больше ресурсов; используйте `INSTALL_TIMEOUT` для увеличения таймаута.
- Фреймворки, требующие `mbstring` или `intl`, не заработают, пока расширения не включены в `php.ini`; сам установщик при этом отработает корректно.

## Разработка

```bash
php -l install.php            # проверка синтаксиса
php install.php doctor        # состояние окружения
php install.php list --json   # машинно-читаемый каталог
```

Файл разбит на секции: конфигурация и утилиты, HTTP-слой, кэш, работа с Packagist и версиями, каталог фреймворков, Composer, обнаружение проектов, установка, диагностика, CLI, веб-интерфейс и API.

---

Разработал: **Михаил Губин**
