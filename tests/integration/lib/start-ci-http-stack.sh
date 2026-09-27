#!/usr/bin/env bash
# Start a production-style concurrent HTTP stack (nginx + PHP-FPM) for
# disposable request-context certification. Replaces php -S, which segfaults
# under multi-request WordPress/Woo workloads on some PHP builds.
#
# Usage: source start-ci-http-stack.sh <wp-root> <port>
# Sets: HTTP_STACK_PID / PHP_FPM_PID
# This file is sourced; never use bare `exit` (use `return`).

wp_root="${1:?usage: source start-ci-http-stack.sh <wp-root> <port>}"
port="${2:?port required}"
conf_dir="${RUNNER_TEMP:-/tmp}/supcheckout-http-stack"
mkdir -p "$conf_dir"

# Locate php-fpm for the active PHP version.
PHP_BIN="$(command -v php)"
PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
FPM_BIN=''
for cand in \
  "php-fpm${PHP_VER}" \
  "php-fpm${PHP_VER%%.*}" \
  "php-fpm" \
  "/usr/sbin/php-fpm${PHP_VER}" \
  "/usr/sbin/php-fpm"; do
  if command -v "$cand" >/dev/null 2>&1; then
    FPM_BIN="$(command -v "$cand")"
    break
  elif [[ -x "$cand" ]]; then
    FPM_BIN="$cand"
    break
  fi
done

if [[ -z "$FPM_BIN" ]]; then
  dir="$(dirname "$PHP_BIN")"
  for cand in "$dir/php-fpm${PHP_VER}" "$dir/php-fpm"; do
    if [[ -x "$cand" ]]; then
      FPM_BIN="$cand"
      break
    fi
  done
fi

if [[ -z "$FPM_BIN" ]]; then
  echo "php-fpm not found for PHP ${PHP_VER}" >&2
  return 72
fi

# Reject an FPM binary from a different PHP major.minor than the CLI.
FPM_VER="$("$FPM_BIN" -v 2>/dev/null | head -1 | grep -oE '[0-9]+\.[0-9]+' | head -1 || true)"
if [[ -n "$FPM_VER" && "$FPM_VER" != "$PHP_VER" ]]; then
  echo "php-fpm version mismatch: FPM=$FPM_VER CLI=$PHP_VER ($FPM_BIN)" >&2
  return 74
fi

# Use a unique unix socket to avoid TCP port collisions between iterations.
FPM_SOCK="$conf_dir/fpm.sock"
rm -f "$FPM_SOCK"

cat >"$conf_dir/fpm.conf" <<EOF
[global]
error_log = $conf_dir/fpm-error.log
daemonize = no

[www]
listen = $FPM_SOCK
listen.mode = 0666
pm = dynamic
pm.max_children = 16
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 8
request_terminate_timeout = 15s
catch_workers_output = yes
php_admin_value[error_log] = $conf_dir/php-error.log
php_admin_value[max_execution_time] = 12
php_admin_value[memory_limit] = 256M
php_admin_value[display_errors] = On
EOF

# Tiny readiness probe that must execute via PHP-FPM (not static).
cat >"$wp_root/supcheckout-fpm-probe.php" <<'EOF'
<?php echo 'FPM_OK ' . PHP_VERSION;
EOF

