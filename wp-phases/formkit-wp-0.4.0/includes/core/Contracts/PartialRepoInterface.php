<?php
namespace FormKit\Core\Contracts;
interface PartialRepoInterface { public function getPartial(string $name): ?string; }
