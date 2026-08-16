<?php
namespace App\Services\Communications;
use App\Models\User; use Illuminate\Database\Eloquent\Builder; use Illuminate\Support\Collection; use Illuminate\Validation\ValidationException;
class AudienceResolver {
 public function resolve(array $filters):Collection{
  $supported=['role_codes','user_ids','member_ids','member_statuses','countries','regions'];$effective=false;foreach($supported as $key)if(!empty($filters[$key])&&is_array($filters[$key])){$effective=true;break;}if(!$effective)throw ValidationException::withMessages(['audience_filters'=>'Choose at least one explicit, non-empty audience filter.']);
  $q=User::query()->where('status','active')->whereNotNull('email_verified_at');
  if($roles=$filters['role_codes']??[])$q->whereHas('roles',fn(Builder $r)=>$r->whereIn('code',$roles));
  if($ids=$filters['user_ids']??[])$q->whereIn('id',$ids);
  if($memberIds=$filters['member_ids']??[])$q->whereHas('member',fn(Builder $m)=>$m->whereIn('id',$memberIds));
  if($statuses=$filters['member_statuses']??[])$q->whereHas('member',fn(Builder $m)=>$m->whereIn('status',$statuses));
  if($countries=$filters['countries']??[])$q->whereHas('member.profile',fn(Builder $p)=>$p->whereIn('country',$countries));
  if($regions=$filters['regions']??[])$q->whereHas('member.profile',fn(Builder $p)=>$p->whereIn('region',$regions));
  return $q->orderBy('id')->get();
 }
}
