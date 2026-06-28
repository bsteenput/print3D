import socket, json, struct, hashlib, base64, time, sys, io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

IP = "192.168.0.124"

# ── 1. UDP discovery complet ──────────────────────────────────
print("=== UDP Discovery complet (port 3000) ===")
s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
s.settimeout(3)
s.sendto(b"M99999", (IP, 3000))
try:
    data, addr = s.recvfrom(4096)
    raw = data.decode("utf-8", errors="replace")
    print("Reponse brute:", raw)
    try:
        parsed = json.loads(raw)
        print("JSON parse:")
        print(json.dumps(parsed, indent=2, ensure_ascii=False))
    except:
        pass
except socket.timeout:
    print("timeout UDP")
s.close()

# ── 2. WebSocket manuel sur /websocket ───────────────────────
print()
print("=== WebSocket ws://192.168.0.124:3030/websocket ===")

def ws_connect(ip, port, path):
    s = socket.create_connection((ip, port), timeout=5)
    key = base64.b64encode(b"print3dprobe1234").decode()
    hs = (
        "GET " + path + " HTTP/1.1\r\n"
        "Host: " + ip + ":" + str(port) + "\r\n"
        "Upgrade: websocket\r\n"
        "Connection: Upgrade\r\n"
        "Sec-WebSocket-Key: " + key + "\r\n"
        "Sec-WebSocket-Version: 13\r\n\r\n"
    )
    s.sendall(hs.encode())
    resp = s.recv(1024).decode("utf-8", errors="replace")
    print("Handshake response:", resp.split("\r\n")[0])
    if "101" not in resp:
        s.close()
        return None
    return s

def ws_send(s, msg):
    """Envoie un frame WebSocket (text, non masque pour test)."""
    data = msg.encode("utf-8")
    n = len(data)
    # Frame: FIN=1, opcode=1 (text), no mask
    header = bytes([0x81])
    if n < 126:
        header += bytes([n])
    elif n < 65536:
        header += bytes([126, (n >> 8) & 0xFF, n & 0xFF])
    else:
        header += bytes([127]) + struct.pack(">Q", n)
    s.sendall(header + data)

def ws_recv(s, timeout=3):
    """Recoit un frame WebSocket."""
    s.settimeout(timeout)
    try:
        header = s.recv(2)
        if len(header) < 2:
            return None
        opcode = header[0] & 0x0F
        length = header[1] & 0x7F
        if length == 126:
            ext = s.recv(2)
            length = struct.unpack(">H", ext)[0]
        elif length == 127:
            ext = s.recv(8)
            length = struct.unpack(">Q", ext)[0]
        if length == 0:
            return ""
        payload = b""
        while len(payload) < length:
            chunk = s.recv(length - len(payload))
            if not chunk:
                break
            payload += chunk
        return payload.decode("utf-8", errors="replace")
    except socket.timeout:
        return None

ws = ws_connect(IP, 3030, "/websocket")
if not ws:
    print("Echec connexion WebSocket")
    sys.exit(1)

print("Connecte ! Envoi de commandes...")

# Commandes candidates Chitu V3 / Elegoo
commands = [
    # Format Elegoo V3
    '{"Id":"test001","Data":{"Cmd":386,"RequestID":"status001"}}',
    '{"Id":"test002","Data":{"Cmd":0}}',
    '{"Id":"test003","Data":{"Cmd":1}}',
    # Format simple
    '{"cmd":"status"}',
    '{"cmd":"get_status"}',
    '{"action":"status"}',
    # GCode
    "M27",
    "M997",
    # Format Chitu
    '{"Cmd":386}',
    '{"type":"status"}',
]

for cmd in commands:
    print()
    print("ENVOI: " + cmd)
    ws_send(ws, cmd)
    resp = ws_recv(ws, timeout=2)
    if resp is not None:
        print("RECU:  " + (resp[:300] if resp else "(vide)"))
    else:
        print("RECU:  timeout")
    time.sleep(0.3)

# Ecoute passive 5 secondes
print()
print("=== Ecoute passive 5s (messages spontanes) ===")
end = time.time() + 5
while time.time() < end:
    r = ws_recv(ws, timeout=1)
    if r:
        print("SPONTANE: " + r[:300])

ws.close()
print("Termine.")
