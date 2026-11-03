#!/usr/bin/env bash
# Hyper Bot 启动脚本（v2）
#
# 三种架构任选，命令行优先于 Config.ini：
#
#   ./bin/hyper.sh start                    # 按配置启动（auto 能力检测）
#   ./bin/hyper.sh start --arch=swoole      # 强制 Swoole 常驻
#   ./bin/hyper.sh start --arch=php         # PHP 内置服务器（无需 Swoole）
#   ./bin/hyper.sh start --arch=webui       # 只跑 WebUI 管理面板
#   ./bin/hyper.sh start --port=8080        # 改端口
#
#   ./bin/hyper.sh stop                     # 停止
#   ./bin/hyper.sh restart --arch=php       # 重启
#   ./bin/hyper.sh status                   # 状态
#   ./bin/hyper.sh logs                     # 跟踪日志
#   ./bin/hyper.sh doctor                   # 环境体检
#
# 为什么需要这个脚本：PHP 进程管理有几个坑（PID 文件、端口占用、
# 后台进程收不到信号），用 bash 包一层比在 PHP 里 fork 更可靠，
# 尤其在没有 proc_open 的环境（安卓/Termux/受限容器）上。

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

# ---------- 找 PHP ----------
# 优先级：$HYPER_PHP > 项目自带 wrapper > PATH 里的 php
#
# 注意：不能只判 -x。安卓 /storage 是 FUSE 挂载，文件可能带 0644 或根本没有
# 可执行位（甚至 chmod +x 无效），但用 `bash <file>` 依然能跑。
# 所以这里判「存在且可读」，能不能执行交给 php_run 决定。
find_php() {
  if [[ -n "${HYPER_PHP:-}" ]]; then
    echo "$HYPER_PHP"
    return
  fi
  local candidates=(
    "$ROOT/../.phpext/php"
    "$ROOT/.phpext/php"
    "$ROOT/vendor/bin/php"
  )
  local candidate
  for candidate in "${candidates[@]}"; do
    if [[ -f "$candidate" && -r "$candidate" ]]; then
      echo "$candidate"
      return
    fi
  done
  if command -v php >/dev/null 2>&1; then
    command -v php
    return
  fi
  echo ""
}

PHP_BIN="$(find_php)"
if [[ -z "$PHP_BIN" ]]; then
  echo "错误：找不到 PHP。请安装 PHP 8.1+，或设置 HYPER_PHP=/path/to/php" >&2
  exit 1
fi

# wrapper 走 bash，真二进制直接执行
#
# PHP 二进制在受限挂载上常常没有可执行位，直接 exec 会 Permission denied，
# 而 `bash <wrapper>` 是可行的（wrapper 本身是 #!/bin/bash 脚本）。
php_run() {
  if [[ "$PHP_BIN" == *"phpext/php" || "$PHP_BIN" == *".phpext/php" ]]; then
    bash "$PHP_BIN" "$@"
    return
  fi
  if head -c 2 "$PHP_BIN" 2>/dev/null | grep -q '#!'; then
    bash "$PHP_BIN" "$@"
    return
  fi
  if [[ -x "$PHP_BIN" ]]; then
    "$PHP_BIN" "$@"
    return
  fi
  echo "错误：$PHP_BIN 不可执行，请设置 HYPER_PHP=/path/to/php" >&2
  return 1
}

# ---------- 参数解析 ----------
ACTION="${1:-start}"
[[ $# -gt 0 ]] && shift

ARCH=""
PORT=""
HOST=""
FOREGROUND=0
for arg in "$@"; do
  case "$arg" in
    --arch=*)  ARCH="${arg#*=}" ;;
    --port=*)  PORT="${arg#*=}" ;;
    --host=*)  HOST="${arg#*=}" ;;
    --foreground|-f) FOREGROUND=1 ;;
    --help|-h) ACTION=help ;;
    *) echo "未知参数：$arg" >&2; exit 2 ;;
  esac
done

# ---------- 端口 / PID ----------
config_value() {
  php_run bin/hyper config "$1" 2>/dev/null | tr -d "'\"" | tr -d ' '
}

[[ -z "$PORT" ]] && PORT="$(config_value http.port)"
[[ -z "$PORT" || "$PORT" == "NULL" ]] && PORT=8080
[[ -z "$HOST" ]] && HOST="$(config_value http.host)"
[[ -z "$HOST" || "$HOST" == "NULL" ]] && HOST=127.0.0.1

