<?php
namespace FormKit\Core\Contracts;
interface LoggerInterface { public function info(string $m,array $c=[]):void; public function error(string $m,array $c=[]):void; }
