#!/usr/bin/env bash

docker exec fairybus-mysql sh -c 'rm -rf /var/www/html/writable/twig/cache/*'