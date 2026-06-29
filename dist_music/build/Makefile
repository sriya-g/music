#----------------------------------------------------------------------------
# Nextcloud Music app
#
# This file is licensed under the Affero General Public License version 3 or
# later. See the COPYING file.
#
# @author Bernhard Posselt, <nukewhale@gmail.com>
# @author Pauli Järvinen <pauli.jarvinen@gmail.com>
# @copyright Bernhard Posselt 2013
# @copyright Pauli Järvinen 2016 - 2026
#----------------------------------------------------------------------------

# Makefile for building the project

# building the javascript
build: deps
	npm run build

build-dev: deps
	npm run build-dev

watch: deps
	npm run watch

# general
deps:
	npm install --deps

# localization
l10n-clone:
	python ./duplicate-lang.py --path=../l10n de:de_DE

l10n-extract:
	npm run l10n-extract
	cd ../l10n && perl l10n.pl music read

l10n-compile: l10n-clone
	npm run l10n-compile
	cd ../l10n && perl l10n.pl music write

# releasing
release:
	./release.sh
