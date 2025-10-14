<?php
namespace FormKit\Core\Support;
final class Container {
    private static ?self $i=null; private array $defs=[]; private array $instances=[];
    public static function instance(): self { return self::$i ??= new self(); }
    public function set(string $id, callable $factory): void { $this->defs[$id]=$factory; }
    public function get(string $id) {
        if (isset($this->instances[$id])) return $this->instances[$id];
        if (!isset($this->defs[$id])) throw new \RuntimeException("Service $id not found");
        return $this->instances[$id]=($this->defs[$id])($this);
    }
}
