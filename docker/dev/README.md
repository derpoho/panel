# Docker development (Linux)

Runs the panel in Docker on a Linux machine, without Herd: PHP 8.4,
Composer and Node 22 in one image, your checkout mounted into it.

| Service     | What it runs                     |
| ----------- | -------------------------------- |
| `panel`     | `php artisan serve` on port 8000 |
| `vite`      | `npm run dev` on port 5173       |
| `queue`     | `php artisan queue:listen`       |
| `scheduler` | `php artisan schedule:work`      |

The containers use host networking, so the panel is at
http://serveros.localhost:8000 and each workspace at
`http://{name}.serveros.localhost:8000`. Browsers send every `*.localhost`
name to 127.0.0.1 themselves, so no `/etc/hosts` entries are needed.
Files the containers write belong to your own user.

## Setting up

```bash
docker/dev/dev.sh setup   # build, .env, composer install, migrate, npm install
docker/dev/dev.sh up      # start the four services
```

`setup` points `APP_URL`, `APP_DOMAIN` and `SESSION_DOMAIN` in `.env` at
`serveros.localhost`. Then register at http://serveros.localhost:8000/register.
Mail goes to `storage/logs/laravel.log`.

## Day to day

```bash
docker/dev/dev.sh logs [panel|vite|queue|scheduler]
docker/dev/dev.sh down
docker/dev/dev.sh shell
docker/dev/dev.sh artisan migrate
docker/dev/dev.sh composer require some/package
docker/dev/dev.sh npm install some-package
docker/dev/dev.sh test             # Pest
docker/dev/dev.sh check            # everything CI runs
docker/dev/dev.sh build            # rebuild the image (new PHP/Node)
```

Ports 8000 and 5173 must be free on the machine.
