#!/bin/bash
set -euo pipefail

DATAROOT="${MOODLE_DATAROOT:-/var/moodledata}"
CONFIG="/var/www/html/config.php"
mkdir -p "$DATAROOT"

# Existing PVC config wins so a roll does not rewrite production secrets.
if [ ! -f "$CONFIG" ]; then
  : "${MOODLE_URL:?MOODLE_URL is required}"
  : "${MOODLE_DB_HOST:?MOODLE_DB_HOST is required}"
  : "${MOODLE_DB_NAME:?MOODLE_DB_NAME is required}"
  : "${MOODLE_DB_USER:?MOODLE_DB_USER is required}"
  : "${MOODLE_DB_PASSWORD:?MOODLE_DB_PASSWORD is required}"
  dbtype="${MOODLE_DB_TYPE:-mariadb}"
  dbport="${MOODLE_DB_PORT:-3306}"
  sslproxy="true"
  if [ "${MOODLE_SSL:-true}" = "false" ]; then
    sslproxy="false"
  fi
  cat > "$CONFIG" <<EOF
<?php
unset(\$CFG);
global \$CFG;
\$CFG = new stdClass();
\$CFG->dbtype    = '${dbtype}';
\$CFG->dblibrary = 'native';
\$CFG->dbhost    = '${MOODLE_DB_HOST}';
\$CFG->dbname    = '${MOODLE_DB_NAME}';
\$CFG->dbuser    = '${MOODLE_DB_USER}';
\$CFG->dbpass    = '${MOODLE_DB_PASSWORD}';
\$CFG->prefix    = 'mdl_';
\$CFG->dboptions = [
    'dbpersist' => false,
    'dbsocket'  => false,
    'dbport'    => '${dbport}',
    'dbcollation' => 'utf8mb4_unicode_ci',
];
\$CFG->wwwroot   = '${MOODLE_URL}';
\$CFG->dataroot  = '${DATAROOT}';
\$CFG->directorypermissions = 02777;
\$CFG->admin = 'admin';
\$CFG->sslproxy = ${sslproxy};
require_once(__DIR__ . '/lib/setup.php');
EOF
fi

chown -R www-data:www-data "$DATAROOT" "$CONFIG" || true
exec apache2-foreground
