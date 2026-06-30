#!/usr/bin/env bash
# file: iartnet/apps/api/infra/deploy/deploy_api.sh
# Idempotent deploy script for IARTNET API (infra/docker stack).
# Usage:
#   ./deploy_api.sh [--ref BRANCH|TAG|SHA] [--no-build] [--with-db-restore] [--db-recreate] [--force-reset] [--yes]
#   ./deploy_api.sh --rollback

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

# --- Constants (aligned with compose: infra/docker/api/compose/docker-compose.yml)
APP_SERVICE="${APP_SERVICE:-app}"
HEALTH_PATH="${HEALTH_PATH:-/up}"
HTTP_PORT="${HTTP_PORT:-8088}"
SMOKE_URL="${SMOKE_URL:-http://127.0.0.1:${HTTP_PORT}${HEALTH_PATH}}"

BACKUP_SQL_NAME="${BACKUP_SQL_NAME:-iartnet1_rebuild.sql}"
MIN_DISK_MB="${MIN_DISK_MB:-500}"

DEPLOY_REF_FILE=".deploy_last_ref"
DEPLOY_PREVIOUS_REF_FILE=".deploy_previous_ref"
LOCK_FILE_DEFAULT=".deploy_api.lock"

LOG_PREFIX_INFO="[INFO]"
LOG_PREFIX_WARN="[WARN]"
LOG_PREFIX_ERR="[ERROR]"

# --- Paths (script under apps/api/infra/deploy/)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)"
API_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../../../.." && pwd)"

COMPOSE_FILE="$REPO_ROOT/infra/docker/api/compose/docker-compose.yml"
ENV_FILE="$API_DIR/.env"
BACKUP_SQL="$REPO_ROOT/dbBackup/$BACKUP_SQL_NAME"
DB_BACKUP_DIR="${DB_BACKUP_DIR:-$REPO_ROOT/dbBackup/pre_restore}"

LOCK_FILE="${LOCK_FILE:-$REPO_ROOT/$LOCK_FILE_DEFAULT}"

# --- Options
OPT_REF=""
OPT_NO_BUILD=false
OPT_WITH_DB_RESTORE=false
OPT_DB_RECREATE=false
OPT_FORCE_RESET=false
OPT_YES=false
OPT_ROLLBACK=false

usage() {
  cat << EOF
Usage: $(basename "$0") [OPTIONS]

Deploy IARTNET API (idempotent, safe-ish). Intended repo root: $REPO_ROOT

Options:
  --ref BRANCH|TAG|SHA   Deploy this ref (default: current HEAD)
  --no-build             Skip image build (use existing image)
  --with-db-restore      Restore DB from dbBackup/$BACKUP_SQL_NAME (requires --yes)
  --db-recreate          DROP/CREATE the target DB before restore (DESTRUCTIVE, requires --with-db-restore --yes)
  --force-reset          If git working tree is dirty: hard reset + git clean -fd
  --yes                  Non-interactive confirmation (required for DB restore / destructive ops)
  --rollback             Rollback: checkout previous deployed ref and restart stack
  -h, --help             This help

Environment (optional):
  HTTP_PORT              Port for smoke test (default: 8088)
  DB_BACKUP_DIR          Where to save pre-restore dumps (default: dbBackup/pre_restore)
  LOCK_FILE              Lock file path (default: $LOCK_FILE)

EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --ref) OPT_REF="${2:?--ref requires a value}"; shift 2 ;;
    --no-build) OPT_NO_BUILD=true; shift ;;
    --with-db-restore) OPT_WITH_DB_RESTORE=true; shift ;;
    --db-recreate) OPT_DB_RECREATE=true; shift ;;
    --force-reset) OPT_FORCE_RESET=true; shift ;;
    --yes) OPT_YES=true; shift ;;
    --rollback) OPT_ROLLBACK=true; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "$LOG_PREFIX_ERR Unknown option: $1" >&2; usage >&2; exit 1 ;;
  esac
done

log() { echo "$(date -Iseconds) $1"; }
log_info() { log "$LOG_PREFIX_INFO $1"; }
log_warn() { log "$LOG_PREFIX_WARN $1"; }
log_error() { log "$LOG_PREFIX_ERR $1" >&2; }
die() { log_error "$1"; exit 1; }

