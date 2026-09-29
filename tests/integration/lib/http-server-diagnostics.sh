#!/usr/bin/env bash

supcheckout_dump_http_server_diagnostics() {
  local label="$1"
  local curl_rc="$2"
  local server_pid="$3"
  local server_log="$4"

  {
    echo "SUPCheckout HTTP transport failure: $label"
    echo "curl exit: $curl_rc"
    echo "PHP server PID: ${server_pid:-<unset>}"

    if [[ -n "$server_pid" ]] && kill -0 "$server_pid" 2>/dev/null; then
      echo 'PHP server state: alive'
      ps -p "$server_pid" -o pid=,ppid=,stat=,etime=,args= || true
    else
      echo 'PHP server state: not running'
    fi

    echo '--- PHP server log ---'
    if [[ -f "$server_log" ]]; then
      cat "$server_log"
    else
      echo "(missing: $server_log)"
    fi
    echo '--- end PHP server log ---'

    # nginx + PHP-FPM (the authoritative request-context stack) records its
    # evidence in the stack directory; a 502 is otherwise undiagnosable.
    local stack_dir="${SUPCHECKOUT_HTTP_STACK_DIR:-${RUNNER_TEMP:-/tmp}/supcheckout-http-stack}"
    local stack_log
    if [[ -d "$stack_dir" ]]; then
      for stack_log in fpm-error.log php-error.log nginx-error.log fpm-stdout.log; do
        echo "--- HTTP stack ${stack_log} (last 200 lines) ---"
        if [[ -f "$stack_dir/$stack_log" ]]; then
          tail -n 200 "$stack_dir/$stack_log"
        else
          echo "(missing: $stack_dir/$stack_log)"
        fi
      done
      echo '--- end HTTP stack logs ---'
    fi
  } >&2
}

supcheckout_curl_once_or_diagnose() {
  local label="$1"
  local server_pid="$2"
  local server_log="$3"
  local curl_rc=0
  shift 3

  if curl "$@"; then
    return 0
  else
    curl_rc=$?
  fi

  supcheckout_dump_http_server_diagnostics "$label" "$curl_rc" "$server_pid" "$server_log"
  return "$curl_rc"
}
