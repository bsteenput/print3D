import socket, json, struct, base64, time, sys, io, os
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

IP = "192.168.0.124"
PRINTER_ID = "f25273b12b094c5a8b9513a30ca60049"

def ws_connect(ip, port, path, extra_headers=""):
    s = socket.create_connection((ip, port), timeout=5)
    key = base64.b64encode(os.urandom(16)).decode()
    hs = "\r\n".join([
        f"GET {path} HTTP/1.1",
        f"Host: {ip}:{port}",
        "Upgrade: websocket",
        "Connection: Upgrade",
        f"Sec-WebSocket-Key: {key}",
        "Sec-WebSocket-Version: 13",
        extra_headers,
        "", ""
    ])
    s.sendall(hs.encode())
    resp = s.recv(2048).decode("utf-8", errors="replace")
    print("Handshake:", resp.split("\r\n")[0])
    return s if "101" in resp else None

def ws_frame(opcode, payload=b"", mask=True):
    if isinstance(payload, str):
        payload = payload.encode("utf-8")
    n = len(payload)
    b0 = 0x80 | opcode
    if mask:
        mk = os.urandom(4)
        payload = bytes([payload[i] ^ mk[i % 4] for i in range(n)])
        if n < 126:
            return bytes([b0, 0x80 | n]) + mk + payload
        elif n < 65536:
            return bytes([b0, 0xFE, (n >> 8) & 0xFF, n & 0xFF]) + mk + payload
    else:
        if n < 126:
            return bytes([b0, n]) + payload
    return bytes([b0, 0x80 | n]) + mk + payload

def ws_recv_raw(s, timeout=3):
    s.settimeout(timeout)
    try:
        h = s.recv(2)
        if not h or len(h) < 2:
            return None, None
        opcode = h[0] & 0x0F
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
        return opcode, payload
    except socket.timeout:
        return None, None

def recv_text(s, timeout=3):
    op, data = ws_recv_raw(s, timeout)
    if op is None:
        return None
    if op == 0xA:
        return "[PONG recu]"
    if op == 0x1:
        return data.decode("utf-8", errors="replace")
    if op == 0x2:
        return f"[BINAIRE {len(data)} bytes: {data[:40].hex()}]"
    return f"[opcode={op} len={len(data or b'')}]"

# ── TEST 1 : WebSocket Ping/Pong ─────────────────────────────
print("=== Test 1 : WebSocket Ping/Pong ===")
ws = ws_connect(IP, 3030, "/websocket")
if ws:
    ws.sendall(ws_frame(0x09))  # opcode 9 = ping
    op, data = ws_recv_raw(ws, timeout=3)
    print(f"Reponse ping: opcode={op} data={data}")
    ws.close()

# ── TEST 2 : MQTT over WebSocket ─────────────────────────────
print()
print("=== Test 2 : MQTT over WebSocket ===")
ws = ws_connect(IP, 3030, "/websocket",
    extra_headers="Sec-WebSocket-Protocol: mqtt")
if ws:
    # Paquet MQTT CONNECT
    client_id = b"print3d_probe"
    proto_name = b"\x00\x04MQTT"
    proto_level = b"\x04"            # MQTT 3.1.1
    connect_flags = b"\x02"          # clean session
    keepalive = struct.pack(">H", 60)
    payload = struct.pack(">H", len(client_id)) + client_id
    var_header = proto_name + proto_level + connect_flags + keepalive
    remaining = len(var_header) + len(payload)
    mqtt_connect = bytes([0x10, remaining]) + var_header + payload

    ws.sendall(ws_frame(0x02, mqtt_connect))  # binary frame
    op, data = ws_recv_raw(ws, timeout=3)
    if op:
        print(f"MQTT CONNACK: opcode={op} data={data.hex() if data else None}")
        if data and len(data) >= 4 and data[0] == 0x20:
            code = data[3]
            codes = {0:"Accepted", 1:"Bad proto", 2:"ID rejected", 3:"Server unavail", 4:"Bad creds", 5:"Not authorized"}
            print(f"  Code CONNACK: {code} = {codes.get(code, 'inconnu')}")
            if code == 0:
                print("  -> MQTT accepte ! Abonnement aux topics...")
                # Subscribe a tous les topics Elegoo candidats
                topics = [b"#", b"status", b"printer/status", b"elegoo/status", b"print/status"]
                for topic in topics:
                    sub_payload = struct.pack(">H", 1) + struct.pack(">H", len(topic)) + topic + b"\x00"
                    sub = bytes([0x82, len(sub_payload)]) + sub_payload
                    ws.sendall(ws_frame(0x02, sub))
                print("  Ecoute 5s...")
                end = time.time() + 5
                while time.time() < end:
                    op2, d2 = ws_recv_raw(ws, timeout=1)
                    if op2:
                        print(f"  MQTT msg: {d2.hex() if d2 else ''} | {d2}")
    else:
        print("Pas de reponse MQTT CONNACK (pas MQTT)")
    ws.close()

# ── TEST 3 : Attente longue sans rien envoyer ─────────────────
print()
print("=== Test 3 : Connexion passive 10s sans envoyer (messages push?) ===")
ws = ws_connect(IP, 3030, "/websocket")
if ws:
    end = time.time() + 10
    while time.time() < end:
        r = recv_text(ws, timeout=1)
        if r:
            print(f"[PUSH] {r[:400]}")
    ws.close()
print("Termine.")
