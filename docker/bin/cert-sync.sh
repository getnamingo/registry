#!/bin/sh
set -u

domain=${NAMINGO_DOMAIN:?NAMINGO_DOMAIN is required}
hostname="epp.${domain}"
interval=${NAMINGO_CERT_SYNC_INTERVAL:-300}
caddy_data=/caddy-data
destination=/certs
stopping=false
sleeper=

case "$interval" in
    ''|*[!0-9]*|0)
        echo "NAMINGO_CERT_SYNC_INTERVAL must be a positive integer." >&2
        exit 1
        ;;
esac

case "${NAMINGO_CERT_SYNC_ENABLED:-true}" in
    true|1|yes)
        sync_enabled=true
        ;;
    false|0|no)
        sync_enabled=false
        echo "Automatic EPP certificate synchronization is disabled."
        ;;
    *)
        echo "NAMINGO_CERT_SYNC_ENABLED must be true or false." >&2
        exit 1
        ;;
esac

shutdown() {
    stopping=true
    if [ -n "$sleeper" ]; then
        kill "$sleeper" 2>/dev/null || true
    fi
}

trap shutdown INT TERM HUP

valid_pair() {
    certificate=$1
    private_key=$2
    openssl x509 -in "$certificate" -noout >/dev/null 2>&1 || return 1
    openssl pkey -in "$private_key" -passin pass: -noout >/dev/null 2>&1 || return 1
    cert_public=$(openssl x509 -in "$certificate" -pubkey -noout 2>/dev/null \
        | openssl pkey -pubin -outform DER 2>/dev/null \
        | sha256sum | awk '{print $1}') || return 1
    key_public=$(openssl pkey -in "$private_key" -passin pass: -pubout -outform DER 2>/dev/null \
        | sha256sum | awk '{print $1}') || return 1
    [ -n "$cert_public" ] && [ "$cert_public" = "$key_public" ]
}

sync_epp_certificate() {
    certificate=$(find "$caddy_data/certificates" -type f \
        -path "*/${hostname}/${hostname}.crt" -printf '%T@ %p\n' 2>/dev/null \
        | sort -nr | sed -n '1s/^[^ ]* //p')
    [ -n "$certificate" ] || return 0

    private_key=${certificate%.crt}.key
    [ -r "$private_key" ] || return 0
    openssl x509 -checkend 86400 -noout -in "$certificate" >/dev/null 2>&1 || return 0
    valid_pair "$certificate" "$private_key" || return 0

    incoming=$(sha256sum "$certificate" "$private_key" \
        | awk '{print $1}' | sha256sum | awk '{print $1}')
    current=missing
    if [ -r "$destination/epp.crt" ] && [ -r "$destination/epp.key" ]; then
        current=$(sha256sum "$destination/epp.crt" "$destination/epp.key" \
            | awk '{print $1}' | sha256sum | awk '{print $1}')
    fi

    if [ "$incoming" != "$current" ]; then
        cp "$certificate" "$destination/.epp.crt.new"
        cp "$private_key" "$destination/.epp.key.new"
        chmod 0644 "$destination/.epp.crt.new"
        chmod 0600 "$destination/.epp.key.new"
        mv -f "$destination/.epp.crt.new" "$destination/epp.crt"
        mv -f "$destination/.epp.key.new" "$destination/epp.key"
        echo "Installed the renewed Caddy certificate for ${hostname}."
    fi
}

sync_ca_bundle() {
    if [ -r "$caddy_data/pki/authorities/local/root.crt" ]; then
        cp "$caddy_data/pki/authorities/local/root.crt" "$destination/.caddy-local-root.crt.new"
        chmod 0644 "$destination/.caddy-local-root.crt.new"
        mv -f "$destination/.caddy-local-root.crt.new" "$destination/caddy-local-root.crt"

        cat /etc/ssl/certs/ca-certificates.crt "$destination/caddy-local-root.crt" \
            > "$destination/.ca-bundle.crt.new"
    else
        cp /etc/ssl/certs/ca-certificates.crt "$destination/.ca-bundle.crt.new"
    fi
    chmod 0644 "$destination/.ca-bundle.crt.new"
    mv -f "$destination/.ca-bundle.crt.new" "$destination/ca-bundle.crt"
}

sync_once() {
    if [ "$sync_enabled" = true ]; then
        sync_epp_certificate
    fi
    sync_ca_bundle
}

while [ "$stopping" = false ]; do
    sync_once
    sleep "$interval" &
    sleeper=$!
    wait "$sleeper" 2>/dev/null || true
    sleeper=
done
