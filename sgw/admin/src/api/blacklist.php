<?php
require_once __DIR__ . '/_auth.php';

$method = $_SERVER['REQUEST_METHOD'];

// GET — 列出黑名单
// ?no_idc=1     跳过IDC概要（日志页仅需IP集合时使用）
// ?cloud_cidrs=1 仅返回云服务商CIDR列表（供前端云IP检测使用）
if ($method === 'GET') {
    if (!empty($_GET['cloud_cidrs'])) {
        json_out(['ok' => true, 'cidrs' => read_cloud_cidrs()]);
    }
    $idc = empty($_GET['no_idc']) ? read_idc_summary() : [];
    json_out([
        'ok' => true,
        'entries' => read_blacklist(),
        'idc_summary' => $idc,
        'idc_block_enabled' => bl_idc_block_enabled(),
    ]);
}

// POST — 添加并立即生效（单个或批量导入）
if ($method === 'POST') {
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];

    // 内置 IDC 封禁开关
    if (array_key_exists('idc_block_enabled', $body)) {
        $enabled = filter_var($body['idc_block_enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) json_err('IDC 封禁开关参数无效');

        $settings = bl_read_settings();
        $settings['idc_block_enabled'] = $enabled;
        if (!bl_write_settings($settings)) json_err('保存设置失败，请检查文件权限');
        if (!bl_rewrite_protect_conf($enabled)) json_err('更新 nginx 配置失败，请检查文件权限');
        $reload = nginx_reload();
        json_out(['ok' => true, 'idc_block_enabled' => $enabled, 'nginx_reloaded' => $reload]);
    }

    // 批量导入（来自文件导入）
    if (!empty($body['import_ips']) && is_array($body['import_ips'])) {
        $entries    = read_blacklist();
        $existingSet = [];
        foreach ($entries as $e) $existingSet[$e['ip']] = true;
        $added = 0; $skipped = 0; $invalid = 0;
        foreach ($body['import_ips'] as $rawIp) {
            $ip = trim($rawIp);
            if (!$ip) continue;
            // 支持 IP 和 CIDR
            if (!preg_match('/^\d{1,3}(\.\d{1,3}){3}(\/\d+)?$/', $ip)) { $invalid++; continue; }
            if (isset($existingSet[$ip])) { $skipped++; continue; }
            $entries[] = ['ip' => $ip, 'comment' => '从文件导入', 'added_at' => date('Y-m-d H:i')];
            $existingSet[$ip] = true;
            $added++;
        }
        if ($added > 0) {
            if (!write_blacklist($entries)) json_err('写入黑名单文件失败，请检查文件权限');
            $reload = nginx_reload();
        } else {
            $reload = false;
        }
        json_out(['ok' => true, 'added' => $added, 'skipped' => $skipped, 'invalid' => $invalid, 'nginx_reloaded' => $reload]);
    }

    // 单个添加
    $ip      = trim($body['ip'] ?? '');
    $comment = safe_comment($body['comment'] ?? '');

    if (!$ip || !preg_match('/^\d{1,3}(\.\d{1,3}){3}(\/\d+)?$/', $ip)) {
        json_err('IP 格式不合法（仅支持 IPv4）');
    }

    $entries = read_blacklist();
    foreach ($entries as $e) {
        if ($e['ip'] === $ip) json_err('该IP已在黑名单中');
    }

    $entries[] = [
        'ip'       => $ip,
        'comment'  => $comment,
        'added_at' => date('Y-m-d H:i'),
    ];

    if (!write_blacklist($entries)) json_err('写入黑名单文件失败，请检查文件权限');
    $reload = nginx_reload();

    json_out(['ok' => true, 'nginx_reloaded' => $reload]);
}

// PATCH — 更新备注（仅更新 JSON，不 reload nginx）
if ($method === 'PATCH') {
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $ip      = trim($body['ip'] ?? '');
    $comment = safe_comment($body['comment'] ?? '');

    if (!$ip) json_err('缺少 ip 参数');

    $entries = read_blacklist();
    $found   = false;
    foreach ($entries as &$e) {
        if ($e['ip'] === $ip) { $e['comment'] = $comment; $found = true; break; }
    }
    unset($e);

    if (!$found) json_err('未找到该IP');
    file_put_contents(BLACKLIST_JSON, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    json_out(['ok' => true]);
}

// DELETE — 移除并立即生效（支持单个 ip 或批量 ips 数组）
if ($method === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    // 批量
    if (!empty($body['ips']) && is_array($body['ips'])) {
        $toRemove = array_map('trim', $body['ips']);
        $entries  = array_values(array_filter(read_blacklist(), fn($e) => !in_array($e['ip'], $toRemove)));
        if (!write_blacklist($entries)) json_err('写入黑名单文件失败，请检查文件权限');
        $reload = nginx_reload();
        json_out(['ok' => true, 'nginx_reloaded' => $reload]);
    }

    // 单个
    $ip = trim($body['ip'] ?? '');
    if (!$ip) json_err('缺少 ip 参数');

    $entries = array_filter(read_blacklist(), fn($e) => $e['ip'] !== $ip);
    if (!write_blacklist(array_values($entries))) json_err('写入黑名单文件失败，请检查文件权限');
    $reload = nginx_reload();

    json_out(['ok' => true, 'nginx_reloaded' => $reload]);
}

json_err('不支持的请求方式', 405);

// ── 读写黑名单 ────────────────────────────────────────────────

function read_blacklist(): array {
    if (!file_exists(BLACKLIST_JSON)) return [];
    $data = json_decode(file_get_contents(BLACKLIST_JSON), true);
    return is_array($data) ? $data : [];
}

function write_blacklist(array $entries): bool {
    $r1 = file_put_contents(BLACKLIST_JSON, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

    $lines = ["# 黑名单 - 由 admin 自动生成 | " . date('Y-m-d H:i:s')];
    foreach ($entries as $e) {
        $ip = trim((string)($e['ip'] ?? ''));
        // 防御性校验 IP/CIDR，避免被篡改的 JSON 通过 IP 字段注入 nginx 指令
        if (!preg_match('/^\d{1,3}(\.\d{1,3}){3}(\/\d+)?$/', $ip)) continue;
        $at      = safe_comment($e['added_at'] ?? '');
        $cmtText = safe_comment($e['comment'] ?? '');
        $cmt = $cmtText !== '' ? " # {$cmtText} ({$at})" : " # {$at}";
        $lines[] = "deny {$ip};{$cmt}";
    }
    $r2 = file_put_contents(BLACKLIST_CONF, implode("\n", $lines) . "\n", LOCK_EX);

    return $r1 !== false && $r2 !== false;
}

// ── 内置 IDC 封禁开关 ────────────────────────────────────────

function bl_read_settings(): array {
    if (!file_exists(SETTINGS_JSON)) return [];
    $raw = @file_get_contents(SETTINGS_JSON);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function bl_write_settings(array $settings): bool {
    return file_put_contents(SETTINGS_JSON, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

function bl_idc_block_enabled(): bool {
    $settings = bl_read_settings();
    return ($settings['idc_block_enabled'] ?? true) !== false;
}

function bl_rewrite_protect_conf(bool $idcBlockEnabled): bool {
    $parsed = bl_parse_protect_conf();
    $settings = bl_read_settings();

    $subscribePath = safe_conf_value($parsed['subscribe_path'] ?? $settings['subscribe_path'] ?? '/api/v1/client/subscribe');
    $backend = safe_conf_value($parsed['upstream_url'] ?? $settings['upstream_url'] ?? (getenv('V2B_BACKEND') ?: ''));
    $host = safe_conf_value($parsed['upstream_host'] ?? $settings['upstream_host'] ?? (parse_url($backend, PHP_URL_HOST) ?: getenv('V2B_HOST') ?: ''));

    if ($backend === '' || $host === '') return false;

    $idcBlockRules = $idcBlockEnabled
        ? '    if ($is_cloud_ip = 1)       { set $block_reason "cloud"; }'
        : '    # 内置 IDC 封禁已关闭';
    $idcBlockReturns = $idcBlockEnabled
        ? '    if ($block_reason = "cloud") { return 403 "Forbidden: Cloud IP"; }'
        : '    # 内置 IDC 封禁已关闭';

    $conf = <<<NGINX
location ^~ $subscribePath {

    if (\$whitelist_ip = 1) { set \$block_reason ""; }

$idcBlockRules
    if (\$bad_subscribe_ua = 1)  { set \$block_reason "ua"; }
    if (\$is_custom_bad_ua = 1)  { set \$block_reason "ua"; }
    if (\$is_ua_whitelisted = 1) { set \$block_reason ""; }

    if (\$whitelist_ip = 1) { set \$block_reason ""; }

$idcBlockReturns
    if (\$block_reason = "ua")    { return 403 "Forbidden: Invalid Client"; }

    limit_req zone=subscribe_limit burst=5 nodelay;
    limit_req_status 429;

    set \$upstream_backend   $backend;
    proxy_pass              \$upstream_backend;
    proxy_set_header    Host              $host;
    proxy_set_header    X-Real-IP         \$remote_addr;
    proxy_set_header    X-Forwarded-For   \$proxy_add_x_forwarded_for;
    proxy_set_header    REMOTE-HOST       \$remote_addr;
    proxy_ssl_server_name on;
    proxy_ssl_name        $host;
    proxy_set_header    Upgrade           \$http_upgrade;
    proxy_set_header    Connection        \$connection_upgrade;
    proxy_http_version  1.1;
    proxy_connect_timeout 10s;
    proxy_send_timeout    15s;
    proxy_read_timeout    60s;

    add_header Cache-Control no-store;
    add_header X-Subscribe-Filter "active";
}
NGINX;
    return file_put_contents(PROTECT_CONF, $conf, LOCK_EX) !== false;
}

function bl_parse_protect_conf(): array {
    if (!file_exists(PROTECT_CONF)) return [];
    $content = @file_get_contents(PROTECT_CONF);
    if ($content === false) return [];
    $result = [];
    if (preg_match('/^location\s+\^~\s+(\S+)/m', $content, $m)) {
        $result['subscribe_path'] = $m[1];
    }
    if (preg_match('/set\s+\$upstream_backend\s+(\S+);/m', $content, $m)) {
        $result['upstream_url'] = rtrim($m[1], ';');
    } elseif (preg_match('/proxy_pass\s+(\S+);/m', $content, $m)) {
        $val = rtrim($m[1], ';');
        if (!str_starts_with($val, '$')) $result['upstream_url'] = $val;
    }
    if (preg_match('/proxy_set_header\s+Host\s+(\S+);/m', $content, $m)) {
        $result['upstream_host'] = rtrim($m[1], ';');
    }
    return $result;
}

// ── 读取 cloud_geo.conf 返回所有CIDR列表（供前端IP范围匹配）──────

function read_cloud_cidrs(): array {
    if (!file_exists(CLOUD_GEO_CONF)) return [];
    $cidrs = [];
    foreach (file(CLOUD_GEO_CONF, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if (preg_match('/^(\d[\d\.\/]+) 1;$/', $line, $m)) {
            $cidrs[] = $m[1];
        }
    }
    return $cidrs;
}

// ── 读取 cloud_geo.conf 返回各IDC汇总 ──────────────────────────

function read_idc_summary(): array {
    if (!file_exists(CLOUD_GEO_CONF)) return [];

    $summary = [];
    $current = null;
    $count   = 0;

    foreach (file(CLOUD_GEO_CONF, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if (preg_match('/^# === (.+) ===$/', $line, $m)) {
            if ($current !== null && $count > 0) {
                $summary[] = ['name' => $current, 'count' => $count];
            }
            $current = $m[1];
            $count   = 0;
        } elseif ($current !== null && preg_match('/^\d[\d\.\/]+ 1;$/', $line)) {
            $count++;
        }
    }
    if ($current !== null && $count > 0) {
        $summary[] = ['name' => $current, 'count' => $count];
    }
    return $summary;
}