require_cmd() {
  command -v "$1" &>/dev/null || die "Missing command: $1"
}

compose() {
  (cd "$REPO_ROOT" && docker compose -f "$COMPOSE_FILE" "$@")
}

# Export only what we need for compose + postgres ops
export_env_for_compose() {
  if [[ -f "$ENV_FILE" ]]; then
    set -a
    # shellcheck source=/dev/null
    source "$ENV_FILE" 2>/dev/null || true
    set +a
  fi

  export POSTGRES_DB="${POSTGRES_DB:-${DB_DATABASE:-iartnet1}}"
  export POSTGRES_USER="${POSTGRES_USER:-${DB_USERNAME:-iartnet}}"
  export POSTGRES_PASSWORD="${POSTGRES_PASSWORD:-${DB_PASSWORD:-}}"
  export HTTP_PORT="${HTTP_PORT:-8088}"

  [[ -n "${POSTGRES_PASSWORD:-}" ]] || die "POSTGRES_PASSWORD is empty. Set it in apps/api/.env (or env)."
}

preflight() {
  log_info "Preflight checks..."
  require_cmd docker
  require_cmd git
  require_cmd curl
  require_cmd awk
  require_cmd flock

  docker compose version &>/dev/null || die "docker compose (v2) not found"
  [[ -f "$COMPOSE_FILE" ]] || die "Compose file not found: $COMPOSE_FILE"

  if [[ ! -f "$ENV_FILE" ]]; then
    log_warn ".env not found at $ENV_FILE (compose may fail if required vars missing)"
  fi

  local avail_mb
  avail_mb="$(df -m "$REPO_ROOT" 2>/dev/null | awk 'NR==2 {print $4}' || echo 0)"
  if [[ -n "${avail_mb}" ]] && [[ "${avail_mb}" -lt "$MIN_DISK_MB" ]]; then
    die "Low disk space: ${avail_mb}MB (min ${MIN_DISK_MB}MB)"
  fi

  if [[ "$OPT_WITH_DB_RESTORE" == true ]]; then
    [[ -f "$BACKUP_SQL" ]] || die "DB restore requested but not found: $BACKUP_SQL"
    [[ "$OPT_YES" == true ]] || die "DB restore requires --yes (explicit confirmation)"
    if [[ "$OPT_DB_RECREATE" == true ]]; then
      log_warn "DB recreate enabled: this will DROP/CREATE database (DESTRUCTIVE)."
    fi
  fi

  log_info "Preflight OK"
}

acquire_lock() {
  mkdir -p "$(dirname "$LOCK_FILE")" 2>/dev/null || true
  exec 9>"$LOCK_FILE"
  if ! flock -n 9; then
    die "Another deploy seems running (lock: $LOCK_FILE). Aborting."
  fi
  log_info "Lock acquired ($LOCK_FILE)"
}

ensure_clean_worktree() {
  (cd "$REPO_ROOT" && git update-index -q --refresh)

  if (cd "$REPO_ROOT" && ! git diff --quiet) || (cd "$REPO_ROOT" && ! git diff --cached --quiet); then
    if [[ "$OPT_FORCE_RESET" == true ]]; then
      log_warn "Working tree dirty → --force-reset enabled: doing git reset --hard + git clean -fd"
      (cd "$REPO_ROOT" && git reset --hard)
      (cd "$REPO_ROOT" && git clean -fd)
    else
      die "Working tree dirty. Commit/stash changes or use --force-reset."
    fi
  fi
}

current_sha() { (cd "$REPO_ROOT" && git rev-parse HEAD); }

git_checkout_ref() {
  local ref="${1:-HEAD}"
  log_info "Fetching and checking out: $ref"
  (cd "$REPO_ROOT" && git fetch --prune --quiet 2>/dev/null || true)
  (cd "$REPO_ROOT" && git checkout --quiet "$ref")
  log_info "Checked out: $(current_sha)"
}

