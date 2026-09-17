#!/usr/bin/env bash
# Sincroniza o plugin com o banco de provas do servidor e roda o PHPUnit.
# NUNCA aponta para releases/tech-provider-20260914 nem para current: aquilo é produção.
set -euo pipefail

HOST="$INBOX_TEST_HOST"
BENCH="<release de provas>"
TARGET="${1:-plugins/MauticInboxBundle/Tests/Unit}"

# Guarda real: pergunta ao servidor para onde current aponta e recusa se for o mesmo lugar.
# Comparar com um literal fixo nao protegeria nada, porque BENCH tambem e literal.
# readlink -f canonicaliza: um symlink relativo faria a comparacao nunca casar, e a guarda
# passaria a mentir em silencio — que e exatamente o que ela existe para evitar.
CURRENT="$(ssh "$HOST" 'readlink -f <release em producao>')"
if [[ "$BENCH" == "$CURRENT" ]]; then
  echo "RECUSADO: o banco de provas e a release que atende producao." >&2
  exit 1
fi

rsync -az --delete \
  --exclude='.git/' --exclude='node_modules/' --exclude='docs/' \
  ./ "$HOST:$BENCH/plugins/MauticInboxBundle/"

# php8.4 explicito: e o que o PHP-FPM do site usa. O `php` do PATH e 8.5, e divergencia de
# versao entre o teste e a producao e exatamente o tipo de surpresa que nao queremos aqui.
ssh "$HOST" "cd $BENCH && php8.4 bin/phpunit -c app/phpunit.xml.dist $TARGET --testdox"
