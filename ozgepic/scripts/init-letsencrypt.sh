#!/usr/bin/env bash
# =====================================================================
#  Первичная установка Let's Encrypt сертификата
#  Запускать один раз после того как:
#   1) DNS A-запись домена указывает на IP этого VPS
#   2) Заполнен .env (DOMAIN, EXTRA_DOMAINS, LETSENCRYPT_EMAIL)
#   3) docker compose собран (docker compose build)
# =====================================================================
set -e

cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
  echo "❌ .env не найден. Скопируйте .env.prod.example → .env"
  exit 1
fi
set -a; . ./.env; set +a

if [ -z "$DOMAIN" ] || [ -z "$LETSENCRYPT_EMAIL" ]; then
  echo "❌ В .env должны быть заданы DOMAIN и LETSENCRYPT_EMAIL"
  exit 1
fi

COMPOSE="docker compose -f docker-compose.yml -f docker-compose.prod.yml"
CERT_DIR="./proxy/letsencrypt/live/$DOMAIN"
STAGING=${STAGING:-0}   # export STAGING=1 для теста против staging LE

# --- Собираем список -d аргументов -----------------------------------
DOMAIN_ARGS="-d $DOMAIN"
for d in $EXTRA_DOMAINS; do DOMAIN_ARGS="$DOMAIN_ARGS -d $d"; done

echo "▶ Домены: $DOMAIN $EXTRA_DOMAINS"
echo "▶ Email:  $LETSENCRYPT_EMAIL"
echo "▶ Staging: $STAGING"

# --- 1) Временный самоподписанный cert, чтобы nginx смог стартовать ---
if [ ! -f "$CERT_DIR/fullchain.pem" ]; then
  echo "▶ Создаю dummy-cert для первоначального старта nginx..."
  mkdir -p "$CERT_DIR"
  docker run --rm -v "$PWD/proxy/letsencrypt:/etc/letsencrypt" \
    alpine/openssl req -x509 -nodes -newkey rsa:2048 -days 1 \
    -keyout "/etc/letsencrypt/live/$DOMAIN/privkey.pem" \
    -out    "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" \
    -subj "/CN=$DOMAIN" >/dev/null 2>&1
fi

# --- 2) Поднимаем весь стек (nginx уже стартует с dummy-cert) ---------
echo "▶ Поднимаю стек..."
$COMPOSE up -d

# --- 3) Ждём пока nginx отвечает (любой HTTP-код = уже поднят) --------
echo "▶ Жду пока proxy отвечает на HTTP..."
for i in $(seq 1 30); do
  CODE=$(curl -o /dev/null -s -w '%{http_code}' --max-time 3 "http://127.0.0.1/" || echo "000")
  if [ "$CODE" != "000" ]; then
    echo "▶ Proxy отвечает (HTTP $CODE) — продолжаю."
    break
  fi
  sleep 2
done

# --- 4) Удаляем dummy и просим реальный cert --------------------------
echo "▶ Удаляю dummy-cert..."
rm -rf "./proxy/letsencrypt/live/$DOMAIN" \
       "./proxy/letsencrypt/archive/$DOMAIN" \
       "./proxy/letsencrypt/renewal/$DOMAIN.conf"

STAGE_FLAG=""
[ "$STAGING" = "1" ] && STAGE_FLAG="--staging"

echo "▶ Запрашиваю сертификат Let's Encrypt..."
$COMPOSE run --rm --entrypoint "\
  certbot certonly --webroot -w /var/www/certbot \
    $STAGE_FLAG \
    --email $LETSENCRYPT_EMAIL \
    --agree-tos --no-eff-email \
    --force-renewal \
    $DOMAIN_ARGS" certbot

# --- 5) Перезапускаем nginx чтобы он подхватил реальный cert ---------
echo "▶ Перезагружаю nginx..."
$COMPOSE exec proxy nginx -s reload

echo "✅ Готово. Проверьте https://$DOMAIN"

