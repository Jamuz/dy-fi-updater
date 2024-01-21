#!/bin/bash
pkgdir="$1"
install -D -m 0755 -t $pkgdir/usr/lib/dy-fi-updater src/*.php
install -D -m 0644 -t $pkgdir/usr/lib/systemd/system/ dy-fi-updater.service dy-fi-updater.timer
install -D -m 0644 -t $pkgdir/usr/lib/systemd/user/ dy-fi-updater.service dy-fi-updater.timer
install -D -m 0644 -t $pkgdir/usr/share/doc/dy-fi-updater README.md
install -d -m 0700 $pkgdir/var/lib/dy-fi-updater
