#!/usr/bin/env python3
"""Mock OpenAI-compatible Groq for the cross-app audit. Mode from file MODE_FILE (default success).
Modes: success | 429 | timeout | malformed | partial | slow.  Logs every request body to LOG_FILE."""
import http.server, json, os, time, sys
MODE_FILE = os.environ.get('MODE_FILE', '/tmp/claude-0/groq_mode'); LOG = os.environ.get('LOG_FILE', '/tmp/claude-0/groq_log.jsonl')
class H(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def do_POST(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', 0) or 0)).decode('utf-8', 'replace')
        with open(LOG, 'a') as f: f.write(json.dumps({'t': time.time(), 'path': self.path, 'body': body}) + '\n')
        mode = open(MODE_FILE).read().strip() if os.path.exists(MODE_FILE) else 'success'
        if mode == '429':
            self.send_response(429); self.send_header('Retry-After', '20'); self.send_header('Content-Type','application/json'); self.end_headers()
            self.wfile.write(b'{"error":{"message":"Rate limit reached","type":"tokens"}}'); return
        if mode == 'timeout': time.sleep(120); return
        if mode == 'slow': time.sleep(8)
        content = os.environ.get('CONTENT') or (open('/tmp/claude-0/groq_content').read() if os.path.exists('/tmp/claude-0/groq_content') else 'ملخص أسبوعي تجريبي.')
        if mode == 'malformed': content = '{"meals": [ {"name": "breakfast", "items": [ {"food_id": 3'
        if mode == 'partial': content = json.dumps({"meals": [{"name": "breakfast", "items": []}]})
        out = {"id": "x", "object": "chat.completion", "model": "mock", "choices": [{"index": 0, "message": {"role": "assistant", "content": content}, "finish_reason": "length" if mode == 'partial' else "stop"}], "usage": {"prompt_tokens": 100, "completion_tokens": 50, "total_tokens": 150}}
        b = json.dumps(out).encode(); self.send_response(200); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(b))); self.end_headers(); self.wfile.write(b)
http.server.ThreadingHTTPServer(('127.0.0.1', 8099), H).serve_forever()
