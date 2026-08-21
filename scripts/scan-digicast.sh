#!/usr/bin/env bash
set -uo pipefail

# Scans the local network for DigiCast devices.
# Matches on hostname, mDNS service names, HTTP banners/titles and MAC vendor.
#
# Usage:
#   ./scripts/scan-digicast.sh                 # auto-detect subnet from default route
#   ./scripts/scan-digicast.sh 10.239.100.0/22 # explicit CIDR
#   PATTERN=signage ./scripts/scan-digicast.sh # match a different keyword
#   EXCLUDE= ./scripts/scan-digicast.sh        # clear the skipped ranges

PATTERN="${PATTERN:-digicast}"
# DigiCast boxes never say "digicast" over the wire. They answer with Server: box
# and a Basic realm of pbox, and their MACs sit in the Asiamajor 00:13:14 range.
OUI="${OUI:-00:13:14}"
SIGNATURES="${SIGNATURES:-realm=\"pbox\"|Server: box}"
PORTS="${PORTS:-80,443}"
TIMEOUT="${TIMEOUT:-2}"
EXCLUDE="${EXCLUDE-10.239.103.0/24}"

have() { command -v "$1" >/dev/null 2>&1; }

for bin in nmap; do
    have "$bin" || { echo "missing: $bin (brew install $bin)" >&2; exit 1; }
done

detect_cidr() {
    local iface gw prefix
    iface=$(route -n get default 2>/dev/null | awk '/interface:/{print $2}')
    [ -n "$iface" ] || return 1
    gw=$(ifconfig "$iface" 2>/dev/null | awk '/inet /{print $2; exit}')
    [ -n "$gw" ] || return 1
    prefix=$(ifconfig "$iface" 2>/dev/null | awk '/inet /{print $4; exit}')
    # netmask comes back as hex on macOS
    local bits=0 mask=$((prefix))
    while [ "$mask" -ne 0 ]; do
        bits=$((bits + (mask & 1)))
        mask=$((mask >> 1))
    done
    [ "$bits" -gt 0 ] || bits=24
    python3 - "$gw" "$bits" <<'PY'
import ipaddress, sys
net = ipaddress.ip_network(f"{sys.argv[1]}/{sys.argv[2]}", strict=False)
print(net.with_prefixlen)
PY
}

CIDR="${1:-$(detect_cidr)}"
[ -n "$CIDR" ] || { echo "could not detect subnet, pass a CIDR" >&2; exit 1; }

echo "== scanning $CIDR for '$PATTERN' =="
echo

WORK=$(mktemp -d -t digicast)

EXCLUDE_ARG=""
if [ -n "$EXCLUDE" ]; then
    EXCLUDE_ARG="--exclude $EXCLUDE"
    echo "   skipping $EXCLUDE"
    echo
fi

echo "-- mDNS / Bonjour --"
if have dns-sd; then
    i=0
    for svc in _http._tcp _workstation._tcp _airplay._tcp _googlecast._tcp _rtsp._tcp _services._dns-sd._udp; do
        i=$((i + 1))
        timeout "$TIMEOUT" dns-sd -B "$svc" local > "$WORK/mdns-raw-$i" 2>/dev/null &
    done
    wait
    cat "$WORK"/mdns-raw-* 2>/dev/null | awk '/Add/{ $1=$2=$3=$4=$5=$6=""; print }' | sort -u > "$WORK/mdns.txt"
    if grep -iq "$PATTERN" "$WORK/mdns.txt"; then
        grep -i "$PATTERN" "$WORK/mdns.txt" | sed 's/^ */  hit: /'
    else
        echo "  no mDNS name matched"
    fi
else
    echo "  dns-sd unavailable, skipped"
fi
echo

echo "-- host discovery --"
# A privileged sweep uses ARP on the local link: ~5s for a /22 and it sees hosts
# that ignore TCP pings. Falls back to the unprivileged TCP sweep.
if sudo -n true 2>/dev/null; then
    SUDO="sudo -n"
else
    SUDO=""
    echo "  no passwordless sudo, using slower TCP sweep"
fi
# No --host-timeout here: it is wall clock from the start of the host group, so
# with a large group the hosts probed last get dropped before they answer.
$SUDO nmap -sn -n -T5 --min-parallelism 128 --min-hostgroup 512 --max-retries 1 \
     $EXCLUDE_ARG "$CIDR" -oG "$WORK/ping.gnmap" >/dev/null 2>&1
[ -n "$SUDO" ] && $SUDO chown "$(id -u)" "$WORK/ping.gnmap" 2>/dev/null
awk '/Status: Up/{print $2}' "$WORK/ping.gnmap" | sort -u > "$WORK/hosts.txt"
echo "  $(wc -l < "$WORK/hosts.txt" | tr -d ' ') hosts up"
echo

echo "-- ARP / OUI --"
# Client isolation on the AP hides peers from an ARP sweep, and some boxes ignore
# TCP pings, so seed the cache with a plain ICMP fan-out over the whole range and
# fold whatever answers into the host list.
python3 - "$CIDR" "$EXCLUDE" > "$WORK/all-ips.txt" <<'PY'
import ipaddress, sys

