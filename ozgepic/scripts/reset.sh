#!/usr/bin/env bash
# =====================================================================
#  ПОЛНЫЙ ресет данных. ОСТОРОЖНО: удаляет БД, логи, JSON-состояния,
#  сертификаты Let's Encrypt. Сами контейнеры/образы не трогает.
# =====================================================================
set -e
cd "$(dirname "$0")/.."

read -p "❗ Это удалит БД, все состояния пользователей и сертификаты. Продолжить? [yes/NO] " ans
[ "$ans" = "yes" ] || { echo "Отменено."; exit 1; }

echo "▶ Останавливаю стек + удаляю named-volumes (db_data, api_logs)..."
docker compose -f docker-compose.yml -f docker-compose.prod.yml down -v 2>/dev/null || \
  docker compose down -v

echo "▶ Удаляю локальные JSON бота..."
rm -f bot/data/*.json

echo "▶ Удаляю TLS-сертификаты (Let's Encrypt нужно будет получить заново)..."
rm -rf proxy/letsencrypt/* proxy/certbot-www/*

echo "✅ Всё чисто. Для запуска заново: ./scripts/init-letsencrypt.sh"

