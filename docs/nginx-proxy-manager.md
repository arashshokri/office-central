# Nginx Proxy Manager

The Compose stack includes Nginx Proxy Manager (NPM) as the public reverse proxy and TLS manager. Public ports are 80 and 443. Its administration port 81 is bound only to server loopback so the management UI is not exposed to the internet.

The Compose network uses the dedicated `172.29.87.0/24` range. Laravel trusts forwarded headers only from NPM (`172.29.87.10`) and the internal Nginx (`172.29.87.20`), not from arbitrary proxies. Change this subnet consistently in `compose.yaml` and `bootstrap/app.php` if it conflicts with an existing Docker network.

Open an SSH tunnel from your computer:

```bash
ssh -L 8181:127.0.0.1:81 root@SERVER_IP
```

Then open `http://127.0.0.1:8181`. Complete NPM's initial administrator setup and immediately use a strong unique password. Create a Proxy Host with:

```text
Domain Names: my.ponet.ir
Scheme: http
Forward Hostname/IP: nginx
Forward Port: 80
Websockets Support: enabled
Block Common Exploits: enabled
```

On the SSL tab request a new Let's Encrypt certificate, accept the terms, and enable Force SSL, HTTP/2 Support, and HSTS after confirming HTTPS works. The application Nginx is also available only on the server at `127.0.0.1:8787`; it is useful for health checks and must not be opened in the public firewall.

DNS must contain an `A` record for `my.ponet.ir` pointing to the server's public IPv4 address. Add `AAAA` only when IPv6 routing and firewall rules are configured. Allow public TCP 80 and 443; keep 81 and 8787 private.
