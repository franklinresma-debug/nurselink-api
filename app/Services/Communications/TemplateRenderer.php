<?php
namespace App\Services\Communications;
use App\Models\User;
class TemplateRenderer {
 public function render(string $text,User $user,array $data=[]):string{$vars=array_merge(['name'=>$user->name,'email'=>$user->email,'member_number'=>$user->member?->member_no??''],$data);$replace=[];foreach($vars as $k=>$v)$replace['{{'.$k.'}}']=(string)($v??'');return strtr($text,$replace);}
}
