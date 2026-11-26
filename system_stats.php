<?php
/* =========================================================
   system_stats.php
   Cross-platform server stats (Linux hosting + Windows/XAMPP)
   Include from admin_monitor.php:  require_once 'system_stats.php';
========================================================= */

function stats_exec_allowed()
{
    if (!function_exists('shell_exec')) {
        return false;
    }
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return !in_array('shell_exec', $disabled, true);
}

function stats_read($path)
{
    $data = @file_get_contents($path);
    return $data === false ? null : $data;
}

function stats_clamp($v)
{
    return max(0, min(100, (int) round($v)));
}

/* ---------- CPU ---------- */

function stats_cpu_times()
{
    $stat = stats_read('/proc/stat');
    if (!$stat || !preg_match('/^cpu\s+(.+)$/m', $stat, $m)) {
        return null;
    }
    $p = array_map('floatval', preg_split('/\s+/', trim($m[1])));
    $idle = $p[3] + ($p[4] ?? 0);          // idle + iowait
    $total = array_sum(array_slice($p, 0, 8));
    return [$idle, $total];
}

function stats_cpu_cores()
{
    $info = stats_read('/proc/cpuinfo');
    if ($info) {
        $n = preg_match_all('/^processor\s*:/m', $info);
        if ($n > 0) {
            return $n;
        }
    }
    return 1;
}

function getCPUUsage()
{
    if (PHP_OS_FAMILY === 'Linux') {
        $a = stats_cpu_times();
        if ($a) {
            usleep(250000);
            $b = stats_cpu_times();
            if ($b && ($b[1] - $a[1]) > 0) {
                $idleD = $b[0] - $a[0];
                $totalD = $b[1] - $a[1];
                return stats_clamp((1 - $idleD / $totalD) * 100);
            }
        }
        // Fallback: load average relative to core count
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if ($load) {
                return stats_clamp(($load[0] / stats_cpu_cores()) * 100);
            }
        }
        return 0;
    }

    if (PHP_OS_FAMILY === 'Windows' && stats_exec_allowed()) {
        $out = @shell_exec('wmic cpu get loadpercentage /value 2>NUL');
        if ($out && preg_match('/LoadPercentage=(\d+)/i', $out, $m)) {
            return stats_clamp($m[1]);
        }
        // wmic is removed on newer Windows 11 builds
        $out = @shell_exec('powershell -NoProfile -Command "(Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average).Average" 2>NUL');
        if ($out !== null && is_numeric(trim($out))) {
            return stats_clamp(trim($out));
        }
    }

    return 0;
}

/* ---------- MEMORY ---------- */

function getMemoryUsage()
{
    if (PHP_OS_FAMILY === 'Linux') {
        $mem = stats_read('/proc/meminfo');
        if ($mem) {
            preg_match('/^MemTotal:\s+(\d+)/m', $mem, $t);
            preg_match('/^MemAvailable:\s+(\d+)/m', $mem, $a);
            if (empty($a[1])) {
                preg_match('/^MemFree:\s+(\d+)/m', $mem, $f);
                preg_match('/^Buffers:\s+(\d+)/m', $mem, $b);
                preg_match('/^Cached:\s+(\d+)/m', $mem, $c);
                $avail = ($f[1] ?? 0) + ($b[1] ?? 0) + ($c[1] ?? 0);
            } else {
                $avail = $a[1];
            }
            if (!empty($t[1]) && $t[1] > 0) {
                return stats_clamp((($t[1] - $avail) / $t[1]) * 100);
            }
        }
        return 0;
    }

    if (PHP_OS_FAMILY === 'Windows' && stats_exec_allowed()) {
        $out = @shell_exec('wmic OS get TotalVisibleMemorySize,FreePhysicalMemory /value 2>NUL');
        if ($out
            && preg_match('/TotalVisibleMemorySize=(\d+)/i', $out, $t)
            && preg_match('/FreePhysicalMemory=(\d+)/i', $out, $f)
            && $t[1] > 0) {
            return stats_clamp((($t[1] - $f[1]) / $t[1]) * 100);
        }
        $out = @shell_exec('powershell -NoProfile -Command "$o=Get-CimInstance Win32_OperatingSystem; [math]::Round((($o.TotalVisibleMemorySize-$o.FreePhysicalMemory)/$o.TotalVisibleMemorySize)*100)" 2>NUL');
        if ($out !== null && is_numeric(trim($out))) {
            return stats_clamp(trim($out));
        }
    }

    return 0;
}

/* ---------- DISK ---------- */

function getDiskUsage()
{
    // Use the drive/partition the site actually lives on
    $path = __DIR__;
    $total = @disk_total_space($path);
    $free = @disk_free_space($path);

    if ($total === false || $free === false || $total <= 0) {
        return 0;
    }

    return stats_clamp((($total - $free) / $total) * 100);
}

/* ---------- NETWORK ---------- */

function stats_net_bytes()
{
    $dev = stats_read('/proc/net/dev');
    if (!$dev) {
        return null;
    }
    $bytes = 0;
    $iface = null;
    foreach (explode("\n", $dev) as $line) {
        if (strpos($line, ':') === false) {
            continue;
        }
        [$name, $rest] = explode(':', $line, 2);
        $name = trim($name);
        if ($name === 'lo') {
            continue;
        }
        $f = preg_split('/\s+/', trim($rest));
        $bytes += (float) $f[0] + (float) ($f[8] ?? 0); // rx + tx
        if ($iface === null) {
            $iface = $name;
        }
    }
    return [$bytes, $iface];
}

function stats_link_speed_bps($iface)
{
    // /sys/class/net/<if>/speed is in Mbit/s (often missing/-1 on VPS)
    if ($iface) {
        $s = stats_read('/sys/class/net/' . $iface . '/speed');
        if ($s !== null && (int) $s > 0) {
            return (int) $s * 1000000 / 8;
        }
    }
    return 1000 * 1000000 / 8; // assume 1 Gbps
}

function getNetworkUsage()
{
    if (PHP_OS_FAMILY === 'Linux') {
        $a = stats_net_bytes();
        if ($a) {
            usleep(250000);
            $b = stats_net_bytes();
            if ($b) {
                $bytesPerSec = max(0, $b[0] - $a[0]) / 0.25;
                return stats_clamp(($bytesPerSec / stats_link_speed_bps($a[1])) * 100);
            }
        }
        return 0;
    }

    if (PHP_OS_FAMILY === 'Windows' && stats_exec_allowed()) {
        $out = @shell_exec('typeperf "\Network Interface(*)\Bytes Total/sec" -sc 1 2>NUL');
        if ($out) {
            $lines = preg_split("/\r\n|\n|\r/", trim($out));
            if (count($lines) >= 2) {
                preg_match_all('/"([^"]+)"/', $lines[count($lines) - 1], $m);
                $sum = 0;
                foreach (array_slice($m[1] ?? [], 1) as $v) {
                    $v = str_replace(',', '.', $v);
                    if (is_numeric($v)) {
                        $sum += (float) $v;
                    }
                }
                return stats_clamp(($sum / 12500000) * 100);
            }
        }
    }

    return 0;
}