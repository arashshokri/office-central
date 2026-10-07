# Existing Docker Nginx Proxy Manager

Central's web service joins the external network named by `PROXY_NETWORK` (default `proxynet`). It publishes no host ports. NPM keeps ports 80/443 and forwards to the unique network alias `office-central-web:80`, using scheme `http` even when the public domain uses HTTPS. PostgreSQL, Redis and PHP do not join the proxy network.

Find the actual NPM container/network names:

```bash
docker ps --format 'table {{.Names}}\t{{.Image}}'
docker inspect YOUR_NPM_CONTAINER --format '{{range $name, $_ := .NetworkSettings.Networks}}{{println $name}}{{end}}'
```

Install with `bash centralctl.sh install --domain scm.ponet.ir --email admin@example.com --proxy-network proxynet`. The canonical public domain is `scm.ponet.ir`. If a shared network needs creating, also pass `--proxy-container YOUR_NPM_CONTAINER`; the script creates it and connects NPM. Declare this external network on the NPM service in its own Compose file as well, retaining its existing networks, so the connection survives recreation.

In NPM, create a Proxy Host for the Central domain:

| Field | Value |
| --- | --- |
| Scheme | `http` |
| Forward Hostname / IP | `office-central-web` |
| Forward Port | `80` |
| SSL | Request/select the domain certificate; enable Force SSL |
| Cache Assets | Off while editing the web interface |

For ZIP uploads, add `client_max_body_size 1024m;` and `proxy_read_timeout 300s;` in the Advanced tab. Set the domain DNS records to the NPM host.

`bash centralctl.sh proxy` prints these settings. `proxy --domain NEW_DOMAIN --network NETWORK --scheme https` updates the local environment; run `start` to apply changes. Plain HTTP preview requires `--scheme http`; this also disables secure-only cookies and the HTTPS requirement for the Agent API, so switch back when enabling SSL.

The application Nginx passes its private identity to PHP-FPM and forwards the original host, client IP chain and scheme. Laravel trusts that private web IP. Health checks use HTTP from inside the web container; they do not depend on DNS, SSL, or a host port.

See the [Persian walkthrough](docker-npm-fa.md) and [NPM's network guide](https://nginxproxymanager.com/advanced-config/#best-practice-use-a-docker-network).
