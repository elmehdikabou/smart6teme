#!/usr/bin/env bash
# Construit l'archive installable via Extensions → Ajouter → Téléverser.
set -euo pipefail
cd "$(dirname "$0")"
rm -f smart6teme-cart-fix.zip
zip -rq smart6teme-cart-fix.zip smart6teme-cart-fix -x '*.DS_Store' '*/.*'
echo "Archive prête : $(pwd)/smart6teme-cart-fix.zip"
