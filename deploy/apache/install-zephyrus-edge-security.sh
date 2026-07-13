#!/usr/bin/env bash

set -Eeuo pipefail

if [[ "$EUID" -ne 0 ]]; then
    echo "Run this one-time edge preparation as root." >&2
    exit 1
fi

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SOURCE="$PROJECT_ROOT/deploy/apache/zephyrus-edge-security.conf"
DESTINATION="/etc/apache2/zephyrus/edge-security.conf"
VHOST="/etc/apache2/sites-enabled/zephyrus.acumenus.net-le-ssl.conf"
INCLUDE_DIRECTIVE="Include /etc/apache2/zephyrus/edge-security.conf"

if ! apache2ctl -M 2>/dev/null | grep -q 'security2_module'; then
    echo "ModSecurity is not active. Install/enable libapache2-mod-security2 and the OWASP CRS before continuing." >&2
    exit 1
fi
if [[ ! -f /usr/share/modsecurity-crs/owasp-crs.load ]] \
   && ! find /usr/share/modsecurity-crs -maxdepth 2 -type f -name '*.conf' -print -quit 2>/dev/null | grep -q .; then
    echo "The OWASP Core Rule Set is not installed." >&2
    exit 1
fi
if [[ ! -f "$VHOST" ]]; then
    echo "The enabled Zephyrus TLS vhost is missing: $VHOST" >&2
    exit 1
fi
if ! grep -Fqx "$INCLUDE_DIRECTIVE" "$VHOST"; then
    echo "Add this line inside the Zephyrus TLS VirtualHost, then rerun:" >&2
    echo "    $INCLUDE_DIRECTIVE" >&2
    exit 1
fi

install -d -o root -g root -m 0755 "$(dirname "$DESTINATION")"
install -o root -g root -m 0644 "$SOURCE" "$DESTINATION"
apache2ctl -t
php "$PROJECT_ROOT/scripts/security/verify-edge-security.php" --contract --apache

echo "Zephyrus edge policy is installed and Apache configuration is valid."

