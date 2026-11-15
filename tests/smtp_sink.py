#!/usr/bin/env python3
"""Minimal SMTP sink for tests: accepts any message (incl. AUTH) and appends it to a file. Never relays."""
import socketserver, sys
OUT = sys.argv[2] if len(sys.argv) > 2 else '/tmp/smtp_sink.log'
class H(socketserver.StreamRequestHandler):
    def w(self, s): self.wfile.write((s + '\r\n').encode())
    def handle(self):
        self.w('220 sink ESMTP')
        data = False; buf = []
        while True:
            line = self.rfile.readline()
            if not line: return
            l = line.decode(errors='replace').rstrip('\r\n')
            if data:
                if l == '.':
                    data = False
                    with open(OUT, 'a') as f: f.write('\n'.join(buf) + '\n----\n')
                    buf = []; self.w('250 OK queued')
                else: buf.append(l)
                continue
            u = l.upper()
            if u.startswith('EHLO'): self.w('250-sink'); self.w('250 AUTH LOGIN PLAIN')
            elif u.startswith('HELO'): self.w('250 sink')
            elif u.startswith('AUTH LOGIN'): self.w('334 VXNlcm5hbWU6'); self.rfile.readline(); self.w('334 UGFzc3dvcmQ6'); self.rfile.readline(); self.w('235 OK')
            elif u.startswith('AUTH'): self.w('235 OK')
            elif u.startswith('DATA'): data = True; self.w('354 go')
            elif u.startswith('QUIT'): self.w('221 bye'); return
            else: self.w('250 OK')
socketserver.TCPServer.allow_reuse_address = True
socketserver.ThreadingTCPServer(('127.0.0.1', int(sys.argv[1]) if len(sys.argv) > 1 else 2525), H).serve_forever()