# Portable fastcgi params (avoid depending on distro nginx include path).
cat >"$conf_dir/fastcgi_params" <<'EOF'
fastcgi_param  QUERY_STRING       $query_string;
fastcgi_param  REQUEST_METHOD     $request_method;
fastcgi_param  CONTENT_TYPE       $content_type;
fastcgi_param  CONTENT_LENGTH     $content_length;
fastcgi_param  SCRIPT_NAME        $fastcgi_script_name;
fastcgi_param  REQUEST_URI        $request_uri;
fastcgi_param  DOCUMENT_URI       $document_uri;
fastcgi_param  DOCUMENT_ROOT       $document_root;
fastcgi_param  SERVER_PROTOCOL    $server_protocol;
fastcgi_param  REQUEST_SCHEME     $scheme;
fastcgi_param  HTTPS              $https if_not_empty;
fastcgi_param  GATEWAY_INTERFACE  CGI/1.1;
fastcgi_param  SERVER_SOFTWARE    nginx;
fastcgi_param  REMOTE_ADDR        $remote_addr;
fastcgi_param  REMOTE_PORT        $remote_port;
fastcgi_param  SERVER_ADDR        $server_addr;
fastcgi_param  SERVER_PORT        $server_port;
fastcgi_param  SERVER_NAME        $server_name;
fastcgi_param  REDIRECT_STATUS    200;
EOF

cat >"$conf_dir/nginx.conf" <<EOF
worker_processes 2;
error_log $conf_dir/nginx-error.log;
pid $conf_dir/nginx.pid;
events { worker_connections 256; }
http {
  access_log $conf_dir/nginx-access.log;
  client_max_body_size 8m;
  server {
    listen 127.0.0.1:${port};
    server_name _;
    root ${wp_root};
    index index.php index.html;
    location / {
      try_files \$uri \$uri/ /index.php?\$args;
    }
    location ~ \\.php\$ {
      include $conf_dir/fastcgi_params;
      fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
      fastcgi_pass unix:$FPM_SOCK;
      fastcgi_read_timeout 15s;
      fastcgi_send_timeout 15s;
    }
  }
}
EOF

# Free HTTP port from any previous stack instance before binding.
if command -v fuser >/dev/null 2>&1; then
  fuser -k "${port}/tcp" 2>/dev/null || true
fi
sleep 0.5

"$FPM_BIN" -y "$conf_dir/fpm.conf" -F >"$conf_dir/fpm-stdout.log" 2>&1 &
PHP_FPM_PID=$!

# Wait for the FPM socket to accept connections.
for _ in $(seq 1 20); do
  if [[ -S "$FPM_SOCK" ]]; then
    break
  fi
  sleep 0.5
done

if ! command -v nginx >/dev/null 2>&1; then
  echo "nginx not found" >&2
  kill "$PHP_FPM_PID" 2>/dev/null || true
  return 73
fi

nginx -c "$conf_dir/nginx.conf" -g "daemon off;" >"$conf_dir/nginx-stdout.log" 2>&1 &
HTTP_STACK_PID=$!
sleep 1

ready=0
for _ in $(seq 1 30); do
  # Readiness must execute PHP-FPM (not only static files).
  if curl -fsS --max-time 5 "http://127.0.0.1:${port}/supcheckout-fpm-probe.php" 2>/dev/null | grep -q FPM_OK; then
    # Also require WordPress itself to respond via FPM.
    if curl -fsS --max-time 10 "http://127.0.0.1:${port}/wp-login.php" >/dev/null 2>&1; then
      ready=1
      break
    fi
  fi
  if ! kill -0 "$PHP_FPM_PID" 2>/dev/null || ! kill -0 "$HTTP_STACK_PID" 2>/dev/null; then
    break
  fi
  sleep 1
done

if [[ "$ready" != "1" ]]; then
  echo "HTTP stack failed to become ready" >&2
  echo "--- fpm ---" >&2
  cat "$conf_dir/fpm-error.log" >&2 2>/dev/null || true
  echo "--- php ---" >&2
  cat "$conf_dir/php-error.log" >&2 2>/dev/null || true
  echo "--- nginx ---" >&2
  cat "$conf_dir/nginx-error.log" >&2 2>/dev/null || true
  kill "$HTTP_STACK_PID" "$PHP_FPM_PID" 2>/dev/null || true
  HTTP_STACK_PID=''
  PHP_FPM_PID=''
  return 70
fi

echo "HTTP_STACK ready nginx+php-fpm (${PHP_VER}) on 127.0.0.1:${port}"