record_deploy_refs_success() {
  local from_sha="$1"
  local to_sha="$2"
  echo "$from_sha" > "$REPO_ROOT/$DEPLOY_PREVIOUS_REF_FILE"
  echo "$to_sha" > "$REPO_ROOT/$DEPLOY_REF_FILE"
  log_info "Recorded refs: previous=$from_sha, last=$to_sha"
}

# Resolve postgres container via compose (no hardcoded container_name)
pg_container_id() {
  local cid
  cid="$(compose ps -q postgres 2>/dev/null | head -n 1 || true)"
  [[ -n "$cid" ]] || die "Cannot resolve postgres container id (is the stack up?)"
  echo "$cid"
}

pg_exec() {
  local cid
  cid="$(pg_container_id)"
  docker exec -i -e PGPASSWORD="$POSTGRES_PASSWORD" "$cid" "$@"
}

pg_cmd() {
  local cid
  cid="$(pg_container_id)"
  docker exec -e PGPASSWORD="$POSTGRES_PASSWORD" "$cid" "$@"
}

compose_up() {
  export_env_for_compose

  log_info "Pulling non-build images (best effort)..."
  compose pull --quiet postgres redis nginx 2>/dev/null || true

  local up_args=(-d)
  if [[ "$OPT_NO_BUILD" == true ]]; then
    up_args+=(--no-build)
  else
    up_args+=(--build)
  fi

  log_info "Starting stack..."
  compose up "${up_args[@]}"
  log_info "Stack started"
}

wait_healthy() {
  log_info "Waiting for PostgreSQL..."
  local max_attempts=60
  local attempt=1
  while [[ $attempt -le $max_attempts ]]; do
    if pg_cmd pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB" -h 127.0.0.1 -p 5432 &>/dev/null; then
      log_info "PostgreSQL is ready"
      break
    fi
    [[ $attempt -eq $max_attempts ]] && die "PostgreSQL not ready after $((max_attempts * 2))s"
    sleep 2
    attempt=$((attempt + 1))
  done

  log_info "Waiting for app health endpoint: $SMOKE_URL"
  attempt=1
  while [[ $attempt -le $max_attempts ]]; do
    if curl -sf --max-time 5 "$SMOKE_URL" &>/dev/null; then
      log_info "App health OK"
      return 0
    fi
    sleep 2
    attempt=$((attempt + 1))
  done
  die "App health check failed after $((max_attempts * 2))s"
}

artisan() { compose exec -T "$APP_SERVICE" php artisan "$@"; }

run_migrate_and_cache() {
  log_info "Running migrations..."
  artisan migrate --force

  log_info "Cache warmup..."
  artisan config:clear 2>/dev/null || true
  artisan config:cache 2>/dev/null || true
  artisan route:cache 2>/dev/null || true
  artisan view:cache 2>/dev/null || true
  artisan queue:restart 2>/dev/null || true
  log_info "Migrate/cache OK"
}

smoke_test() {
  log_info "Smoke test: $SMOKE_URL"
  local code
  code="$(curl -sS --max-time 10 -o /dev/null -w "%{http_code}" "$SMOKE_URL" 2>/dev/null || echo "000")"
  if [[ "$code" == "200" ]]; then
    log_info "Smoke test OK (HTTP 200)"
    return 0
  fi
  log_warn "Smoke test got HTTP $code"
  return 1
}

db_backup_now() {
  export_env_for_compose
  mkdir -p "$DB_BACKUP_DIR"
  local stamp dump_file
  stamp="$(date +%Y%m%d_%H%M%S)"
  dump_file="$DB_BACKUP_DIR/pre_restore_${stamp}.sql"

  log_info "Backing up current DB to $dump_file ..."
  pg_cmd pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner --no-acl > "$dump_file"
  log_info "Backup saved: $dump_file ($(wc -c < "$dump_file") bytes)"
  echo "$dump_file"
}

