<?php

namespace Metabolix\DyFiUpdater;

class Account {
    private $logger;
    private $dry_run;
    private $curl;
    private $email;
    private $password;
    private $state = [];

    private function parse($html) {
        $this->state = [];
        if (preg_match_all('#<.*td-ht-(un)?pointed.*hostid=(\\d+).*>#', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $state = [];
                $txt = preg_replace('/^|(&[^;]{1,6};|\\s|<[^<>]*>)+|$/', ' ', $row[0]);
                $state["hostid"] = (int) $row[2];
                $state["unpointed"] = (bool) @$row[1];
                $state["hostname"] = preg_match('# (\\S+) #', $txt, $m) ? $m[1] : null;
                $state["ipv4"] = preg_match('# (\\d+\\.\\d+\\.\\d+\\.\\d+) #', $txt, $m) ? IPTools::makeCanonical($m[1]) : null;
                $state["ipv6"] = preg_match('#IPv6: ([0-9a-f:]+) #', $txt, $m) ? IPTools::makeCanonical($m[1]) : null;
                $state["mx"] = preg_match('#MX: (\\S+) #', $txt, $m) ? $m[1] : null;
                $state["expires"] = preg_match('#released in: ([0-9dmh ]+) #', $txt, $m) ? self::timeToSeconds($m[1]) : 0;
                $state["email"] = $this->email;
                $this->state[$state["hostname"]] = $state;
            }
        }
        $this->logger->debug(json_encode($this->state, JSON_PRETTY_PRINT));
    }
    private static function curl() {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_USERAGENT, "PHP");
        curl_setopt($curl, CURLOPT_FAILONERROR, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_FAILONERROR, 1);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_ENCODING, "");
        curl_setopt($curl, CURLOPT_COOKIEFILE, "");
        return $curl;
    }
    private function login() {
        if ($this->curl) {
            return;
        }
        $this->logger->debug("Login for {$this->email}");
        $this->curl = self::curl();
        curl_setopt($this->curl, CURLOPT_POST, 1);
        curl_setopt($this->curl, CURLOPT_POSTFIELDS, http_build_query(["c" => "login", "submit" => "login", "lang" => "en", "email" => $this->email, "password" => $this->password]));
        curl_setopt($this->curl, CURLOPT_URL, "https://www.dy.fi/?");
        $this->parse(curl_exec($this->curl));
    }
    public function refresh() {
        $this->login();
        curl_setopt($this->curl, CURLOPT_POST, 0);
        curl_setopt($this->curl, CURLOPT_URL, "https://www.dy.fi/");
        $this->parse(curl_exec($this->curl));
    }
    private static function timeToSeconds($s) {
        $t = 0;
        if (preg_match_all('#([0-9]+)(d|h|m)#', $s, $m)) {
            foreach ($m[1] as $i => $j) {
                $t += $j * ["d" => 86400, "h" => 3600, "m" => 60][$m[2][$i]];
            }
        }
        return $t;
    }
    public function __construct($logger, $dry_run, $email, $password) {
        $this->logger = $logger;
        $this->dry_run = $dry_run;
        $this->email = $email;
        $this->password = $password;
    }
    public function getState() {
        $this->login();
        return $this->state;
    }
    private function check($hostname) {
        $this->login();
        if (empty($this->state[$hostname])) {
            throw new Exception("Missing hostname $hostname on account {$this->email}!");
        }
    }
    public function updateIPv4($hostname, $ipv4 = null) {
        $this->check($hostname);
        $this->logger->info("Updating $hostname ipv4 to $ipv4");
        if ($this->dry_run) {
            $this->state[$hostname]["ipv4"] = $ipv4;
            $this->state[$hostname]["expires"] = 7 * 86400;
            return null;
        }
        $curl = self::curl();
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Basic ".base64_encode($this->email.":".$this->password)]);
        curl_setopt($curl, CURLOPT_URL, "https://www.dy.fi/nic/update?hostname=".urlencode($hostname));
        $str = curl_exec($curl);
        if (preg_match('/^(nochg)|^good (\\d+\\.\\d+\\.\\d+\\.\\d+)/', $str, $m) && $ipv4 != $m[1]) {
            if ($m[1] == "nochg" || $m[2] == $ipv4) {
                $this->logger->info("Updated $hostname to $ipv4 (no change)");
                return null;
            }
            $ipv4 = $m[2];
            $this->logger->info("Updated $hostname to $ipv4");
            $this->state[$hostname]["ipv4"] = $ipv4;
            $this->state[$hostname]["expires"] = 7 * 86400;
            return $ipv4;
        }
        throw new Exception("Updating $hostname failed: $str");
    }
    private function postUpdate($hostname) {
        $state = $this->state[$hostname];
        curl_setopt($this->curl, CURLOPT_POST, 1);
        curl_setopt($this->curl, CURLOPT_POSTFIELDS, http_build_query([
            "c" => "hopt",
            "hostid" => $state["hostid"],
            "aaaa" => $state["ipv6"],
            "mx" => $state["mx"],
            "url" => "",
            "title" => "",
            "framed" => "",
            "submit" => "1"
        ]));
        curl_setopt($this->curl, CURLOPT_URL, "https://www.dy.fi/?");
        $str = curl_exec($this->curl);
        if (!preg_match('/updated successfully/', $str)) {
            $str = substr(strip_tags($str), 0, 100);
            throw new Exception("Updating $hostname failed: $str");
        }
        $this->parse($str);
    }
    public function updateIPv6($hostname, $ipv6) {
        $this->check($hostname);
        $this->state[$hostname]["ipv6"] = $ipv6;
        $this->logger->info("Updating $hostname ipv6 to $ipv6");
        if (!$this->dry_run) {
            $this->postUpdate($hostname);
        }
    }
    public function updateMX($hostname, $mx) {
        $this->check($hostname);
        $this->state[$hostname]["mx"] = $mx;
        $this->logger->info("Updating $hostname mx to $mx");
        if (!$this->dry_run) {
            $this->postUpdate($hostname);
        }
    }
}
