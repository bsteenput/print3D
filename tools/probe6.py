import socket, json, struct, base64, time, sys, io, os, hashlib
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

IP           = "192.168.0.124"
PRINTER_ID   = "f25273b12b094c5a8b9513a30ca60049"
MAINBOARD_ID = "80372a95ccd90100"

def ws_connect(ip, port, path):
    s = socket.create_connection((ip, port), timeout=5)
    key = base64.b64encode(os.urandom(16)).decode()
    hs = "\r\n".join([
        f"GET {path} HTTP/1.1", f"Host: {ip}:{port}",
        "Upgrade: websocket", "Connection: Upgrade",
        f"Sec-WebSocket-Key: {key}", "Sec-WebSocket-Version: 13", "", ""
    ])
    s.sendall(hs.encode())
    resp = s.recv(2048).decode("utf-8", errors="replace")
    return s if "101" in resp else None

def ws_send(s, msg):
    data = msg.encode("utf-8") if isinstance(msg, str) else msg
    n = len(data); mk = os.urandom(4)
    masked = bytes([data[i] ^ mk[i % 4] for i in range(n)])
    hdr = bytes([0x81, 0x80 | n]) if n < 126 else bytes([0x81, 0xFE, (n>>8)&0xFF, n&0xFF])
    s.sendall(hdr + mk + masked)

def ws_recv(s, timeout=2):
    s.settimeout(timeout)
    try:
        h = s.recv(2)
        if not h or len(h) < 2: return None
        op = h[0] & 0x0F; length = h[1] & 0x7F
        if length == 126: length = struct.unpack(">H", s.recv(2))[0]
        payload = b""
        while len(payload) < length:
            chunk = s.recv(length - len(payload))
            if not chunk: break
            payload += chunk
        return f"[op={op}] " + payload.decode("utf-8", errors="replace")
    except socket.timeout:
        return None

def try_batch(label, messages, listen=4):
    ws = ws_connect(IP, 3030, "/websocket")
    if not ws:
        print(f"[connexion echouee] {label}"); return
    print(f"\n>>> {label}")
    for msg in messages:
        txt = json.dumps(msg) if isinstance(msg, dict) else msg
        ws_send(ws, txt)
        print(f"  >> {txt[:100]}")
        time.sleep(0.3)
    got = 0
    end = time.time() + listen
    while time.time() < end:
        r = ws_recv(ws, 1)
        if r:
            print(f"  << {r[:300]}"); got += 1
    if not got:
        print("  (aucune reponse)")
    ws.close()

# Tokens derives du MainboardID
md5_id  = hashlib.md5(MAINBOARD_ID.encode()).hexdigest()
sha_id  = hashlib.sha256(MAINBOARD_ID.encode()).hexdigest()[:32]

# 1. Connect avec MainboardID comme token
try_batch("Auth via MainboardID", [
    {"Id": PRINTER_ID, "Data": {"Cmd": 0, "MainboardID": MAINBOARD_ID}},
    {"Id": PRINTER_ID, "Data": {"Cmd": 386, "MainboardID": MAINBOARD_ID}},
])

# 2. Token MD5 du MainboardID
try_batch("Auth via token MD5", [
    {"Id": PRINTER_ID, "Data": {"Cmd": 0, "Token": md5_id}},
    {"Id": PRINTER_ID, "Data": {"Cmd": 386, "Token": md5_id}},
])

# 3. Format vu dans certaines imprimantes Chitu (Cmd=512 = file list, Cmd=386 = print status)
try_batch("Commandes avec RequestID", [
    {"Id": PRINTER_ID, "Data": {"Cmd": 512, "RequestID": "req001"}},
    {"Id": PRINTER_ID, "Data": {"Cmd": 386, "RequestID": "req002"}},
    {"Id": PRINTER_ID, "Data": {"Cmd": 64,  "RequestID": "req003"}},
])

# 4. Format observe dans firmware Elegoo recents
try_batch("Format Elegoo V3 avec MainboardID", [
    {"Id": MAINBOARD_ID, "Data": {"Cmd": 0}},
    {"Id": MAINBOARD_ID, "Data": {"Cmd": 386}},
    {"Id": MAINBOARD_ID, "Data": {"Cmd": 128}},
])

# 5. Connexion via HTTP sur port 3030 avec le bon path
print("\n=== Tentatives HTTP sur port 3030 avec MainboardID ===")
import urllib.request
for path in [
    f"/api/{MAINBOARD_ID}/status",
    f"/api/{PRINTER_ID}/status",
    f"/{MAINBOARD_ID}/status",
    "/api/getprinterstatus",
    "/api/printer/getstate",
]:
    try:
        url = f"http://{IP}:3030{path}"
        with urllib.request.urlopen(url, timeout=2) as r:
            body = r.read().decode("utf-8", errors="replace")
            print(f"[{r.status}] {path} -> {body[:200]}")
    except urllib.error.HTTPError as e:
        print(f"[{e.code}] {path}")
    except Exception as e:
        print(f"[--] {path} -> {str(e)[:60]}")

print("\nTermine.")
