#!/usr/bin/env bash
# Обновление прод-версии из git: pull → rebuild → up.
# Сохраняет БД и сертификаты.
#
# Учитывает структуру репозитория:
#   ~/altyn-kese/              ← корень git-репо (.git/, .gitattributes, .gitignore)
#   └── altyn-kese/            ← корень проекта (docker-compose.yml и т.д.)
#       └── scripts/deploy.sh  ← вы здесь
set -e

# Папка скрипта = scripts/, родитель = корень проекта
PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

# Корень git-репо ищем вверх по дереву (на случай если структура изменится)
GIT_DIR="$(cd "$PROJECT_DIR" && git rev-parse --show-toplevel 2>/dev/null || echo "$PROJECT_DIR/..")"
GIT_DIR="$(cd "$GIT_DIR" && pwd)"

COMPOSE="docker compose -f docker-compose.yml -f docker-compose.prod.yml"

echo "▶ git pull (в $GIT_DIR)..."
cd "$GIT_DIR"
git pull --ff-only

echo "▶ Пересборка изменённых образов (в $PROJECT_DIR)..."
cd "$PROJECT_DIR"
$COMPOSE build

echo "▶ Применяю обновление (rolling recreate)..."
$COMPOSE up -d --remove-orphans

echo "▶ Сброс старых образов..."
docker image prune -f

echo "✅ Обновление применено."
$COMPOSE ps

