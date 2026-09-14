# Existing host Nginx proxy

Office Central does not bind public ports 80 or 443. Its application Nginx is published only on server loopback at `127.0.0.1:8787`, so it can coexist with an existing Nginx, Nginx UI, CDN connector, or reverse-proxy service on the host.

Create a proxy entry for `my.ponet.ir` with upstream `http://127.0.0.1:8787`. Enable WebSocket support if the UI offers it, preserve the original Host header, and forward the client/protocol headers. A native Nginx server block uses:

```nginx
server {
    listen 80;
    server_name my.ponet.ir;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name my.ponet.ir;

    ssl_certificate /path/to/fullchain.pem;
    ssl_certificate_key /path/to/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8787;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Port $server_port;
    }
}
```

Issue and renew TLS in the existing host proxy/UI. Keep port 8787 closed in the public firewall; test it locally with `curl http://127.0.0.1:8787/health`.
