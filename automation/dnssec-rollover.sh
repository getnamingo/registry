#!/bin/bash
ZONE="example.tld"
ROOT_SERVER="a.root-servers.net"
KEYDIR="/var/lib/bind"  # Adjust if needed
LOGFILE="/var/log/namingo/dnssec-rollover.log"

echo "[$(date)] Checking IANA root zone for DS update..." | tee -a $LOGFILE

# Fetch DS records from IANA root zone
root_ds=$(dig +short DS $ZONE @$ROOT_SERVER | awk '{print $1, $2, $3, $4}')
if [[ -z "$root_ds" ]]; then
    echo "[$(date)] ERROR: Unable to fetch DS records from IANA. Exiting." | tee -a $LOGFILE
    exit 1
fi

# Fetch DS records from BIND (local KSKs)
local_ds=$(dnssec-dsfromkey -2 $KEYDIR/K${ZONE}*.key | awk '{print $1, $2, $3, $4}')
if [[ -z "$local_ds" ]]; then
    echo "[$(date)] ERROR: Unable to fetch DS records from BIND. Exiting." | tee -a $LOGFILE
    exit 1
fi

echo "[$(date)] DS records at IANA:" | tee -a $LOGFILE
echo "$root_ds" | tee -a $LOGFILE
echo "[$(date)] DS records in BIND:" | tee -a $LOGFILE
echo "$local_ds" | tee -a $LOGFILE

# Step 1: Check if IANA DS matches BIND DS (safe transition condition)
if [[ "$root_ds" == "$local_ds" ]]; then
    echo "[$(date)] IANA has updated DS record. Safe to retire old KSK." | tee -a $LOGFILE

    # Step 2: Identify and remove old KSKs from BIND
    for key in $(ls $KEYDIR/K${ZONE}*.key); do
        key_ds=$(dnssec-dsfromkey -2 $key | awk '{print $1, $2, $3, $4}')
        
        if [[ ! "$root_ds" == *"$key_ds"* ]]; then
            echo "[$(date)] Removing old KSK: $key" | tee -a $LOGFILE
            rndc dnssec -clear key $ZONE
            echo "[$(date)] Old KSK $key removed successfully." | tee -a $LOGFILE
        fi
    done
else
    echo "[$(date)] DS record mismatch! Keeping old KSK active. Retrying later..." | tee -a $LOGFILE
fi
