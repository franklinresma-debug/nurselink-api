<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\LbsProcessingController as P;
Route::middleware(['auth:sanctum','verified','active.user','throttle:60,1'])->prefix('lbs-processing')->group(function(){
 Route::get('/',[P::class,'index']);Route::get('/cases/{case}',[P::class,'show'])->whereUuid('case');
 Route::post('/offers/{offer}/response',[P::class,'respond'])->whereUuid('offer');
 Route::patch('/cases/{case}/items/{item}',[P::class,'item'])->whereUuid('case')->whereUuid('item');
});
Route::middleware(['auth:sanctum','verified','active.user','mfa.required',\App\Http\Middleware\LbsProcessingAccess::class,'throttle:120,1'])->prefix('admin/lbs-processing')->group(function(){
 Route::get('/',[P::class,'index']);Route::get('/applications',[P::class,'applications']);Route::get('/cases/{case}',[P::class,'show'])->whereUuid('case');
 Route::post('/offers',[P::class,'offer']);Route::post('/cases',[P::class,'open']);Route::patch('/cases/{case}',[P::class,'action'])->whereUuid('case');
 Route::post('/cases/{case}/items',[P::class,'item'])->whereUuid('case');Route::patch('/cases/{case}/items/{item}',[P::class,'item'])->whereUuid('case')->whereUuid('item');
});
