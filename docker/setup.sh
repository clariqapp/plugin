#!/usr/bin/env bash
# Runs once via WP-CLI container after WordPress is up
# Sets up WordPress + WooCommerce + our plugin

set -e

echo "Waiting for WordPress to be ready..."
until wp core is-installed --allow-root --path=/var/www/html 2>/dev/null; do
  sleep 3
done

echo "Installing WordPress..."
wp core install \
  --allow-root \
  --path=/var/www/html \
  --url="http://localhost:8888" \
  --title="Clariq Dev" \
  --admin_user=admin \
  --admin_password=password \
  --admin_email=admin@example.com \
  --skip-email 2>/dev/null || true

echo "Installing WooCommerce..."
wp plugin install woocommerce --activate --allow-root --path=/var/www/html 2>/dev/null || true

echo "Activating our plugin..."
wp plugin activate wc-analytics-mcp --allow-root --path=/var/www/html 2>/dev/null || true

echo "Enabling HPOS..."
wp option update woocommerce_feature_custom_order_tables_enabled yes --allow-root --path=/var/www/html 2>/dev/null || true

echo "Done. Admin: http://localhost:8888/wp-admin  user: admin / password"
