<?php
namespace FormKit\Core\Repos;
use FormKit\Core\Contracts\PartialRepoInterface;

final class FilePartialRepo implements PartialRepoInterface {
    public function __construct(private string $dir) {}
    public function getPartial(string $name): ?string {
        $f = rtrim($this->dir,'/').'/'.$name.'.mde';
        return is_file($f) ? (file_get_contents($f) ?: '') : null;
    }
}
