<?php

namespace Metabolix\DyFiUpdater;

abstract class Logger {
    public function debug($msg) {
        $this->write("debug", $msg);
    }
    public function info($msg) {
        $this->write("info", $msg);
    }
    public function warn($msg) {
        $this->write("warn", $msg);
    }
    public function error($msg) {
        $this->write("error", $msg);
    }
	abstract protected function write($level, $msg);
}
