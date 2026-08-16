<?php
namespace App\Http\Requests\Credentials;
use Illuminate\Foundation\Http\FormRequest;
class UploadMemberDocumentRequest extends FormRequest
{
    public function authorize():bool{return true;}
    public function rules():array{return [
        'file'=>['required','file','max:15360','mimes:pdf,jpg,jpeg,png,docx'],
        'document_type'=>['required','string','max:100'],
        'title'=>['nullable','string','max:180'],
        'issued_on'=>['nullable','date'],
        'expires_on'=>['nullable','date','after_or_equal:issued_on'],
    ];}
}
