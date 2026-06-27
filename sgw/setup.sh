#!/bin/bash
# =============================================================
# setup.sh — 首次部署脚本（支持断点续跑）
# 自动生成随机密钥 → 写入 .env → 启动容器 → 打印访问信息
# =============================================================

set -euo pipefail

cd "$(dirname "$0")"

# ── 颜色 ──────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'

STATE_FILE=".setup_state"

echo -e "${BOLD}SubSieve — 部署向导${RESET}"
echo "────────────────────────────────────────"

# ── 加载上次保存的输入 ─────────────────────────────────────────
_S_V2B_HOST=""; _S_SUBSCRIBE_PATH=""; _S_GATEWAY_PORT=""
if [[ -f "$STATE_FILE" ]]; then
    # shellcheck source=/dev/null
    source "$STATE_FILE" 2>/dev/null || true
fi

# ── 辅助：带默认值的 read ──────────────────────────────────────
ask() {
    # ask "提示文字" "默认值" VARNAME
    local prompt="$1" default="$2" var="$3" val
    if [[ -n "$default" ]]; then
        read -rp "${prompt} [${default}]: " val
        printf -v "$var" '%s' "${val:-$default}"
    else
        read -rp "${prompt}: " val
        printf -v "$var" '%s' "$val"
    fi
}

# ── 随机生成函数 ───────────────────────────────────────────────
gen_random() { head -c 48 /dev/urandom | base64 | tr -dc 'a-zA-Z0-9' | head -c "$1"; }

# ── 检查 .env → 决定是否重新生成凭证 ─────────────────────────
REGEN_CREDS=true
ADMIN_USER="admin"; ADMIN_PASS=""; ADMIN_SECRET_PATH=""
if [[ -f .env ]]; then
    echo -e "${YELLOW}⚠  检测到已有 .env 文件${RESET}"
    read -rp "是否重新生成账号密码和访问路径？(y/N): " _CONFIRM
    if [[ "${_CONFIRM,,}" != "y" ]]; then
        REGEN_CREDS=false
        # 从现有 .env 逐行解析凭证（避免 source 副作用）
        while IFS='=' read -r _key _val; do
            [[ "$_key" =~ ^[[:space:]]*# ]] && continue
            [[ -z "${_key// /}" ]] && continue
            _key="${_key// /}"; _val="${_val// /}"
            case "$_key" in
                ADMIN_USER)        ADMIN_USER="$_val" ;;
                ADMIN_PASS)        ADMIN_PASS="$_val" ;;
                ADMIN_SECRET_PATH) ADMIN_SECRET_PATH="$_val" ;;
            esac
        done < .env
        # 若解析失败则重新生成
        if [[ -z "$ADMIN_PASS" || -z "$ADMIN_SECRET_PATH" ]]; then
            echo -e "${YELLOW}⚠  无法从 .env 读取凭证，将重新生成${RESET}"
            REGEN_CREDS=true
        fi
    fi
fi

# ── 收集机场信息 ───────────────────────────────────────────────
echo ""
echo -e "${CYAN}请填写机场信息${RESET}"
ask "机场地址（如 panel.yourdomain.com，不含 https://）" "$_S_V2B_HOST" V2B_HOST
V2B_HOST="${V2B_HOST#https://}"
V2B_BACKEND="https://${V2B_HOST}"

ask "订阅路径（默认 /api/v1/client/subscribe）" "${_S_SUBSCRIBE_PATH:-/api/v1/client/subscribe}" SUBSCRIBE_PATH

ask "用来接收客户订阅请求的端口（默认 443）" "${_S_GATEWAY_PORT:-443}" GATEWAY_PORT

# ── 持久化本次输入（下次重跑时作为默认值）────────────────────
cat > "$STATE_FILE" <<EOF
_S_V2B_HOST="${V2B_HOST}"
_S_SUBSCRIBE_PATH="${SUBSCRIBE_PATH}"
_S_GATEWAY_PORT="${GATEWAY_PORT}"
EOF

echo ""
echo -e "${CYAN}内部服务将以 HTTP 启动，无需申请或放置 SSL 证书。${RESET}"
echo "如需公网 HTTPS 或域名访问，请在 Docker 外部自行配置反代或隧道。"

# ── 检测并安装 Docker ──────────────────────────────────────────
if ! command -v docker &>/dev/null; then
    echo ""
    echo -e "${YELLOW}未检测到 Docker，正在自动安装…${RESET}"
    curl -fsSL https://get.docker.com | sh
    systemctl enable --now docker 2>/dev/null || true
    echo -e "${GREEN}✅ Docker 安装完成${RESET}"
    echo ""
