import socket, json, struct, base64, time, sys, io, os
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

IP = "192.168.0.124"
PRINTER_ID = "f25273b12b094c5a8b9513a30ca60049"  # ID recupere via UDP

def ws_connect(ip, port, path):
    s = socket.create_connection((ip, port), timeout=5)
    key = base64.b64encode(os.urandom(16)).decode()
    hs = "\r\n".join([
        f"GET {path} HTTP/1.1",
        f"Host: {ip}:{port}",
        "Upgrade: websocket",
        "Connection: Upgrade",
        f"Sec-WebSocket-Key: {key}",
        "Sec-WebSocket-Version: 13",
        "", ""
    ])
    s.sendall(hs.encode())
    resp = s.recv(1024).decode("utf-8", errors="replace")
    if "101" not in resp:
        print("Handshake echoue:", resp[:100])
        s.close()
        return None
    print("WebSocket connecte (101)")
    return s

def ws_send(s, msg):
    """Frame WebSocket avec masquage obligatoire (RFC 6455 client)."""
    data = msg if isinstance(msg, bytes) else msg.encode("utf-8")
    n = len(data)
    mask = os.urandom(4)
    masked = bytes([data[i] ^ mask[i % 4] for i in range(n)])
    header = bytes([0x81])  # FIN + text opcode
    if n < 126:
        header += bytes([0x80 | n])
    elif n < 65536:
        header += bytes([0x80 | 126, (n >> 8) & 0xFF, n & 0xFF])
    else:
        header += bytes([0x80 | 127]) + struct.pack(">Q", n)
    s.sendall(header + mask + masked)

def ws_recv(s, timeout=3):
    s.settimeout(timeout)
    try:
        h = s.recv(2)
        if len(h) < 2:
            return None
        length = h[1] & 0x7F
        if length == 126:
            length = struct.unpack(">H", s.recv(2))[0]
        elif length == 127:
            length = struct.unpack(">Q", s.recv(8))[0]
        payload = b""
        while len(payload) < length:
            chunk = s.recv(length - len(payload))
            if not chunk:
                break
            payload += chunk
        return payload.decode("utf-8", errors="replace")
    except socket.timeout:
        return None
    except Exception as e:
        return f"[ERREUR RECV: {e}]"

ws = ws_connect(IP, 3030, "/websocket")
if not ws:
    sys.exit(1)

def try_cmd(label, cmd):
    msg = json.dumps(cmd) if isinstance(cmd, dict) else cmd
    print(f"\n[>>] {label}")
    print(f"     {msg[:120]}")
    ws_send(ws, msg)
    # Attend jusqu'a 3 reponses ou timeout
    for _ in range(3):
        r = ws_recv(ws, timeout=2)
        if r is None:
            break
        print(f"[<<] {r[:300]}")

# ── Commandes avec masquage correct ──────────────────────────

# 1. Ping / heartbeat
try_cmd("Heartbeat vide", {"Id": PRINTER_ID, "Data": {}})
try_cmd("Cmd 0 heartbeat", {"Id": PRINTER_ID, "Data": {"Cmd": 0}})

# 2. Status impression
try_cmd("Cmd 386 (status)", {"Id": PRINTER_ID, "Data": {"Cmd": 386}})
try_cmd("Cmd 1 (status v2)", {"Id": PRINTER_ID, "Data": {"Cmd": 1}})
try_cmd("Cmd 128 (info)",    {"Id": PRINTER_ID, "Data": {"Cmd": 128}})
try_cmd("Cmd 258 (status3)", {"Id": PRINTER_ID, "Data": {"Cmd": 258}})

# 3. Sans Id
try_cmd("Sans Id, Cmd 386", {"Data": {"Cmd": 386}})
try_cmd("Juste Cmd",        {"Cmd": 386})

# 4. Format alternatif
try_cmd("action=status",    {"action": "status", "id": PRINTER_ID})
try_cmd("type=query",       {"type": "query", "data": {"action": "getStatus"}})

# 5. Ecoute passive 8 secondes (messages spontanes de l'imprimante)
print("\n=== Ecoute passive 8s ===")
end = time.time() + 8
got = 0
while time.time() < end:
    r = ws_recv(ws, timeout=1)
    if r:
        print(f"[SPONTANE] {r[:400]}")
        got += 1

if got == 0:
    print("(aucun message spontane)")

ws.close()
print("\nTermine.")
