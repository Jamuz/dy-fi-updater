<?php

namespace Metabolix\DyFiUpdater;

class Updater {
    const EXPIRATION_MARGIN = 12347;
    private $accounts;

    public static function initStateFile(?string $state_file) {
        if (!$state_file) {
            throw new Exception("State file path is not defined!");
        }
        $dir = dirname($state_file);
        if (file_exists($state_file) ? !is_writable($state_file) : !((is_dir($dir) || mkdir($dir, 0755, true)) && touch($state_file))) {
            throw new Exception("State file $state_file is not writable!");
        }
        // Sanity check: time can't be less than file modification time.
        // This prevents problems on systems without persistent RTC.
        if (time() < filemtime($state_file)) {
            throw new Exception("System time is incorrect!");
        }
        return $state_file;
    }

    public static function parseTargets(array $conf) {
        foreach ($conf["accounts"] as $account) {
            $email = $account["email"] ?? null;
            $password = $account["password"] ?? null;
            if (!$email || !$password) {
                throw new Exception("Error: Account is missing email or password!");
            }
            foreach ($account["hosts"] as $host) {
                $hostname = $host["hostname"] ?? null;
                if (!$hostname) {
                    throw new Exception("Error: Host is missing hostname!");
                }
                $targets[$hostname] = [
                    "email" => $email,
                    "password" => $password,
                    "ipv4" => $host["ipv4"] ?? $account["ipv4"] ?? $conf["ipv4"] ?? true,
                    "ipv6" => $host["ipv6"] ?? $account["ipv6"] ?? $conf["ipv6"] ?? true,
                    "mx" => $host["mx"] ?? $account["mx"] ?? $conf["mx"] ?? null,
                ];
            }
        }
        return $targets;
    }

    public static function resolveIPs() {
        list($ipv4, $ipv6) = IPTools::getBoth();
        if (!$ipv4) {
            throw new Exception("IPv4 address is missing!");
        }
        return [$ipv4, $ipv6];
    }

    public static function loadState(string $state_file) {
        $old_state = (object) ["ipv4" => 0, "ipv4_in_dyfi" => null, "ipv6" => 0, "expires" => 0, "hosts" => []];
        if ($tmp = @json_decode(file_get_contents($state_file), true)) {
            foreach ($tmp as $a => $b) if (property_exists($old_state, $a)) {
                $old_state->$a = $b;
            }
        }
        return $old_state;
    }

    public function update(array $conf, Logger $logger) {
        $dry_run = $conf["dry-run"] ?? false;

        $state_file = self::initStateFile($conf["state"] ?? null);
        $old = self::loadState($state_file);
        $targets = self::parseTargets($conf);
        list($ipv4, $ipv6) = self::resolveIPs();

        // Create new state.
        $new = (object) ["ipv4" => $ipv4, "ipv4_in_dyfi" => null, "ipv6" => $ipv6, "expires" => $old->expires, "hosts" => []];

        // If some weird routing puts a different IPv4 address in dy.fi,
        // and if the locally resolved IPv4 address has not changed,
        // assume that the old IPv4 address in dy.fi is still applicable.
        // Apparently there was need for this code at some point,
        // but I don't remember why. Leaving it here just in case.
        if ($ipv4 == $old->ipv4 && $ipv4 != $old->ipv4_in_dyfi && $old->ipv4_in_dyfi) {
            $new->ipv4_in_dyfi = $old->ipv4_in_dyfi;
        }

        // Which hosts will be updated?
        $tmp = array_keys($targets);
        sort($tmp);
        $new->hosts = $tmp;

        // Update needed?
        if ($old->expires > time() && $old->ipv4 == $new->ipv4 && $old->ipv6 == $ipv6 && $old->hosts == $new->hosts) {
            $logger->debug("No update needed");
            return;
        }

        foreach ($targets as $key => $entry) {
            $targets[$key]["ipv4"] = $targets[$key]["ipv4"] ? $new->ipv4_in_dyfi ?? $new->ipv4 : null;
            $targets[$key]["ipv6"] = $targets[$key]["ipv6"] ? $new->ipv6 : null;
        }

        $accounts = [];
        foreach ($targets as $entry) {
            $accounts[$entry["email"]] = $entry["password"];
        }

        $state = [];
        foreach ($accounts as $email => $password) {
            $accounts[$email] = $a = new Account($logger, $dry_run, $email, $password);
            foreach ($a->getState() as $hostname => $entry) {
                if (isset($targets[$hostname])) {
                    $state[$hostname] = $entry;
                }
            }
        }

        $update_ipv4 = $update_ipv6 = $update_mx = false;
        foreach (array_keys($targets) as $hostname) {
            if (empty($state[$hostname])) {
                $logger->warn("Missing hostname $hostname in dy.fi!");
                unset($targets[$hostname]);
                continue;
            }
            if ($state[$hostname]["ipv4"] != $targets[$hostname]["ipv4"] || $state[$hostname]["expires"] < self::EXPIRATION_MARGIN) {
                $update_ipv4 = true;
            }
        }
        foreach (array_keys($targets) as $hostname) {
            $account = $accounts[$state[$hostname]["email"]];
            if ($update_ipv4) {
                $ipv4_in_dyfi = $account->updateIPv4($hostname, $targets[$hostname]["ipv4"]);
                if ($ipv4_in_dyfi) {
                    $new->ipv4_in_dyfi = $ipv4_in_dyfi;
                }
            }
            if ($state[$hostname]["ipv6"] != $targets[$hostname]["ipv6"]) {
                $account->updateIPv6($hostname, $targets[$hostname]["ipv6"]);
            }
            if ($state[$hostname]["mx"] != $targets[$hostname]["mx"]) {
                $account->updateMX($hostname, $targets[$hostname]["mx"]);
            }
        }

        // Which hosts were updated?
        $tmp = array_keys($targets);
        sort($tmp);
        $new->hosts = $tmp;

        // Get next expiration date.
        $expires = 7 * 86400;
        foreach ($accounts as $account) {
            $account->refresh();
            foreach ($account->getState() as $entry) {
                if (isset($targets[$entry["hostname"]])) {
                    $expires = min($expires, $entry["expires"]);
                }
            }
        }
        $new->expires = time() + $expires - self::EXPIRATION_MARGIN;
        $new->expires_str = date("Y-m-d H:i:s", $new->expires);

        if ($dry_run) {
            $logger->debug("Dry run, final state: ". json_encode($new, JSON_PRETTY_PRINT));
        } else {
            file_put_contents($state_file, json_encode($new, JSON_PRETTY_PRINT)."\n");
        }
    }

