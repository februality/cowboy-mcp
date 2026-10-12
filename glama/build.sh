#!/bin/sh
# Glama build step: a throwaway WordPress (SQLite, no MySQL) with this checkout installed as the
# cowboy-mcp plugin, so Glama's inspector can start the server and list its tools.
# Not part of the plugin: the WordPress.org zip and SVN trunk ship only the plugin files.
set -eu

SRC=$(cd "$(dirname "$0")/.." && pwd)
WP=/wp
wp() { /usr/local/bin/wp --allow-root --path="$WP" "$@"; }

apt-get update
apt-get install -y --no-install-recommends php-cli php-sqlite3 php-xml php-mbstring php-curl php-zip php-intl unzip
curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
chmod +x /usr/local/bin/wp

wp core download --skip-content

# SQLite Database Integration (the WordPress Playground database driver) as the db.php drop-in.
mkdir -p "$WP/wp-content/plugins"
curl -fsSL -o /tmp/sqlite.zip https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip
unzip -q /tmp/sqlite.zip -d "$WP/wp-content/plugins"
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$WP/wp-content/plugins/sqlite-database-integration#" \
	-e 's#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#' \
	"$WP/wp-content/plugins/sqlite-database-integration/db.copy" > "$WP/wp-content/db.php"

wp config create --dbname=wordpress --dbuser=unused --dbpass=unused --skip-check --extra-php <<'PHP'
define( 'DISABLE_WP_CRON', true );
define( 'WP_DEBUG', false );
PHP
wp core install --url=http://127.0.0.1:8765 --title="Cowboy MCP sandbox" --admin_user=admin \
	--admin_password="$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')" --admin_email=admin@example.com --skip-email

# Copy (not symlink): the plugin derives wp-content from its own path.
mkdir -p "$WP/wp-content/plugins/cowboy-mcp"
cp -R "$SRC/cowboy-mcp.php" "$SRC/includes" "$SRC/admin" "$SRC/languages" "$SRC/readme.txt" "$WP/wp-content/plugins/cowboy-mcp/"
wp plugin activate cowboy-mcp

apt-get clean
rm -rf /var/lib/apt/lists/* /tmp/sqlite.zip
