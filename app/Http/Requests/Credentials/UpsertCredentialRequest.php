<?php
namespace App\Http\Requests\Credentials;
use Illuminate\Foundation\Http\FormRequest;
class UpsertCredentialRequest extends FormRequest
{
    public function authorize():bool{return true;}
    public function rules():array{return [
        'category'=>['required','in:license,registration,certification,training,cpd'],
        'credential_type'=>['required','string','max:120'],
        'title'=>['required','string','max:180'],
        'credential_number'=>['nullable','string','max:180'],
        'issuing_authority'=>['nullable','string','max:180'],
        'country'=>['nullable','string','size:2'],
        'issued_on'=>['nullable','date'],
        'expires_on'=>['nullable','date','after_or_equal:issued_on'],
        'does_not_expire'=>['sometimes','boolean'],
        'primary_document_id'=>['nullable','uuid'],
    ];}
}