net = ipaddress.ip_network(sys.argv[1], strict=False)
skip = [ipaddress.ip_network(x, strict=False) for x in sys.argv[2].split(",") if x.strip()]
for ip in net.hosts():
    if not any(ip in s for s in skip):
        print(ip)
PY

xargs -P 256 -I{} ping -c1 -W 300 -t 1 {} < "$WORK/all-ips.txt" >/dev/null 2>&1
arp -an > "$WORK/arp.txt"

grep -v -e '(incomplete)' -e 'ff:ff:ff:ff:ff:ff' "$WORK/arp.txt" | sed -E 's/^.*\(([0-9.]+)\).*/\1/' \
    | grep -E '^[0-9.]+$' >> "$WORK/hosts.txt"
sort -u -t. -k1,1n -k2,2n -k3,3n -k4,4n "$WORK/hosts.txt" -o "$WORK/hosts.txt"
echo "  $(wc -l < "$WORK/hosts.txt" | tr -d ' ') hosts after ARP merge"

SHORT_OUI=$(echo "$OUI" | sed 's/:0\([0-9a-fA-F]\)/:\1/g; s/^0\([0-9a-fA-F]\):/\1:/')
if grep -iE "$OUI|$SHORT_OUI" "$WORK/arp.txt" > "$WORK/oui.txt"; then
    sed 's/^/  OUI HIT: /' "$WORK/oui.txt"
else
    echo "  no MAC in $OUI"
fi
echo

echo "-- port sweep --"
nmap -Pn -n -T5 -p "$PORTS" --open --min-parallelism 64 --max-retries 1 \
     --host-timeout 15s -iL "$WORK/hosts.txt" -oG "$WORK/ports.gnmap" >/dev/null 2>&1

awk -F'Ports: ' '/Ports:/{ split($1, a, " "); n = split($2, p, ", ");
    for (i = 1; i <= n; i++) { split(p[i], f, "/"); if (f[2] == "open") print a[2] " " f[1] } }' \
    "$WORK/ports.gnmap" | sort -u -k1,1 -k2,2n > "$WORK/open.txt"
echo "  $(wc -l < "$WORK/open.txt" | tr -d ' ') open ports on $(cut -d' ' -f1 "$WORK/open.txt" | sort -u | wc -l | tr -d ' ') hosts"
echo

echo "-- banner grab --"
while read -r ip port; do
    {
        case "$port" in
            443|8443) url="https://$ip:$port/" ;;
            *)        url="http://$ip:$port/" ;;
        esac
        body=$(curl -skL --max-time 4 -D- "$url" 2>/dev/null | head -c 20000)
        [ -n "$body" ] && printf '### %s %s\n%s\n' "$ip" "$port" "$body" > "$WORK/banner-$ip-$port"
    } &
done < "$WORK/open.txt"
wait
cat "$WORK"/banner-* 2>/dev/null > "$WORK/banners.txt"

python3 - "$WORK/banners.txt" "$PATTERN" "$SIGNATURES" <<'PY'
import re, sys

text = open(sys.argv[1], errors="replace").read()
pattern = sys.argv[2].lower()
signatures = [p for p in sys.argv[3].split("|") if p] if len(sys.argv) > 3 else []
found = False
for block in text.split("### ")[1:]:
    head = block.splitlines()[0].strip()
    matched = pattern in block.lower() or any(sig.lower() in block.lower() for sig in signatures)
    if matched:
        found = True
        print(f"  HIT {head}")
        for line in block.splitlines():
            low = line.lower()
            if pattern in low or any(sig.lower() in low for sig in signatures):
                print(f"      {line.strip()[:160]}")
if not found:
    print("  no banner or signature matched")
    print("  identified:")
    for block in text.split("### ")[1:]:
        head = block.splitlines()[0].strip()
        srv = re.search(r"^Server:\s*(.+)$", block, re.I | re.M)
        title = re.search(r"<title[^>]*>(.*?)</title>", block, re.I | re.S)
        bits = [b for b in (srv.group(1).strip() if srv else "",
                            " ".join(title.group(1).split())[:60] if title else "") if b]
        print(f"    {head:22} {' | '.join(bits) or '(no banner)'}")
PY
echo

echo "-- reverse DNS --"
while read -r ip; do
    { name=$(dig +short +time=1 +tries=1 -x "$ip" 2>/dev/null | head -1)
      [ -n "$name" ] && echo "$ip $name" > "$WORK/rdns-$ip"; } &
done < "$WORK/hosts.txt"
wait
cat "$WORK"/rdns-* 2>/dev/null | sort -u > "$WORK/rdns.txt"
if grep -iq "$PATTERN" "$WORK/rdns.txt"; then
    grep -i "$PATTERN" "$WORK/rdns.txt" | sed 's/^/  hit: /'
else
    echo "  no PTR record matched"
fi
echo

echo "raw banners: $WORK/banners.txt"
