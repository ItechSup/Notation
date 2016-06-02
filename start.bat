@echo off
start composer install
start php -S localhost:8008 -t web web/index.php
start "Chrome" chrome --new-window http://localhost:8008/api/personnes
exit