PID_DIR="$ROOT/runtime"
PID_FILE="$PID_DIR/hyper-$PORT.pid"
LOG_FILE="$PID_DIR/hyper-$PORT.log"
mkdir -p "$PID_DIR"

process_alive() {
  local pid="$1"
  [[ -n "$pid" ]] || return 1
  if [[ -d /proc ]]; then
    [[ -d "/proc/$pid" ]]
  else
    kill -0 "$pid" 2>/dev/null
  fi
}

is_running() {
  [[ -f "$PID_FILE" ]] || return 1
  local pid
  pid="$(cat "$PID_FILE" 2>/dev/null)"
  process_alive "$pid"
}

current_pid() { cat "$PID_FILE" 2>/dev/null; }

port_in_use() {
  local port="$1"
  if command -v ss >/dev/null 2>&1; then
    ss -ltn 2>/dev/null | awk '{print $4}' | grep -qE ":$port\$" && return 0
  fi
  if command -v netstat >/dev/null 2>&1; then
    netstat -ltn 2>/dev/null | awk '{print $4}' | grep -qE ":$port\$" && return 0
  fi
  # 兜底：真的发一个 HTTP 请求。
  # 不能只测 /dev/tcp —— 安卓的 bash 常常不支持它，会恒判「未占用」。
  if command -v curl >/dev/null 2>&1; then
    curl -s -m 1 -o /dev/null "http://127.0.0.1:$port/" 2>/dev/null && return 0
  fi
  return 1
}

# ---------- 命令 ----------
cmd_start() {
  if is_running; then
    echo "已在运行（PID $(current_pid)）。如需重启：$0 restart"
    return 0
  fi

  if port_in_use "$PORT"; then
    echo "错误：端口 $PORT 已被占用。换端口：$0 start --port=8081" >&2
    return 1
  fi

  local args=(bin/hyper serve --port="$PORT" --host="$HOST")
  [[ -n "$ARCH" ]] && args+=(--arch="$ARCH")

  if [[ $FOREGROUND -eq 1 ]]; then
    echo "前台运行（Ctrl+C 停止）"
    exec php_run "${args[@]}"
  fi

  # 后台启动：nohup 分离，日志落 runtime/
  #
  # 必须走 php_run（而不是直接 exec $PHP_BIN）：受挂载限制的 wrapper
  # 没有可执行位，直接跑会 Permission denied，但 `bash <wrapper>` 可以。
  local php_cmd
  php_cmd="$(declare -f php_run); PHP_BIN=$(printf '%q' "$PHP_BIN"); php_run $(printf '%q ' "${args[@]}")"
  nohup bash -c "$php_cmd" >"$LOG_FILE" 2>&1 &

  # 等服务进程自己写 pid 文件（它比 shell 的 $! 更准：
  # 沙箱/受限环境下 $! 可能是外层 bash 的 PID）
  local pid=""
  local i
  for i in $(seq 1 120); do
    if [[ -s "$PID_FILE" ]]; then
      pid="$(cat "$PID_FILE" 2>/dev/null)"
      break
    fi
    sleep 0.2
  done

  if [[ -z "$pid" ]]; then
    echo "启动失败：服务进程未写入 PID 文件（$PID_FILE）" >&2
    tail -20 "$LOG_FILE" >&2
    return 1
  fi

  # 等端口就绪（最多 15 秒）
  local i
  for i in $(seq 1 15); do
    if port_in_use "$PORT"; then
      echo "已启动（PID $pid，端口 $PORT）"
      echo "  面板  http://$HOST:$PORT/webui/"
      echo "  日志  $LOG_FILE"
      return 0
    fi
    if ! kill -0 "$pid" 2>/dev/null; then
      echo "启动失败。日志末尾：" >&2
      tail -20 "$LOG_FILE" >&2
      rm -f "$PID_FILE"
      return 1
    fi
    sleep 1
  done

  echo "警告：进程已起（PID $pid）但端口 $PORT 在 15 秒内未就绪" >&2
  echo "请检查日志：$LOG_FILE" >&2
  return 1
}