elif ! docker info &>/dev/null 2>&1; then
    echo -e "${YELLOW}Docker 已安装但未运行，正在启动…${RESET}"
    systemctl start docker 2>/dev/null || true
fi

# ── 生成/保留凭证 ──────────────────────────────────────────────
GATEWAY_CONTAINER="subscribe-gateway"
if [[ "$REGEN_CREDS" == "true" ]]; then
    ADMIN_USER="admin"
    ADMIN_PASS="$(gen_random 16)"
    ADMIN_SECRET_PATH="$(gen_random 12)"
fi

# ── 写入 .env ─────────────────────────────────────────────────
cat > .env <<EOF
# 由 setup.sh 自动生成 | $(date '+%Y-%m-%d %H:%M:%S')
# 请妥善保管此文件，勿泄露

V2B_BACKEND=${V2B_BACKEND}
V2B_HOST=${V2B_HOST}
SUBSCRIBE_PATH=${SUBSCRIBE_PATH}
GATEWAY_PORT=${GATEWAY_PORT}

ADMIN_USER=${ADMIN_USER}
ADMIN_PASS=${ADMIN_PASS}
ADMIN_SECRET_PATH=${ADMIN_SECRET_PATH}
GATEWAY_CONTAINER=${GATEWAY_CONTAINER}
EOF

echo -e "${GREEN}✅ .env 已生成${RESET}"

# ── 启动容器 ──────────────────────────────────────────────────
echo ""
echo -e "${CYAN}正在构建并启动容器（首次约需 3-5 分钟）…${RESET}"
docker compose up -d --build

# ── 等待 gateway 初始化完成 ────────────────────────────────────
echo -e "${CYAN}等待 gateway 初始化（拉取云IP库，请稍候）…${RESET}"
for i in $(seq 1 60); do
    if docker logs subscribe-gateway 2>&1 | grep -q "启动 nginx\|daemon off\|start worker"; then
        break
    fi
    sleep 3
    echo -n "."
done
echo ""

# ── 打印访问信息 ──────────────────────────────────────────────
print_summary() {
    DISPLAY_HOST="127.0.0.1"

    echo ""
    echo -e "${BOLD}════════════════════════════════════════════${RESET}"
    echo -e "${GREEN}${BOLD}  ✅ 部署完成！以下是你的访问信息${RESET}"
    echo -e "${BOLD}════════════════════════════════════════════${RESET}"
    echo ""
    echo -e "  ${BOLD}管理后台${RESET}"
    echo -e "  内部地址：${CYAN}http://${DISPLAY_HOST}:64444/${ADMIN_SECRET_PATH}${RESET}"
    echo -e "  用户名：${YELLOW}${ADMIN_USER}${RESET}"
    echo -e "  密码：  ${YELLOW}${ADMIN_PASS}${RESET}"
    echo ""
    echo -e "  ${BOLD}订阅网关${RESET}"
    echo -e "  内部地址：${CYAN}http://${DISPLAY_HOST}:${GATEWAY_PORT}${RESET}"
    echo -e "  订阅路径：${CYAN}${SUBSCRIBE_PATH}${RESET}"
    echo -e "  代理到：  ${CYAN}${V2B_BACKEND}${RESET}"
    echo ""
    echo -e "  ${YELLOW}提示：服务仅绑定本机 127.0.0.1，公网 HTTPS / 域名 / 外层反代请自行配置。${RESET}"
    echo ""
    echo -e "  ${BOLD}以上信息已保存到 .env 和 DEPLOY_INFO.txt${RESET}"
    echo -e "${BOLD}════════════════════════════════════════════${RESET}"
    echo ""
}

print_summary

# ── 保存一份到本地文件 ─────────────────────────────────────────
DISPLAY_HOST="127.0.0.1"

cat > DEPLOY_INFO.txt <<EOF
SubSieve 部署信息
生成时间: $(date '+%Y-%m-%d %H:%M:%S')

管理后台
    内部地址: http://${DISPLAY_HOST}:64444/${ADMIN_SECRET_PATH}
  用户名: ${ADMIN_USER}
  密码:   ${ADMIN_PASS}

订阅网关
  端口:     ${GATEWAY_PORT}
    内部地址: http://${DISPLAY_HOST}:${GATEWAY_PORT}
  订阅路径: ${SUBSCRIBE_PATH}
  代理到:   ${V2B_BACKEND}

说明
    容器内部服务使用 HTTP，无需 SSL 证书。
    服务仅绑定宿主机 127.0.0.1。
    如需公网 HTTPS、域名或隧道访问，请在 Docker 外部自行配置。
EOF

echo -e "  ${GREEN}访问信息已同步保存到 ./DEPLOY_INFO.txt${RESET}"
echo ""
