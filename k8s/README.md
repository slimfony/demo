# Kubernetes

Manifests for the four application workloads (the web server, the identity provider, the outbox relay and the importers), with probes, a security context, autoscaling, disruption budgets and network policies. PostgreSQL, PgBouncer, NATS and Prometheus are not part of them: run those the way your cluster runs infrastructure (an operator or a managed service), and point `config.yaml` at them.

| File | What |
| --- | --- |
| `config.yaml` | settings, network policies, the ingress |
| `web.yaml` | the API and the page: 3 pods, probes (`/readyz` for startup and readiness, `/healthz` for liveness), CPU autoscaling, a disruption budget |
| `workers.yaml` | the relay (2 pods) and the importers (4 pods, autoscaled) |
| `idp.yaml` | the demo identity provider (leave it out when you have a real issuer) |

All containers run as a non-root user, with a read-only root file system, no capabilities and the runtime's default seccomp profile.

## What you need first

1. **The image**, built from the `Dockerfile` and pushed to your registry (the tag in `kustomization.yaml` is `0.2.0`):

   ```bash
   docker build --target runtime -t registry.example.com/slimfony-demo:0.2.0 .
   docker push registry.example.com/slimfony-demo:0.2.0
   ```

2. **PostgreSQL behind PgBouncer** (transaction pooling), with the schema applied and the role guards set:

   ```bash
   kubectl exec -i <postgres-pod> -- psql -U demo -d demo < docker/postgres/init.sql
   kubectl exec -i <postgres-pod> -- psql -U demo -d demo < docker/postgres/20-schema.sql
   kubectl exec -i <postgres-pod> -- psql -U demo -d demo < docker/postgres/30-import.sql
   ```

3. **NATS with JetStream** (the streams are created by the services).
4. **Secrets**, as files the services read through their `*_FILE` settings:

   ```bash
   kubectl create namespace slimfony-demo
   kubectl -n slimfony-demo create secret generic demo-secrets \
     --from-literal=db_password="$(openssl rand -base64 30 | tr -d '=+/')" \
     --from-literal=metrics_token="$(openssl rand -base64 30 | tr -d '=+/')" \
     --from-literal=mercure_key="$(openssl rand -base64 48 | tr -d '=+/')"
   ```

   The database password must match the one PostgreSQL and PgBouncer use for the role `demo`. The Mercure key must be the same for every pod that publishes (relay, importers) and for the web pods, which hold the hub.

## Install

```bash
kubectl apply -k k8s
# the web pods trust the demo identity provider's CA: copy it from the identity provider once it is up
kubectl -n slimfony-demo rollout status deploy/idp
kubectl -n slimfony-demo exec deploy/idp -- cat /data/caddy/pki/authorities/local/root.crt > root.crt
kubectl -n slimfony-demo create secret generic idp-ca --from-file=root.crt
kubectl -n slimfony-demo rollout restart deploy/web
```

With a real issuer there is no private CA: leave out `idp.yaml` and the `idp-ca` volume of `web.yaml`, and set `JWKS_URI`, `JWT_ISSUER` and `JWT_AUDIENCE`.

## Things to know

- The chaos switches are off (`CHAOS_ENABLED: "false"`); the page's chaos buttons then answer that they are turned off.
- The web pods have `max_requests` and worker limits from the image (`docker/Caddyfile`); resize the CPU request together with the number of PHP workers.
- Scale the importers on the queue, not on CPU, when you can: with [KEDA](https://keda.sh), scale on the number of pending messages of the NATS consumer `importers`.
- The identity provider keeps its signing key in the memory of one pod, so it runs as a single replica; a restart creates a new key, which the web pods pick up by themselves.
- `TRUSTED_PROXIES` in `config.yaml` is an example range: set it to the address range of your ingress controller, or the client IPs (and so the sandbox's rate limit) will be wrong.
