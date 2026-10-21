#!/bin/sh
set -eu

port="${PORT:-8080}"
sed -i "s/^Listen 80$/Listen ${port}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${port}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground