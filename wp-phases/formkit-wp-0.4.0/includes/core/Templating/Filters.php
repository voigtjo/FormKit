<?php
namespace FormKit\Core\Templating;

final class Filters {
    public function apply(string $filter, $value, $arg=null) {
        switch ($filter) {
            case 'number': $dec=is_numeric($arg)?(int)$arg:0; return is_numeric($value)?number_format((float)$value,$dec,',','.') : $value;
            case 'date':   $fmt=$arg?:'Y-m-d'; $ts=is_numeric($value)?(int)$value:strtotime((string)$value); return $ts?date($fmt,$ts):$value;
            case 'upper':  return mb_strtoupper((string)$value);
            case 'lower':  return mb_strtolower((string)$value);
            default:       return $value;
        }
    }
}
