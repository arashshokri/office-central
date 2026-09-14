.PHONY: up down restart logs migrate test shell backup
up:
	docker compose up -d --build
down:
	docker compose down
restart:
	docker compose restart
logs:
	docker compose logs -f --tail=200
migrate:
	docker compose exec app php artisan migrate --force
test:
	docker compose exec app php artisan test
shell:
	docker compose exec app sh
backup:
	sudo ./centralctl.sh backup
