#!/bin/bash
set -e
echo "Running PHP tests..."
vendor/bin/phpunit
echo "Running browser tests..."
npx playwright test
