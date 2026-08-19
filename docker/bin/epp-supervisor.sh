#!/bin/sh
set -u

source_cert=/certs/epp.crt
source_key=/certs/epp.key
runtime_cert=/run/namingo/tls/epp.crt
runtime_key=/run/namingo/tls/epp.key
child_pid=
stopping=false

pair_hash() {
    if [ ! -r "$source_cert" ] || [ ! -r "$source_key" ]; then
        printf '%s' missing
        return
    fi
    sha256sum "$source_cert" "$source_key" | sha256sum | awk '{print $1}'
}

valid_pair() {
    openssl x509 -in "$source_cert" -noout >/dev/null 2>&1 || return 1
    openssl pkey -in "$source_key" -passin pass: -noout >/dev/null 2>&1 || return 1
    cert_public=$(openssl x509 -in "$source_cert" -pubkey -noout 2>/dev/null \
        | openssl pkey -pubin -outform DER 2>/dev/null \
        | sha256sum | awk '{print $1}') || return 1
    key_public=$(openssl pkey -in "$source_key" -passin pass: -pubout -outform DER 2>/dev/null \
        | sha256sum | awk '{print $1}') || return 1
    [ -n "$cert_public" ] && [ "$cert_public" = "$key_public" ]
}

install_pair() {
    if ! valid_pair; then
        echo "EPP TLS certificate and private key are missing or do not match." >&2
        return 1
    fi
    cp "$source_cert" "${runtime_cert}.new"
    cp "$source_key" "${runtime_key}.new"
    chown www-data:www-data "${runtime_cert}.new" "${runtime_key}.new"
    chmod 0644 "${runtime_cert}.new"
    chmod 0600 "${runtime_key}.new"
    mv -f "${runtime_cert}.new" "$runtime_cert"
    mv -f "${runtime_key}.new" "$runtime_key"
}

shutdown() {
    stopping=true
    if [ -n "$child_pid" ] && kill -0 "$child_pid" 2>/dev/null; then
        kill -INT "$child_pid" 2>/dev/null || true
    fi
}

trap shutdown INT TERM HUP

while :; do
    while ! install_pair; do
        [ "$stopping" = true ] && exit 0
        sleep 2
    done

    active_hash=$(pair_hash)
    gosu www-data:www-data php /opt/registry/epp/start_epp.php &
    child_pid=$!

    while kill -0 "$child_pid" 2>/dev/null; do
        sleep 15 &
        wait $! 2>/dev/null || true
        [ "$stopping" = true ] && break

        next_hash=$(pair_hash)
        if [ "$next_hash" != "$active_hash" ] && valid_pair; then
            echo "EPP TLS certificate changed; restarting the EPP server."
            kill -INT "$child_pid" 2>/dev/null || true
            break
        fi
    done

    wait "$child_pid" 2>/dev/null
    status=$?
    child_pid=

    [ "$stopping" = true ] && exit 0
    echo "EPP server exited with status ${status}; restarting in 3 seconds." >&2
    sleep 3
done
