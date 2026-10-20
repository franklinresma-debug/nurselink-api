<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\SmartRegistration\OcrSandboxService;
use Illuminate\Http\Request;

class OcrSandboxController extends Controller {
    public function handle(Request $request, OcrSandboxService $service) {
        $request->validate(['action'=>'required|in:inspect,page,parse']);
        if ($request->input('action')==='parse') {
            $data=$request->validate(['pages'=>'required|array|min:1|max:100','pages.*.page'=>'required|integer|min:1|max:100','pages.*.text'=>'required|string|max:120000','pages.*.method'=>'required|in:text,ocr','pages.*.ocr_quality'=>'nullable|array']);
            abort_if(mb_strlen(implode('',array_column($data['pages'],'text')))>120000,422,'Total text exceeds 120,000 characters.');
            return response()->json($service->parse($data['pages']))->header('Cache-Control','no-store');
        }
        $request->validate(['file'=>'required|file|max:15360|mimes:pdf,jpg,jpeg,png','page'=>'required_if:action,page|integer|min:1|max:100']);
        $file=$request->file('file'); $start=microtime(true);
        try {
            $result=$request->input('action')==='inspect' ? $service->inspect($file->getRealPath(),$file->getClientOriginalName()) : $service->page($file->getRealPath(),$file->getClientOriginalName(),(int)$request->input('page'));
            return response()->json([...$result,'request_seconds'=>round(microtime(true)-$start,3)])->header('Cache-Control','no-store');
        } catch (\Throwable $e) { report($e); return response()->json(['message'=>'Document processing failed. Check the file and OCR engine; retry is available.'],422)->header('Cache-Control','no-store'); }
    }
}
