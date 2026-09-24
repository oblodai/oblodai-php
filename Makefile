# Every gate of the SDK, run locally in docker (label oblodai.sdkcheck=1; nothing else is touched).
# The PHP tools run in php:8.3-cli, composer in composer:2; caches live in the git-ignored .cache/.
# The drift check runs the backend's generator with the host's Go.
#
#   make ci                                   # vendor, drift, lint, stan, test, package
#   make ci OBLODAI_BACKEND=/path/to/backend  # the backend checkout (default ../oblodai-backend)
#   make live OBLODAI_LIVE_URL=http://…       # the live tier against a running gateway

OBLODAI_BACKEND ?= $(abspath $(CURDIR)/../oblodai-backend)
export OBLODAI_BACKEND

UID_GID := $(shell id -u):$(shell id -g)
DOCKER := docker run --rm --label oblodai.sdkcheck=1 --memory 2g -u $(UID_GID) -v $(CURDIR):/src -w /src
PHP := $(DOCKER) -v $(OBLODAI_BACKEND):/backend:ro -e OBLODAI_BACKEND=/backend php:8.3-cli
COMPOSER := $(DOCKER) -e COMPOSER_HOME=/src/.cache/composer composer:2

.PHONY: ci backend vendor drift lint stan test package live

ci: backend vendor drift lint stan test package
	@echo "all gates green"

backend:
	@test -d "$(OBLODAI_BACKEND)/tools/sdkgen/conformance" || { \
		echo "no backend at $(OBLODAI_BACKEND): set OBLODAI_BACKEND to the backend checkout" >&2; exit 1; }

vendor:
	$(COMPOSER) composer install --no-interaction --no-progress --quiet

drift:         ## src/Generated and names.lock match the backend's contract
	sh scripts/check-generated.sh --require

lint:
	$(PHP) sh -c 'PHP_CS_FIXER_IGNORE_ENV=1 vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no --show-progress=none'

stan:
	$(PHP) php vendor/bin/phpstan analyse --no-progress --memory-limit=1G

test:          ## unit, contract (generated code, README, examples) and conformance
	$(PHP) vendor/bin/phpunit --testsuite unit,contract,conformance

package:       ## the archive composer would ship: the library, not the repository
	$(COMPOSER) sh -c 'set -e; composer validate --strict --no-check-lock; \
		rm -rf .cache/dist; composer archive --format=zip --dir=.cache/dist --file=oblodai-sdk --quiet; \
		php scripts/check-package.php .cache/dist/oblodai-sdk.zip'

live:
	$(DOCKER) -e OBLODAI_LIVE_URL --network host php:8.3-cli vendor/bin/phpunit --testsuite live
