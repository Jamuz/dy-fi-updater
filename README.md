# dy-fi-updater

This is a script for updating dynamic DNS in https://dy.fi/. While IPv4 updating can be done with a one-liner, this script supports IPv6, MX, multiple domains, even multiple accounts. Also the IP address is checked before updating, so that the script may be safely used as often as necessary without flooding the service.

## Configuration

Create `dy-fi-updater.conf` as valid JSON.

```json
{
	"ipv4": true,
	"ipv6": true,
	"mx": "offline.dy.fi",
	"accounts": [
		{
			"email": "alice@example.com",
			"password": "foo-bar-baz",
			"hosts": [
				{"hostname": "alpha-example.dy.fi"},
				{"hostname": "bravo-example.dy.fi"}
			]
		},
		{
			"email": "bob@example.com",
			"password": "foo-bar-baz",
			"hosts": [
				{"hostname": "charlie-example.dy.fi"},
				{"hostname": "delta-example.dy.fi"}
			]
		}
	]
}
```

## Usage with systemd

* Install files (`sh install.sh /` does the trick, if not using a package manager).
* Edit `/etc/dy-fi-updater.conf` (system) or `~/.config/dy-fi-updater.conf` (user).
* Optional security for system-wide usage:
	* `chown` the configuration and state to a dedicated user.
	* `systemctl edit dy-fi-updater.service` and add `User=dedicated-user`.
* Enable `dy-fi-updater.timer` (system or user).
	* For single user, `loginctl enable-linger` might be useful.

## Usage as stand-alone application

To run as a stand-alone application, simply start CLI.php.

```sh
php src/CLI.php -c dy-fi-updater.conf -s dy-fi-updater.state
```

## Usage with PHP

```php
<?php
# Use a PSR-4 autoloader

$conf_file = __DIR__ . "/dy-fi-updater.conf";
$state_file = __DIR__ . "/dy-fi-updater.state";

$conf = json_decode(file_get_contents($conf_file), true);
$conf["state"] = $state_file;

try {
	Metabolix\DyFiUpdater\Updater::runOnce($conf);
} catch (Metabolix\DyFiUpdater\Exception $e) {
	print_r($e->log);
	die($e->getMessage());
}
```

## License

The Unlicense (Public Domain)
