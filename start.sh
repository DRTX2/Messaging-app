#!/usr/bin/env bash
# Starts Nexus on free ports: DB 5433, API 8100, WS 8180, web 4300
set -e
cd "$(dirname "$0")"
docker start nexus-pg >/dev/null
(cd backend && php artisan migrate --force && php artisan serve:all --port=8100 --reverb-port=8180) &
trap 'kill 0' EXIT
cd frontend && npx ng serve --port 4300
