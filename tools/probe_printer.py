"""
Probe Elegoo Saturn 4 Ultra -- Chitu V3 API
Usage : python probe_printer.py [ip]
"""
import urllib.request
import urllib.error
import json
import socket
import sys
import time
import io

# Force UTF-8 sur stdout Windows
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

IP = sys.argv[1] if len(sys.argv) > 1 else "192.168.0.124"
TIMEOUT = 4

def ok(s):  print("[OK] " + s)
def err(s): print("[--] " + s)
def inf(s): print("[..] " + s)

def http_get(path, port=80):
    url = f"http://{IP}:{port}{path}"
    try:
        req = urllib.request.Request(url, headers={"Accept": "application/json"})
        with urllib.request.urlopen(req, timeout=TIMEOUT) as r:
            body = r.read().decode("utf-8", errors="replace")
            return r.status, body
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", errors="replace")
    except Exception as e:
        return None, str(e)

def http_post(path, data=b"", content_type="application/json", port=80):
    url = f"http://{IP}:{port}{path}"
    try:
        req = urllib.request.Request(
            url, data=data,
            headers={"Content-Type": content_type, "Accept": "application/json"},
            method="POST"
        )
        with urllib.request.urlopen(req, timeout=TIMEOUT) as r:
            return r.status, r.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", errors="replace")
    except Exception as e:
        return None, str(e)

def udp_discovery():
    inf("UDP discovery (broadcast port 3000)...")
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
        s.settimeout(3)
        s.sendto(b"M99999", ("<broadcast>", 3000))
        s.sendto(b"M99999", (IP, 3000))
        data, addr = s.recvfrom(4096)
        ok(f"Reponse UDP de {addr}: {data.decode('utf-8', errors='replace')}")
        return data
    except socket.timeout:
        err("Pas de reponse UDP (timeout 3s)")
    except Exception as e:
        err(f"UDP error: {e}")
    finally:
        s.close()
    return None

def show(status, body, label):
    if status is None:
        err(f"{label} -> injoignable ({body})")
        return False
    try:
        parsed = json.loads(body)
        ok(f"{label} -> HTTP {status} (JSON valide)")
        print(json.dumps(parsed, indent=2, ensure_ascii=False)[:1000])
        return True
    except Exception:
        if status == 200:
            ok(f"{label} -> HTTP {status} (non-JSON)")
            print("  " + body[:300])
            return True
        err(f"{label} -> HTTP {status} | {body[:120]}")
        return False

# ================================================================
print("\n" + "="*55)
print(f"  Probe Elegoo Saturn 4 Ultra -- {IP}")
print("="*55 + "\n")

# 1. TCP port 80
inf("Test TCP port 80...")
try:
    s = socket.create_connection((IP, 80), timeout=3); s.close()
    ok("Port 80 ouvert")
except Exception as e:
    err(f"Port 80 inaccessible : {e}")
    print("\n[!] Verifie que l'imprimante est allumee et sur le meme reseau.")
    sys.exit(1)

# 2. UDP
print()
udp_discovery()

# 3. GET endpoints
print("\n" + "-"*55)
print("  Endpoints GET Chitu V3")
print("-"*55)

get_endpoints = [
    ("/api/print/currentsession",  80),
    ("/api/print/status",          80),
    ("/api/mainboard/status",      80),
    ("/api/version",               80),
    ("/api/files",                 80),
    ("/api/getglobalcfg",          80),
    ("/status",                    80),
    ("/",                          80),
    ("/api/print/currentsession",  3000),
    ("/api/status",                3000),
]

found = []
for path, port in get_endpoints:
    label = f"GET :{port}{path}"
    status, body = http_get(path, port)
    if show(status, body, label):
        found.append((label, body))
    time.sleep(0.15)

# 4. POST endpoints
print("\n" + "-"*55)
print("  Endpoints POST Chitu V3")
print("-"*55)

post_candidates = [
    ("/api/print/status",  b"{}",          "application/json"),
    ("/api/getprintinfo",  b"{}",          "application/json"),
    ("/api/gomain",        b"{}",          "application/json"),
    ("/api/print/status",  b"cmd=status",  "application/x-www-form-urlencoded"),
]

for path, data, ct in post_candidates:
    label = f"POST {path}"
    status, body = http_post(path, data, ct)
    if show(status, body, label):
        found.append((label, body))
    time.sleep(0.15)

# 5. Resume
print("\n" + "="*55)
if found:
    print(f"  {len(found)} endpoint(s) qui repondent :")
    for label, _ in found:
        print(f"    - {label}")
else:
    print("  Aucun endpoint n'a repondu.")
    print("""
  Pistes :
  - Verifie l'IP : Menu imprimante -> Reseau -> WiFi
  - Active "LAN Mode" ou "Network Printing" dans les reglages
  - Essaie : ping 192.168.0.124
""")
print("="*55 + "\n")
