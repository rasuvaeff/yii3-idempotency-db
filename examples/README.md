# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `basic-usage.php` | DbIdempotencyStorage claim/store/load cycle with SQLite | No |

## Running

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/basic-usage.php
```
