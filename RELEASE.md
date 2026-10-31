# JetCredit release build

Инсталаторът се изгражда само от Git commit, не от работното дърво. Скриптът не променя runtime файлове, WordPress настройки или база данни.

## Използване

Необходими инструменти: Bash, Git, Python 3 (стандартна библиотека), PHP CLI, Node.js, `unzip` и `sha256sum`. Composer, `vendor/` и npm dependencies не са необходими за самия builder. При нужда задайте `PHP_BIN` и `NODE_BIN` като пътища до изпълнимите файлове.

```bash
PHP_BIN=/usr/bin/php8.4 scripts/build-release.sh --ref v2.0.0
scripts/build-release.sh --ref <full-commit-sha> --output-dir /tmp/jetcredit-installers
```

По подразбиране се използва committed `HEAD`, а резултатите са в игнорираната директория `dist/`:

```text
CC_Woocommerce_JET_v.<version>.zip
CC_Woocommerce_JET_v.<version>.zip.sha256
```

Версията се чете от PHP header-а в избрания snapshot и трябва да съвпада с `JET_VERSION`, readme Stable tag и changelog секцията. Tag-ът се разрешава до commit SHA преди четене на съдържанието. Публикуваният 2.0.0 инсталатор трябва да използва одобрения и push-нат tag `v2.0.0`.

Builder-ът чете manifest и blobs от същия commit в временна staging директория. Dirty, untracked и ignored файловете не участват. Самото наличие на чисто работно дърво не заменя избора на правилния одобрен commit/tag.

## Production manifest и проверки

`scripts/release-manifest.txt` съдържа 42-те release файла. Добавянето на runtime файл в следваща версия изисква преглед и актуализация на manifest; промяна само на версията не изисква редакция на скрипта.

В ZIP има само файлове под `jetcredit/`. Включени са всички PHP helpers, JS/CSS, изображения, шрифтове, readme, changelog и каноничният `LICENSE`. Не се включват tests, scripts, Git/IDE metadata, Composer development файлове, vendor, development SBOM, логове, credentials и временни файлове.

Проверяват се липсващи/дублирани/опасни manifest пътища, забранени записи, symlinks, непосочени committed runtime файлове, version/license съгласуваност, празни файлове, PHP/JS синтаксис и основните PNG/TTF signatures. След създаването се изпълняват `unzip -t`, точна проверка на ZIP имената и сравнение на всеки файл с committed blob. SHA-256 sidecar се проверява и чрез `sha256sum --check`.

Съществуващ идентичен ZIP и checksum се приемат; различен или непълен резултат със същото име се отказва. Използвайте друга output директория вместо да презаписвате различен artifact.

Файловете са сортирани, permissions са нормализирани, а ZIP timestamps идват от commit timestamp в UTC. При еднакъв snapshot и еднакъв Python/zlib toolchain резултатът е побайтово повторяем. Различни zlib версии могат да дадат различна компресия; съдържанието винаги се сверява с Git blobs.

## Лиценз и provenance

Собственикът изрично избра `GPL-3.0-or-later` за 2.0.0 след преглед на историческото противоречие между GPLv3 LICENSE/readme и GPLv2-or-later metadata. Каноничният GPLv3 `LICENSE` е запазен. Header, readme и Composer са съгласувани; ignored `license.txt` не е release източник и не се пакетира.

Старият `sbom.cdx.json` описва development dependencies и исторически `dev-main` metadata. Той не е SBOM на инсталатора и не се включва или публикува като release SBOM. Лицензите на външните development зависимости не се променят.

## Тестове

```bash
python3 tests/release-builder.py
bash -n scripts/build-release.sh
git diff --check
```

Builder тестовете използват отделни временни synthetic Git repositories и ZIP файлове. Не създават release commits или tags в това хранилище и не достъпват магазина. Те проверяват committed source, версии, root, съдържание, изключвания, повредени файлове/архиви, откази и повторяемост.

PHP регресиите изискват PHP CLI с pcntl/pdo_sqlite за concurrency сценариите и налични WordPress/WooCommerce source файлове за изолираните core API/copy-method проверки. DOM тестовете използват React 18, jsdom 26 и jQuery от WordPress; визуалните тестове изискват Playwright/Chromium и блокират мрежата. Тези development зависимости не са plugin runtime зависимости.

Реалният MySQL counter тест изисква отделно разрешение за синтетични DB записи. Не се изпълнява като част от packaging. Изолираните тестове не заменят owner-operated E2E; конкретно проверената съвместимост и clean-install ограниченията са в readme.

## Публикуване

1. Прегледайте metadata, license и manifest; изпълнете регресиите.
2. Stage-нете само одобрените source, tests, документация и scripts. Не добавяйте dist или ignored файлове.
3. Създайте release commit и annotated tag; push-нете main и tag без force.
4. Сверете remote commit/tag SHA, след което изградете инсталатора от tag-а.
5. Проверете checksum и manifest преди предоставяне на ZIP. Не качвайте автоматично към WordPress.org или други услуги.
