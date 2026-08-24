# MDI Native MySQL Engine

This module is the executable MySQL protocol and query-engine boundary tracked
by [issue #232](https://github.com/Automattic/markdown-database-integration/issues/232).
It pins `dolthub/go-mysql-server` and will supply a custom MDI canonical storage
backend. It does not embed SQLite or require a MySQL/MariaDB server.

## Decision Constraints

- `go-mysql-server` is pinned at `v0.20.0`; its pre-1.0 backend interfaces are
  upgraded only with differential compatibility evidence.
- Production builds use the normal ICU-compatible engine path. The
  `gms_pure_go` build tag is excluded because upstream documents known MySQL
  compatibility failures under that implementation.
- The engine runs as an MDI-managed local sidecar because a real MySQL protocol
  endpoint preserves `mysqli`, `$wpdb->dbh`, and direct plugin clients.
- The sidecar frontend is generic. WordPress and canonical-file semantics belong
  in backend adapters rather than parser or protocol forks.
- The tagged upstream example currently omits the required context factory; MDI
  follows the compiled API and upstream issue
  [#2884](https://github.com/dolthub/go-mysql-server/issues/2884).

The current milestone uses the engine's memory backend only to prove that PHP
`mysqli`, prepared statements, session variables, result metadata, and errors
work through the standard MySQL wire protocol. Production `db.php` routing and
canonical storage are later milestones.

Run the protocol integration gate from the repository root:

```bash
bash tests/run-native-mysql-sidecar.sh
```

Run the Go gate directly:

```bash
cd native-engine
go test ./...
```

The sidecar binds to `127.0.0.1:0` by default and writes one JSON readiness
envelope to stdout. Query text is omitted from sidecar logs.
