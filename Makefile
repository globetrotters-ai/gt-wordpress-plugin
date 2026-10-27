# Globetrotters AI Presence — developer tasks.
# All tooling goes through Composer (org convention: Make targets, not raw tools).

.DEFAULT_GOAL := help
.PHONY: help install lint lint-fix test test-integration plugin-check check dist release clean

# Directory the update channel at https://globetrotters.ai/wp-plugin is deployed
# from: `make release` stages the versioned zip + update.json into it. No
# default; set it in an untracked Makefile.local or on the command line:
#   make release RELEASE_DIR=/path/to/website/public/wp-plugin
-include Makefile.local
RELEASE_DIR      ?=
PACKAGE_BASE_URL := https://globetrotters.ai/wp-plugin

help: ## Show this help.
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

install: ## Install dev dependencies.
	composer install

lint: ## Run PHPCS (WPCS + PHP 7.4 compatibility).
	composer lint

lint-fix: ## Auto-fix fixable PHPCS violations.
	composer lint:fix

test: ## Run the PHPUnit unit suite.
	composer test

test-integration: ## Run the integration suite + route smoke against live WP (needs Docker + wp-env).
	# One shell line so env:stop always runs — even if env:start itself fails and
	# short-circuits the chain — and never leaves a half-started env behind.
	npm run env:start && npm run test:integration && bash bin/smoke-routes.sh; rc=$$?; npm run env:stop; exit $$rc

plugin-check: dist ## Run WordPress Plugin Check on the built ZIP tree — the WP.org release gate (needs Docker + wp-env).
	# Check exactly what ships: `make dist` strips dev files via .distignore, so
	# Plugin Check sees the same tree CI does — not the repo root (bin/, tests/,
	# vendor/, node_modules/, composer.json) that wp-env maps in as the plugin.
	# One shell line so env:stop always runs (see test-integration).
	npm run env:start && npx wp-env run cli wp plugin check /var/www/html/wp-content/plugins/globetrotters-ai-presence/build/globetrotters-ai-presence; rc=$$?; npm run env:stop; exit $$rc

check: lint test ## Run lint + unit tests (integration/plugin-check need Docker; run them separately).

dist: ## Build a distributable plugin ZIP into build/.
	@rm -rf build && mkdir -p build/globetrotters-ai-presence
	@rsync -a --exclude-from=.distignore ./ build/globetrotters-ai-presence/
	@cd build && zip -rq globetrotters-ai-presence.zip globetrotters-ai-presence
	@echo "Built build/globetrotters-ai-presence.zip"

release: dist ## Build a versioned release and stage it into RELEASE_DIR (the update channel).
	@VERSION=$$(grep -oE "const VERSION = '[0-9]+\.[0-9]+\.[0-9]+[^']*'" globetrotters-ai-presence.php | grep -oE "[0-9]+\.[0-9]+\.[0-9]+[^']*"); \
	if [ -z "$$VERSION" ]; then echo "❌ Could not read const VERSION from globetrotters-ai-presence.php"; exit 1; fi; \
	if [ -z "$(RELEASE_DIR)" ] || [ ! -d "$(RELEASE_DIR)" ]; then \
		echo "❌ RELEASE_DIR is unset or not a directory: '$(RELEASE_DIR)'."; \
		echo "   Point it at the directory the update channel is deployed from:"; \
		echo "   make release RELEASE_DIR=/path/to/website/public/wp-plugin"; \
		exit 1; \
	fi; \
	DEST="$(RELEASE_DIR)"; \
	ZIP="build/globetrotters-ai-presence-$$VERSION.zip"; \
	cp build/globetrotters-ai-presence.zip "$$ZIP"; \
	php bin/build-update-json.php "$$VERSION" "$$ZIP" "$(PACKAGE_BASE_URL)" > build/update.json; \
	cp "$$ZIP" "$$DEST/"; \
	cp build/update.json "$$DEST/"; \
	echo "✅ Staged globetrotters-ai-presence-$$VERSION.zip + update.json into $$DEST"; \
	echo "   Next: review + commit the staged files, then deploy the website to publish."

clean: ## Remove build artefacts and vendor.
	rm -rf build vendor
