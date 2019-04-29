#!/usr/bin/env bash
set -ex

composer update --profile --ansi --prefer-dist --no-interaction --optimize-autoloader --no-suggest --no-progress
