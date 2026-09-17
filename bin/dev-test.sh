#!/usr/bin/env bash
# Sincroniza o plugin com um banco de provas e roda o PHPUnit la.
#
# O host e o caminho vem do ambiente, sem valor padrao, por dois motivos: este pacote e
# publico, e um endereco de servidor de producao nao deve viajar dentro dele; e um padrao
# silencioso e justamente o que faz alguem rodar contra a maquina errada sem perceber.
#
#   export INBOX_TEST_HOST=usuario@servidor
#   export INBOX_TEST_BENCH=/caminho/para/uma/release/que/nao/atende/trafego
#   ./bin/dev-test.sh [alvo]
set -euo pipefail

HOST="${INBOX_TEST_HOST:?defina INBOX_TEST_HOST, ex.: usuario@servidor}"
BENCH="${INBOX_TEST_BENCH:?defina INBOX_TEST_BENCH, o caminho da release de provas}"
SITE="${INBOX_TEST_SITE:-$(dirname "$(dirname "$BENCH")")}"
TARGET="${1:-plugins/MauticInboxBundle/Tests/Unit}"
PHP="${INBOX_TEST_PHP:-php8.4}"

# O alvo entra num shell remoto. Sem esta validacao, um argumento com ponto-e-virgula executa
# o que quiser como o usuario de deploy; um com espaco roda a suite no diretorio errado e
# reporta verde.
if [[ ! "$TARGET" =~ ^[A-Za-z0-9/_.-]+$ ]]; then
  echo "RECUSADO: alvo com caracteres fora de [A-Za-z0-9/_.-]: $TARGET" >&2
  exit 1
fi

# Canonicaliza OS DOIS lados na mesma chamada. Resolver so um deles deixa a guarda passar
# quando houver qualquer symlink no prefixo compartilhado — e a guarda existe exatamente
# para o caso em que o banco de provas E a release de producao.
read -r CURRENT_REAL BENCH_REAL <<<"$(ssh "$HOST" "readlink -f '$SITE/current'; readlink -f '$BENCH'" | tr '\n' ' ')"

if [[ -z "$BENCH_REAL" ]]; then
  echo "RECUSADO: nao foi possivel resolver $BENCH no host." >&2
  exit 1
fi
if [[ "$BENCH_REAL" == "$CURRENT_REAL" ]]; then
  echo "RECUSADO: o banco de provas e a release que atende producao ($BENCH_REAL)." >&2
  exit 1
fi

rsync -az --delete \
  --exclude='.git/' --exclude='node_modules/' --exclude='docs/' \
  ./ "$HOST:$BENCH_REAL/plugins/MauticInboxBundle/"

# Os testes unitarios nao tocam banco e ignoram tudo abaixo. Os funcionais exigem duas coisas:
# as variaveis DB_* que o config_test.php do Mautic le, e a confirmacao explicita do nome do
# banco descartavel, sem a qual o MauticMysqlTestCase se recusa a rodar — e faz bem.
#
# As credenciais sao lidas do proprio arquivo de ambiente DO SERVIDOR, no servidor. Elas nunca
# viajam por aqui, nunca aparecem em linha de comando e nunca entram em log.
DB_ENV="${INBOX_TEST_DB_ENV:-$SITE/.mariadb.env}"
TESTDB="${INBOX_TEST_DATABASE:-}"

ssh "$HOST" "
  cd '$BENCH_REAL' || exit 1
  if [ -n '$TESTDB' ] && [ -r '$DB_ENV' ]; then
    set -a; . '$DB_ENV'; set +a
    export DB_HOST=\"\${INBOX_TEST_DB_HOST:-127.0.0.1}\"
    export DB_PORT=\"\${INBOX_TEST_DB_PORT:-3306}\"
    export DB_NAME='$TESTDB'
    export DB_USER=\"\$MARIADB_USER\"
    export DB_PASSWD=\"\$MARIADB_PASSWORD\"
    export MAUTIC_TEST_DATABASE_ALLOW_DESTRUCTIVE='$TESTDB'
  fi
  $PHP bin/phpunit -c app/phpunit.xml.dist $TARGET --testdox
"
