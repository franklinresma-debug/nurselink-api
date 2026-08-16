<?php
namespace App\Http\Requests\Organization;
use Illuminate\Foundation\Http\FormRequest; use Illuminate\Validation\Rule;
class UploadOrganizationDocumentRequest extends FormRequest { public function authorize():bool{return $this->user()?->hasPermission('organization.document.manage')??false;} public function rules():array{return ['file'=>['required','file','max:'.config('organization.max_document_kb',15360),'mimes:pdf,docx,xlsx,pptx,jpg,jpeg,png'],'title'=>['nullable','string','max:255'],'document_type'=>['nullable','string','max:80'],'visibility'=>['nullable',Rule::in(['internal','members','public'])]];} }
