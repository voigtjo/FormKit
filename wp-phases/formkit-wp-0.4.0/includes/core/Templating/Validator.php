<?php
namespace FormKit\Core\Templating;
final class Validator {
    public static function extractRules(string $mde): array {
        $rules=[]; if (preg_match_all('/#field\s+([a-zA-Z0-9_.\-]+)([^\n]*)/',$mde,$mm,PREG_SET_ORDER)) {
            foreach($mm as $m){ $name=$m[1]; $attrs=$m[2]; $r=[]; if(preg_match('/\brequired\b/',$attrs))$r[]='required'; if(preg_match('/type\s*=\s*"(?:email)"/',$attrs))$r[]='email'; $rules[$name]=$r; }
        } return $rules;
    }
    public static function validate(array $data, array $rules): array {
        $errors=[]; foreach($rules as $f=>$rs){ $v=$data[$f]??null;
            if(in_array('required',$rs,true) && ($v===null || $v==='' || $v===[])) $errors[$f][]='required';
            if(in_array('email',$rs,true) && !empty($v) && !is_email((string)$v)) $errors[$f][]='email';
        } return $errors;
    }
}
