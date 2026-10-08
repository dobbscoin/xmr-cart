#!/bin/sh
# xmr-cart installer: see install.php
cd "$(dirname "$0")" && exec php install.php "$@"
