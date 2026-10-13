# kschroeder/spaced-tracker-api

HTTP API for [kschroeder/spaced-tracker](https://github.com/kschroeder/spaced-tracker). Routes are declared with PHP attributes, in the same spirit as FastAPI: one method, one path, one summary. The package is a PSR-15 application, so it can run alone or inside an application you already have.

Space-Track credentials stay on the server. Session login and logout do not accept a password from the client and do not return the session cookie. This is not an open proxy.

## Install

Install [kschroeder/spaced-tracker-api](https://packagist.org/packages/kschroeder/spaced-tracker-api) from Packagist. Composer installs `kschroeder/spaced-tracker` from Packagist as well:

```bash
composer require kschroeder/spaced-tracker-api:^1.0
```

The API requires the core library, `nikic/fast-route`, `nyholm/psr7`, and the PSR-15 handler and middleware interfaces.

To work on this repository, clone it and run `composer update`. The repository has no lock file. That command clones `kschroeder/spaced-tracker` from GitHub, because this checkout's `composer.json` lists that repository. Replace that entry with a path repository of `../spaced-tracker` when Composer should symlink a neighboring checkout instead. The CRT console is [kschroeder/spaced-tracker-ui](https://github.com/kschroeder/spaced-tracker-ui), also on Packagist.

## Run it

```bash
vendor/bin/spaced-tracker-api
```

That starts the PHP built-in server on `127.0.0.1:8080` with `public/` as the document root. Override the bind address with `SPACED_TRACKER_HOST` and `SPACED_TRACKER_PORT`. The host must be a hostname or IP, and the port must be 1–65535.

The server reads the process environment. It does not load a `.env` file. [`.env.example`](.env.example) lists the variables. Export them before starting the process. An empty `SPACETRACK_IDENTITY` selects demo mode.

Point a normal web server at `public/` and use `public/router.php` as the front controller when the server needs one. Apache and nginx should route every non-file request to `public/index.php`.

`Application` is the single entry class:

```php
use SpacedTracker\Api\Http\Application;

Application::fromEnvironment()->run();
```

## Demo mode

If `SPACETRACK_IDENTITY` is empty, or `SPACED_TRACKER_DEMO` is `1`, `true`, `yes`, or `on`, the API serves a fixed public catalog. No account and no network call are required. The set includes ISS (25544), Hubble (20580), Tiangong (48274), JWST (50463, labeled L2), TDRS 12 (39504), GOES 18 (51850), GPS IIF-7 (40105), and one debris object (38421), plus a historical decay and TIP row for Skylab.

`GET /health` never logs in. It returns:

```json
{"status":"ok","mode":"demo","auth":"open"}
```

`mode` is `demo` or `live`. `auth` is `open` until you set `SPACED_TRACKER_API_TOKEN`. Set that token before you expose the port. Comparison uses `hash_equals`. These paths stay public so a probe and the docs still load: `/health`, `/docs`, `/openapi.json`.

## Routes

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/health` | Liveness. Does not log in. |
| GET | `/classes` | Every request class the core library knows. |
| GET | `/classes/{controller}/{class}/modeldef` | Model definition. |
| POST | `/query` | One query. |
| POST | `/batch` | Up to 10 queries. Item `key` values are preserved. |
| GET | `/gp`, `/satcat`, `/decay`, `/tip`, `/boxscore`, `/launch-sites`, `/announcements`, `/cdm-public` | Shortcuts. |
| GET | `/files`, `/files/folders` | File-share lists. |
| DELETE | `/files/{id}` | File-share delete. |
| GET | `/public/dirs`, `/public/download?name=` | Public files. |
| POST | `/session` | Log in with the server account. No cookie is written to the browser. |
| DELETE | `/session` | Log out on the server. |
| GET | `/openapi.json`, `/docs` | Contract and a self-contained route index. No CDN. |

`POST /query` body:

```json
{
  "class": "satcat",
  "controller": "basicspacedata",
  "where": {"NORAD_CAT_ID": 25544},
  "order_by": [{"field": "LAUNCH", "direction": "desc"}],
  "limit": 20,
  "offset": 0,
  "format": "json",
  "orbit": "LEO"
}
```

`class` is required. `controller` is required only when the wire name belongs to more than one controller (`download`, `file`). `orbit` is applied after the query, because Space-Track has no regime column. JWST is reported as `L2` from the demo row's `ORBIT_REGIME`.

The response contains `records` (provider rows) and `contacts` (the table shape used by the UI): `id`, `name`, `designation`, `class` (`PAY`, `R/B`, `DEB`), `orbit`, `perigee`, `country`, `status`.

Shortcut query keys: `norad_cat_id`, `object_name`, `object_type`, `country`, `intldes`, `object_id`, `q` (name contains), `orbit`, `limit`, `offset`, `format`.

Errors follow the FastAPI shapes. A string detail is `{"detail":"..."}`. Validation is HTTP 422:

```json
{"detail":[{"loc":["body","class"],"msg":"class is required.","type":"value_error"}]}
```

Upstream Space-Track failures keep their HTTP status when it is in the 400–599 range. The raw provider body is not copied into the API response. Rate and advisory limits are HTTP 429.

## Embed

Give the application a prefix, then mount the middleware. Paths outside the prefix are delegated.

```php
use SpacedTracker\Api\Bridge\DelegateController;
use SpacedTracker\Api\Bridge\SpaceTrackApiMiddleware;
use SpacedTracker\Api\Http\Application;

$api = Application::fromEnvironment()->withPrefix('/space-track');

// PSR-15
$middleware = new SpaceTrackApiMiddleware($api);

// Slim, Mezzio, or any callable controller
$controller = new DelegateController($api);
```

An empty prefix makes the middleware handle every path. Set one when you embed it.

CORS defaults to `http://localhost:5173` and `http://127.0.0.1:5173` so the Vite dev server can call a separate PHP port. Change it with a comma-separated `SPACED_TRACKER_CORS_ORIGINS`. A `*` origin is ignored. The API never sends `Access-Control-Allow-Origin: *`.

File upload stays on the core client. A browser multipart upload would be a second place to get the folder id and file bytes wrong, and it is not required for the catalog UI.

## Logging

Pass a PSR-3 logger to `Application::fromEnvironment($env, $logger)`. It is wrapped with the core redacting logger. Each record has the method, the path after the prefix, the status, and the duration. The query string and the body are not logged, so predicate values and a mistakenly posted password do not land in the log. Unexpected exceptions log the exception class only.

## Configuration reference

| Variable | Meaning |
| --- | --- |
| `SPACETRACK_IDENTITY`, `SPACETRACK_PASSWORD` | Server-side Space-Track account. Empty identity selects demo mode. |
| `SPACED_TRACKER_DEMO` | Force demo mode even when identity is set. |
| `SPACED_TRACKER_API_TOKEN` | Bearer token for every route except health, docs, and OpenAPI. |
| `SPACED_TRACKER_API_PREFIX` | Mount prefix. `withPrefix()` overrides it in code. |
| `SPACED_TRACKER_CORS_ORIGINS` | Comma-separated browser origins. |
| `SPACED_TRACKER_HOST`, `SPACED_TRACKER_PORT` | Built-in server bind. Default `127.0.0.1:8080`. |

The live client also honors the core variables documented in `kschroeder/spaced-tracker`.
