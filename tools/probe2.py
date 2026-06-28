import socket, sys, time
import urllib.request, urllib.error
import io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

IP = "192.168.0.124"

print("=== WebSocket paths sur port 3030 ===")
ws_paths = ["/", "/ws", "/websocket", "/socket", "/api/ws", "/ctrl", "/printer", "/api"]
for path in ws_paths:
    try:
        s = socket.create_connection((IP, 3030), timeout=2)
        hs = "GET " + path + " HTTP/1.1\r\nHost: " + IP + ":3030\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n"
        s.sendall(hs.encode())
        resp = s.recv(1024).decode("utf-8", errors="replace")
        first_line = resp.split("\r\n")[0]
        print("  " + path + " -> " + first_line)
        s.close()
    except Exception as e:
        print("  " + path + " -> " + str(e)[:60])

print()
print("=== UDP probe ===")
udp_ports = [3000, 3030, 5000, 6000]
for port in udp_ports:
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.settimeout(2)
        for msg in [b"M99999", b"ping"]:
            s.sendto(msg, (IP, port))
        try:
            data, addr = s.recvfrom(4096)
            print("[OK] UDP port " + str(port) + " -> " + str(data[:200]))
        except socket.timeout:
            print("[--] UDP port " + str(port) + " -> timeout")
        s.close()
    except Exception as e:
        print("[--] UDP port " + str(port) + " -> " + str(e))

print()
print("=== Raw TCP port 3030 (envoie JSON) ===")
try:
    s = socket.create_connection((IP, 3030), timeout=3)
    msgs = [
        b'{"Id":0,"Data":{"Cmd":386,"RequestID":"status"}}\n',
        b'{"cmd":"get_status"}\n',
        b'M27\n',
    ]
    for msg in msgs:
        s.sendall(msg)
        time.sleep(0.5)
    s.settimeout(3)
    try:
        resp = s.recv(4096)
        print("[OK] Reponse raw TCP: " + resp.decode("utf-8", errors="replace")[:300])
    except socket.timeout:
        print("[--] Pas de reponse TCP raw")
    s.close()
except Exception as e:
    print("[--] " + str(e))

print()
print("=== HTTP paths supplementaires port 3030 ===")
extra_paths = [
    "/upload", "/fileupload", "/files",
    "/print/current", "/ctrl/status",
    "/api/v1/print/status",
]
for path in extra_paths:
    try:
        with urllib.request.urlopen("http://" + IP + ":3030" + path, timeout=2) as r:
            body = r.read().decode("utf-8", errors="replace")
            print("[" + str(r.status) + "] " + path + " -> " + body[:150])
    except urllib.error.HTTPError as e:
        print("[" + str(e.code) + "] " + path)
    except Exception as e:
        print("[--] " + path + " -> " + str(e)[:50])
