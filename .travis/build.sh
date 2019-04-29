#!/usr/bin/env bash
set -ex

if [ "${ALL_TESTS}" = "1" ]; then
  echo "Running Sonar Scanner"
  vendor/bin/phpstan analyse src -c phpstan.neon --level=max --no-progress -vvv
  vendor/bin/phpunit --coverage-clover=coverage-report.clover --log-junit=test-report.xml
  sonar-scanner
else
  echo "Not running code coverage"
fi