cmd_stop() {
  # 以实际存在的 pid 文件为准，而不是配置里的端口
  # （start 可能用 --port 覆盖了配置值，此时 PID_FILE 指向错端口）
  local stopped=0
  local f pid pidfile
  for f in "$PID_DIR"/hyper-*.pid; do
    [[ -f "$f" ]] || continue
    pid="$(cat "$f" 2>/dev/null)"
    if [[ -z "$pid" ]] || ! process_alive "$pid"; then
      rm -f "$f"
      continue
    fi

    pidfile="$f"
    stopped=1
    echo "停止中（PID $pid）..."
    kill -TERM "$pid" 2>/dev/null

    local i
    for i in $(seq 1 15); do
      if ! process_alive "$pid"; then
        rm -f "$pidfile"
        echo "已停止"
        break
      fi
      sleep 1
    done

    if process_alive "$pid"; then
      echo "优雅停止超时，强制结束" >&2
      kill -KILL "$pid" 2>/dev/null
      sleep 1
      rm -f "$pidfile"
      echo "已强制停止"
    fi

    # 清理对应的子进程 pid 文件（正常情况下主进程已删掉它）
    local childfile="${f%.pid}.child"
    if [[ -f "$childfile" ]]; then
      local child
      child="$(cat "$childfile" 2>/dev/null)"
      [[ -n "$child" ]] && kill -KILL "$child" 2>/dev/null
      rm -f "$childfile"
    fi
  done

  # 兜底：任何还挂着 --arch/--port 的服务进程
  if [[ $stopped -eq 0 ]]; then
    echo "未在运行"
  fi
  rm -f "$PID_FILE"
}

cmd_restart() {
  cmd_stop
  sleep 1
  cmd_start
}

cmd_status() {
  echo
  # 以实际存在的 pid 文件为准（start 时可能用 --port 覆盖了配置值）
  local found_pid="" found_port=""
  local f
  for f in "$PID_DIR"/hyper-*.pid; do
    [[ -f "$f" ]] || continue
    local candidate_port candidate_pid
    candidate_port="$(basename "$f" .pid)"
    candidate_port="${candidate_port#hyper-}"
    candidate_pid="$(cat "$f" 2>/dev/null)"
    if [[ -n "$candidate_pid" ]] && process_alive "$candidate_pid"; then
      found_pid="$candidate_pid"
      found_port="$candidate_port"
      break
    fi
  done

  if [[ -n "$found_pid" ]]; then
    echo "进程    ✓ 运行中（PID $found_pid）"
    echo "端口    $found_port"
    echo "地址    http://$HOST:$found_port/webui/"
    echo "日志    $PID_DIR/hyper-$found_port.log"
  else
    echo "进程    ✗ 未运行"
  fi
  echo
  php_run bin/hyper status 2>/dev/null
}

cmd_logs() {
  if [[ ! -f "$LOG_FILE" ]]; then
    echo "日志文件不存在（服务可能未启动过）：$LOG_FILE" >&2
    return 1
  fi
  tail -n 100 -f "$LOG_FILE"
}

cmd_doctor() {
  php_run bin/hyper doctor
}

cmd_help() {
  cat <<'HELP'
Hyper Bot 启动脚本（v2）

用法：bash bin/hyper.sh <命令> [选项]

命令：
  start            启动服务（默认后台，--foreground 则前台）
  stop             停止服务
  restart          重启服务
  status           显示进程与运行架构
  logs             跟踪日志（Ctrl+C 退出）
  doctor           环境体检
  help             显示本帮助

选项：
  --arch=swoole    Swoole 常驻（需要 ext-swoole）
  --arch=php       PHP 内置服务器（无需 Swoole）
  --arch=webui     仅 WebUI 管理面板，不处理机器人事件
  --port=8080      端口（默认读 Config.ini [http] port）
  --host=127.0.0.1 监听地址
  --foreground     前台运行（便于直接看日志）

示例：
  bash bin/hyper.sh start --arch=php --port=8080
  bash bin/hyper.sh restart --arch=swoole
  HYPER_PHP=/usr/bin/php bash bin/hyper.sh start

说明：
  架构也可以在 WebUI「运行架构」页面切换（写入 Config.ini，重启生效）。
  命令行 --arch 优先级最高，其次 HYPER_ARCH 环境变量，最后 Config.ini。
HELP
}

case "$ACTION" in
  start)         cmd_start ;;
  stop)          cmd_stop ;;
  restart)       cmd_restart ;;
  status)        cmd_status ;;
  logs|log)      cmd_logs ;;
  doctor|check)  cmd_doctor ;;
  help|-h|--help) cmd_help ;;
  *)
    echo "未知命令：$ACTION" >&2
    echo "用 $0 help 查看用法" >&2
    exit 2
    ;;
esac
