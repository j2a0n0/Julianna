VERSION := $(shell grep "appVersion" ./app/Core/Configuration/AppSettings.php |awk -F' = ' '{print substr($$2,2,length($$2)-3)}')
TARGET_DIR:= ./target/julianna
DESC:=$(shell git log -1 --pretty=%B)
RUNNING_DOCKER_CONTAINERS:= $(shell docker ps -a -q)
RUNNING_DOCKER_VOLUMES:= $(shell docker volume ls -q)

install-deps-dev:
	npm install
	composer install

install-deps:
	npm install
	composer install --no-dev --optimize-autoloader --prefer-dist

build: install-deps clear-cache
	npx mix --production
	node generateBlocklist.mjs

build-dev: install-deps-dev clear-cache
	npx mix
	node generateBlocklist.mjs

package: clean build
	mkdir -p $(TARGET_DIR)

	#copy code files
	cp -R ./app $(TARGET_DIR)
	cp -R ./config $(TARGET_DIR)
	cp -R ./bin $(TARGET_DIR)
	cp -R ./bootstrap $(TARGET_DIR)
	cp -R ./public $(TARGET_DIR)
	cp -R ./vendor $(TARGET_DIR)

	#create empty cache and storage folders
	mkdir -p $(TARGET_DIR)/storage
	mkdir -p $(TARGET_DIR)/storage/framework
	mkdir -p $(TARGET_DIR)/storage/framework/cache
	mkdir -p $(TARGET_DIR)/storage/framework/sessions
	mkdir -p $(TARGET_DIR)/storage/framework/views

	#prepare log file
	mkdir -p $(TARGET_DIR)/storage/logs
	touch $(TARGET_DIR)/storage/logs/julianna.log

	mkdir -p $(TARGET_DIR)/userfiles
	touch   $(TARGET_DIR)/userfiles/.gitkeep


	rm -rf $(TARGET_DIR)/config/.env
	rm -rf $(TARGET_DIR)/public/theme/*/css/custom.css

	# Remove user files
	rm -rf $(TARGET_DIR)/app/Plugins/*

	# Remove user files
	rm -rf $(TARGET_DIR)/userfiles/*
	rm -rf $(TARGET_DIR)/public/userfiles/*

	# Removing unneeded items for release
	rm -rf $(TARGET_DIR)/public/dist/images/Screenshots

	# Strip vendor bloat (#3330): composer source-fallback installs ship .git dirs
	# and aws-sdk build/test artifacts that ballooned releases from ~50MB to ~860MB
	find $(TARGET_DIR)/vendor -type d -name ".git" -prune -exec rm -rf {} +
	rm -rf $(TARGET_DIR)/vendor/aws/aws-sdk-php/build
	rm -rf $(TARGET_DIR)/vendor/aws/aws-sdk-php/features
	rm -rf $(TARGET_DIR)/vendor/aws/aws-sdk-php/tests
	rm -rf $(TARGET_DIR)/vendor/aws/aws-sdk-php/.changes

	# Removing javascript directories
	find  $(TARGET_DIR)/app/Domain/ -depth -maxdepth 2 -name "js" -exec rm -rf {} \;

	# Removing un-compiled javascript files
	find $(TARGET_DIR)/public/dist/js/ -depth -mindepth 1 ! -name "*compiled*" -exec rm -rf {} \;

	#create zip files
	cd target/julianna && zip -r -X ../"Julianna-v$(VERSION)$$1.zip" .
	cd target/julianna && tar -zcvf ../"Julianna-v$(VERSION)$$1.tar.gz" .

clean:
	rm -rf $(TARGET_DIR)

release-check:
	bash scripts/check-independent-release.sh

run-dev: build-dev
	docker compose --file .dev/docker-compose.yaml up --detach --build --remove-orphans

acceptance-test: build-dev
	docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml up --detach --build --remove-orphans
	docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml exec julianna-dev php vendor/bin/codecept clean
	docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml exec julianna-dev php vendor/bin/codecept build
	docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml exec julianna-dev php vendor/bin/codecept run Acceptance --steps

unit-test: build-dev
	docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml up --detach --build --remove-orphans
	docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml exec julianna-dev php vendor/bin/codecept build
	docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml exec julianna-dev php vendor/bin/codecept run Unit --steps

test-translations: build-dev
	docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml up --detach --build --remove-orphans
	docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml exec julianna-dev php vendor/bin/codecept build
	docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml exec julianna-dev php vendor/bin/codecept run Unit tests/Unit/app/Core/LanguageCatalogTest.php --steps
	docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml exec julianna-dev php vendor/bin/codecept run Acceptance --group fr-ch-localization --steps

api-test: build-dev
	docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml up --detach --build --remove-orphans
	docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml exec julianna-dev php vendor/bin/codecept build
	docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml exec julianna-dev php vendor/bin/codecept run Api --steps

acceptance-test-ci: build-dev
	docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml up --detach --build --remove-orphans
	docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml exec julianna-dev php vendor/bin/codecept build
	docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml exec julianna-dev php vendor/bin/codecept run Acceptance --steps

bearer-api-test-ci: build-dev
	docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml up --detach --build --remove-orphans
	docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml exec julianna-dev php vendor/bin/codecept build
	docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml exec julianna-dev php vendor/bin/codecept run Acceptance --group bearer-api --steps

codesniffer:
	./vendor/squizlabs/php_codesniffer/bin/phpcs app -d memory_limit=1048M

codesniffer-fix:
	./vendor/squizlabs/php_codesniffer/bin/phpcbf app -d memory_limit=1048M

get-version:
	@echo $(VERSION)

phpstan:
	./vendor/bin/phpstan analyse -c .phpstan/phpstan.neon --memory-limit 2G

update-carbon-macros:
	./vendor/bin/carbon macro Leantime\\Core\\Support\\CarbonMacros app/Core/Support/CarbonMacros.php

test-code-style:
	./vendor/bin/pint --test --config .pint/pint.json

fix-code-style:
	./vendor/bin/pint --config .pint/pint.json

clear-cache:
	rm -rf ./bootstrap/cache/*.php
	rm -rf ./storage/framework/composerPaths.php
	rm -rf ./storage/framework/viewPaths.php

	find ./storage/framework/cache -type f ! -name '.gitignore' -delete
	find ./storage/framework/cache -type d -empty -delete

	find ./storage/framework/sessions -type f ! -name '.gitignore' -delete
	find ./storage/framework/sessions -type d -empty -delete

	find ./storage/framework/views -type f ! -name '.gitignore' -delete
	find ./storage/framework/views -type d -empty -delete


.PHONY: install-deps build-js build package clean release-check run-dev