    public static function runOnce($conf) {
        $log = [];
        $logger = new class extends Logger {
            protected function write($level, $msg) {
                $log[] = [$level, $msg];
            }
        };

        $updater = new Updater();
        try {
            $updater->update($conf, $logger);
            return $log;
        } catch (Exception $e) {
            $logger->error($e->getMessage());
            $e->log = $log;
            throw $e;
        }
    }

    public static function runApplication() {
        // Parse arguments.
        $opts = getopt("hc:s:d", ["help", "conf:", "state:", "dry-run", "log-level:"]);
        $help = $opts["h"] ?? $opts["help"] ?? empty($opts);
        $conf_file = $opts["c"] ?? $opts["conf"] ?? null;
        if ($help || !$conf_file) {
            echo "Usage: php ".__FILE__." [options]\n";
            echo "Options:\n";
            echo "  -c, --conf <file>       Configuration file.\n";
            echo "  -s, --state <file>      State file.\n";
            echo "  -d, --dry-run           Don't actually update anything.\n";
            echo "      --log-level <level> Log level: debug, info, warn, error.\n";
            echo "  -h, --help              Show this help.\n";
            return 0;
        }

        // Sanity check: time can't be less than file modification time.
        // This prevents problems on systems without persistent RTC.
        if (time() < filemtime(__FILE__) || time() < filemtime($conf_file)) {
            throw new Exception("Error: System time is incorrect!");
        }

        // Load configuration.
        if (!is_readable($conf_file)) {
            echo "Error: Configuration file $conf_file not found!\n";
            return 1;
        }
        $conf = @json_decode(file_get_contents($conf_file), true);
        if (!$conf) {
            echo "Error: Configuration file $conf_file is invalid!\n";
            return 1;
        }

        // Set state file.
        $conf["state"] = $opts["s"] ?? $opts["state"] ?? $conf["state"] ?? null;

        // Set dry-run.
        $conf["dry-run"] = !empty($conf["dry-run"]) || isset($opts["d"]) || isset($opts["dry-run"]);

        // Set up logger.
        $log_level = $opts["log-level"] ?? $conf["log-level"] ?? "info";
        $logger = new class ($log_level) extends Logger {
            private $log_level;
            public function __construct($log_level) {
                $this->log_level = $log_level;
            }
            protected function write($level, $msg) {
                $levels = ["debug", "info", "warn", "error"];
                if (array_search($level, $levels) < array_search($this->log_level, $levels)) {
                    return;
                }
                echo "$level: $msg\n";
            }
        };

        // Run updater.
        try {
            $u = new Updater();
            $u->update($conf, $logger);
        } catch (Exception $e) {
            echo $e->getMessage(), "\n";
            return 1;
        }
    }
}
