<?php
namespace App\Http\Requests\Communications;
use Illuminate\Foundation\Http\FormRequest;
class UpdateNotificationPreferencesRequest extends FormRequest { public function authorize():bool{return true;} public function rules():array{return ['in_app_enabled'=>['sometimes','boolean'],'email_enabled'=>['sometimes','boolean'],'sms_enabled'=>['sometimes','boolean'],'push_enabled'=>['sometimes','boolean'],'whatsapp_enabled'=>['sometimes','boolean'],'category_preferences'=>['sometimes','array'],'timezone'=>['sometimes','string','max:64']];} }
