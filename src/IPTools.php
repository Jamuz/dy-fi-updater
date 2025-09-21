<?php

namespace Metabolix\DyFiUpdater;

class IPTools {
    public static function makeCanonical($ip) {
        return inet_ntop(inet_pton($ip));
    }
    public static function getIPv4FromRemote() {
        $ip = file_get_contents("http://ip4only.me/api/");
        return self::makeCanonical(explode(",", $ip)[1] ?? null);
    }
    public static function getIPv6FromRemote() {
        $ip = file_get_contents("http://ip6only.me/api/");
        return self::makeCanonical(explode(",", $ip)[1] ?? null);
    }
    public static function iproute2() {
        $s = @shell_exec("ip -o addr 2>/dev/null");
        preg_match_all('#inet (?!127\\.|10\\.|192\\.168\\.|172\\.(?:1[6789]|2[0-9]|3[01])\\.)([0-9.]+)(/[0-9]+)? .*scope global#', $s, $m);
        $ipv4 = array_map(self::makeCanonical(...), $m[1])[0] ?? null;
        preg_match_all('#inet6 (?!fe[89a-f]|f[cd]|ff[0:]|[0:]+1[ /])([0-9a-f:]+)(/[0-9]+)? scope global#', $s, $m);
        $ipv6 = array_map(self::makeCanonical(...), $m[1])[0] ?? null;
        return [$ipv4, $ipv6];
    }
    public static function getBoth() {
        list($ipv4, $ipv6) = self::iproute2();
        return [$ipv4 ?: self::getIPv4FromRemote(), $ipv6 ?: self::getIPv6FromRemote ()];
    }
}
