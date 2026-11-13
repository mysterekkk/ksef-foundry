import { createServer, type IncomingMessage, type Server, type ServerResponse } from 'node:http';
import type { AddressInfo, Socket } from 'node:net';

export interface LocalServer {
  readonly baseUrl: string;
  readonly hits: { method: string; url: string; headers: IncomingMessage['headers']; body: string }[];
  close(): Promise<void>;
}

export type Handler = (req: IncomingMessage, res: ServerResponse, body: string, hit: number) => void | Promise<void>;

/** REAL local HTTP server (fault-injection fixture; never proof of KSeF compatibility). */
export async function startServer(handler: Handler): Promise<LocalServer> {
  const hits: LocalServer['hits'] = [];
  const sockets = new Set<Socket>();
  const server: Server = createServer((req, res) => {
    const chunks: Buffer[] = [];
    req.on('data', (c: Buffer) => chunks.push(c));
    req.on('end', () => {
      const body = Buffer.concat(chunks).toString('utf8');
      hits.push({ method: req.method ?? '', url: req.url ?? '', headers: req.headers, body });
      void Promise.resolve(handler(req, res, body, hits.length)).catch(() => {
        res.destroy();
      });
    });
  });
  server.on('connection', (s) => {
    sockets.add(s);
    s.on('close', () => sockets.delete(s));
  });
  await new Promise<void>((r) => server.listen(0, '127.0.0.1', r));
  const port = (server.address() as AddressInfo).port;
  return {
    baseUrl: `http://127.0.0.1:${String(port)}/v2`,
    hits,
    close: () =>
      new Promise<void>((resolve) => {
        for (const s of sockets) s.destroy();
        server.close(() => {
          resolve();
        });
      }),
  };
}

export function json(res: ServerResponse, status: number, body: unknown, headers: Record<string, string> = {}): void {
  const text = JSON.stringify(body);
  res.writeHead(status, { 'content-type': 'application/json', 'content-length': Buffer.byteLength(text), ...headers });
  res.end(text);
}
