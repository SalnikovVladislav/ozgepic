# Алтын Кесе — Docker шаблон

Полная сборка: Telegram-бот + публичный сайт с игрой + админка + приватный backend API + синхронизация в Google Sheets. Всё в одном `docker compose`.

## Архитектура

```
         INTERNET
            │
            ▼
┌───────────────────────┐
│   site  (nginx + php) │   ← публичный (порт 80)
│  • kese.php — игра    │
│  • admin/, stats, users
│  • /api/public.php    │  ── проксирует только CHECK_TOKEN / REVEAL / GET_PRIZES
└──────────┬────────────┘
           │ internal docker net
           ▼
┌────────────────────┐       ┌──────────────┐
│  api (nginx+php)   │ ◀───► │  bot (node)  │ ── Telegram
│  приватный,        │       └──────────────┘
│  X-API-Token       │◀─── sheets-sync (node) → Google Sheets
└──────────┬─────────┘
           ▼
        ┌────┐
        │ db │  MySQL 8 (не торчит в интернет)
        └────┘
```

**Почему это безопаснее, чем раньше?**
* Админские и «серверные» action-ы API (create_attempt, register, reset_attempts, CRUD призов и т.д.) больше **не висят на публичном URL**. Они живут в контейнере `api`, который недоступен из интернета — только из соседних контейнеров, и только с заголовком `X-API-Token`.
* Публичная страница `kese.php` дергает лишь белый список действий через тонкий прокси (`site/public/api/public.php`).
* Креды БД, токен бота, пароль админки — только в `.env`, не в коде.

## Быстрый старт

```bash
cd altyn-kese
cp .env.example .env
# отредактируйте .env — обязательно:
#   DB_PASSWORD, DB_ROOT_PASSWORD, API_INTERNAL_TOKEN (openssl rand -hex 32),
#   TELEGRAM_BOT_TOKEN, ADMIN_PASSWORD, SITE_PUBLIC_URL

# положите service-account.json в sheets-sync/secrets/
cp /path/to/service-account.json sheets-sync/secrets/service-account.json

docker compose up -d --build
docker compose logs -f bot
```

Сайт будет на `http://<ваш-ip>:80/`, админка — `http://<ваш-ip>/admin/`.

## Структура репозитория

```
altyn-kese/
├── .env.example                     # все настройки одним местом
├── docker-compose.yml
├── db/init/                         # SQL seed (при первом запуске)
├── api/                             # приватный PHP API
│   ├── public/index.php             # роутер, проверяет X-API-Token
│   └── src/
│       ├── config.php               # читает env
│       ├── db.php                   # PDO
│       └── endpoints/
│           ├── kese.php             # бизнес-действия (create_attempt, reveal...)
│           └── export.php           # json dump для sheets-sync
├── site/                            # публичный сайт + админка
│   ├── public/
│   │   ├── kese.php                 # игра
│   │   ├── api/public.php           # whitelist-прокси в приватный api
│   │   ├── admin/                   # kese_admin.php, stats.php, users.php
│   │   └── assets/images/           # ★ ВСЕ КАРТИНКИ — ЗДЕСЬ
│   └── src/auth.php                 # защита админки
├── bot/                             # Node.js Telegram бот
│   ├── src/
│   │   ├── index.js
│   │   ├── config.js                # читает env
│   │   ├── api.js                   # клиент приватного API
│   │   ├── receipt.js               # парсинг PDF чеков Kaspi
│   │   ├── handlers/                # обработчики команд/сообщений
│   │   └── templates/messages.js    # ★ ВСЕ ТЕКСТЫ — ЗДЕСЬ
│   ├── assets/                      # ★ КАРТИНКИ БОТА (celebration.jpg)
│   └── data/                        # runtime JSON (volume)
└── sheets-sync/                     # раз в N минут пишет всё в Google Sheets
```

## Как быстро что-то поменять

| Задача | Где править |
|---|---|
| Картинки игры (кесе, фон, кнопка, описание…) | `site/public/assets/images/` |
| Картинка «celebration» от бота | `bot/assets/celebration.jpg` |
| Тексты бота (приветствие, формы, поздравления) | `bot/src/templates/messages.js` |
| Цена билета / ИИН / ссылка Kaspi | `.env` (→ перезапустить `bot`) или в Telegram: `/set_price`, `/set_iin`, `/set_url` |
| Пароль админки / IP | `.env` → `ADMIN_PASSWORD`, `ADMIN_ALLOWED_IPS` |
| Призы, сессии, preset-билеты, user-призы | Админка: `/admin/` |

## Полезные команды

```bash
docker compose up -d --build       # собрать и запустить
docker compose logs -f bot         # логи бота
docker compose logs -f api         # логи PHP API
docker compose exec db mysql -u root -p kese   # в БД
docker compose restart bot         # перезапустить бота
docker compose down                # остановить всё
docker compose down -v             # ...и удалить БД
```

## Миграция со старого хостинга

1. Экспорт MySQL на старом хосте: `mysqldump -u … p-354953_kese > dump.sql`.
2. Положите `dump.sql` в `db/init/02-dump.sql` до первого `up`.
3. Перенесите `bot/used_transactions.json`, `user_states.json`, `settings.json` в `bot/data/`.
4. Перенесите `service-account.json` в `sheets-sync/secrets/`.
5. Поднимайте: `docker compose up -d --build`.