db_recreate_empty() {
  [[ "$OPT_YES" == true ]] || die "DB recreate requires --yes"
  log_warn "Recreating DB '$POSTGRES_DB' (DROP/CREATE)."

  pg_cmd psql -U "$POSTGRES_USER" -d postgres -v ON_ERROR_STOP=1 -c \
    "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='${POSTGRES_DB}' AND pid <> pg_backend_pid();" \
    >/dev/null 2>&1 || true

  pg_cmd dropdb -U "$POSTGRES_USER" --if-exists "$POSTGRES_DB"
  pg_cmd createdb -U "$POSTGRES_USER" "$POSTGRES_DB"
  log_info "DB recreated: $POSTGRES_DB"
}

# Streaming restore: filter only destructive DB-level statements, no big temp file
db_restore_from_file_streaming() {
  local sql_file="$1"
  log_info "Restoring DB from $sql_file (streaming filter)..."

  awk '
    BEGIN { IGNORECASE=1 }
    /^[[:space:]]*\\connect\b/ { next }
    /^[[:space:]]*\\c[[:space:]]/ { next }
    $0 ~ /(^|[[:space:]])drop[[:space:]]+database[[:space:]]/ { next }
    $0 ~ /(^|[[:space:]])create[[:space:]]+database[[:space:]]/ { next }
    $0 ~ /(^|[[:space:]])alter[[:space:]]+database[[:space:]].*[[:space:]]owner[[:space:]]+to[[:space:]]/ { next }
    { print }
  ' "$sql_file" | pg_exec psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"

  local tables
  tables="$(pg_cmd psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc \
    "select count(*) from information_schema.tables where table_schema not in ('pg_catalog','information_schema');" \
    2>/dev/null | tr -d '[:space:]' || echo "?")"
  log_info "DB sanity: ${tables} user tables"
}

do_db_restore() {
  export_env_for_compose

  log_warn "DB restore flow: stopping app and nginx..."
  compose stop "$APP_SERVICE" nginx 2>/dev/null || true

  db_backup_now >/dev/null

  if [[ "$OPT_DB_RECREATE" == true ]]; then
    db_recreate_empty
  fi

  db_restore_from_file_streaming "$BACKUP_SQL"

  log_info "Starting stack..."
  compose up -d
  wait_healthy
  run_migrate_and_cache
  smoke_test || true

  log_info "DB restore flow completed"
}

do_rollback() {
  local prev_ref_file="$REPO_ROOT/$DEPLOY_PREVIOUS_REF_FILE"
  [[ -f "$prev_ref_file" ]] || die "No previous ref found ($prev_ref_file). Cannot rollback."

  local prev_ref
  prev_ref="$(cat "$prev_ref_file")"
  [[ -n "$prev_ref" ]] || die "Previous ref file is empty."

  log_warn "Rollback: checking out previous ref: $prev_ref"
  ensure_clean_worktree
  git_checkout_ref "$prev_ref"

  export_env_for_compose
  compose up -d --build
  wait_healthy
  run_migrate_and_cache
  smoke_test || true

  log_info "Rollback completed (code). NOTE: DB was NOT rolled back automatically."
}

main() {
  local start_ts end_ts
  start_ts="$(date +%s)"
  log_info "=== IARTNET API deploy started ==="

  preflight
  acquire_lock
  export_env_for_compose

  if [[ "$OPT_ROLLBACK" == true ]]; then
    do_rollback
    end_ts="$(date +%s)"
    log_info "Duration: $((end_ts - start_ts))s"
    log_info "=== IARTNET API deploy finished ==="
    return 0
  fi

  ensure_clean_worktree

  local from_sha to_sha
  from_sha="$(current_sha)"

  if [[ -n "$OPT_REF" ]]; then
    git_checkout_ref "$OPT_REF"
  fi
  to_sha="$(current_sha)"
  log_info "Target commit: $to_sha (from: $from_sha)"

  if [[ "$OPT_WITH_DB_RESTORE" == true ]]; then
    compose_up
    wait_healthy
    do_db_restore
  else
    compose_up
    wait_healthy
    run_migrate_and_cache
    smoke_test || true
  fi

  # Record refs only on full success
  record_deploy_refs_success "$from_sha" "$to_sha"

  end_ts="$(date +%s)"
  log_info "Deployed SHA: $to_sha"
  log_info "Duration: $((end_ts - start_ts))s"
  log_info "=== IARTNET API deploy finished ==="
}

main "$@"
