<?php
namespace App\Services\SmartRegistration;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
class DocumentStorageService
{
    private const ALLOWED_MIME = ['application/pdf','image/jpeg','image/png','application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    public function store(Application $application, User $user, UploadedFile $file, string $category): ApplicationDocument
    {
        if (! in_array($file->getMimeType(), self::ALLOWED_MIME, true)) throw ValidationException::withMessages(['file'=>'Unsupported document type.']);
        if ($file->getSize() > 15 * 1024 * 1024) throw ValidationException::withMessages(['file'=>'Document exceeds the 15 MB limit.']);
        $sha256 = hash_file('sha256', $file->getRealPath());
        $storedName = sprintf('%s.%s', (string) str()->uuid(), $file->guessExtension() ?: 'bin');
        $path = "applications/{$application->id}/documents/{$storedName}";
        Storage::disk(config('smart_registration.private_disk','private'))->put($path, file_get_contents($file->getRealPath()));
        return ApplicationDocument::query()->create([
            'application_id'=>$application->id,'uploaded_by_user_id'=>$user->id,'category'=>$category,
            'original_name'=>$file->getClientOriginalName(),'stored_name'=>$storedName,'disk'=>config('smart_registration.private_disk','private'),'path'=>$path,
            'mime_type'=>$file->getMimeType(),'size_bytes'=>$file->getSize(),'sha256'=>$sha256,
            'upload_status'=>'received','malware_scan_status'=>'pending','extraction_status'=>'not_started',
        ]);
    }
}
