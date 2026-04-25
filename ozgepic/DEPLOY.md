# 🚀 Деплой ozgepic на VPS (домен ozgepic.kz)

Standalone проект — свой VPS, свой nginx, свой Let's Encrypt.

---

## Шаг 1 — DNS

В панели регистратора домена `ozgepic.kz`:
```
ozgepic.kz      A  <IP вашего VPS>
www.ozgepic.kz  A  <IP вашего VPS>
```
Подождать 5–15 мин, проверить:
```bash
dig +short ozgepic.kz
```

---

## Шаг 2 — Подготовка VPS

```bash
# Создать пользователя deploy
adduser --disabled-password --gecos "" deploy
usermod -aG docker deploy

# SSH-ключ для GitHub (под deploy)
su - deploy
ssh-keygen -t ed25519 -C "vps-ozgepic"
cat ~/.ssh/id_ed25519.pub
# Добавить ключ на github.com/settings/keys
```

---

## Шаг 3 — Клонировать и настроить

```bash
su - deploy
git clone git@github.com:SalnikovVladislav/ozgepic.git
cd ~/ozgepic/ozgepic

cp .env.prod.example .env
nano .env
# Заполнить все <ЗАПОЛНИТЕ> значения

chmod +x scripts/*.sh
mkdir -p proxy/letsencrypt proxy/certbot-www
mkdir -p sheets-sync/secrets
# Загрузить service-account.json если нужен Google Sheets
```

---

## Шаг 4 — Собрать образы

```bash
cd ~/ozgepic/ozgepic
docker compose -f docker-compose.yml -f docker-compose.prod.yml build
```

---

## Шаг 5 — Запуск + SSL

```bash
./scripts/init-letsencrypt.sh
```

Скрипт сам:
1. Создаст dummy-cert
2. Поднимет весь стек
3. Выпустит реальный Let's Encrypt сертификат
4. Перезапустит nginx

После этого `https://ozgepic.kz` работает ✅

---

## Обновление кода

```powershell
# Windows
cd Z:\reac\test4
git add .
git commit -m "что изменил"
git push
```

```bash
# VPS
cd ~/ozgepic && git pull --ff-only
cd ozgepic
docker compose -f docker-compose.yml -f docker-compose.prod.yml build
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

---

## Полезные команды

```bash
# Логи
docker compose logs -f bot
docker compose logs -f site

# Статус
docker compose -f docker-compose.yml -f docker-compose.prod.yml ps

# Зайти в БД
docker exec -it ozgepic-db-1 sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" ozgepic'

# Бэкап БД
docker exec ozgepic-db-1 sh -c \
  'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" ozgepic | gzip' \
  > ~/backup-$(date +%Y%m%d).sql.gz
```
