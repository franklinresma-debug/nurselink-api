<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\LbsQueueController as Q;
Route::get('/lbs-queue/display',[Q::class,'display'])->middleware('throttle:60,1');
Route::middleware(['auth:sanctum','verified','active.user','throttle:60,1'])->prefix('lbs-queue')->group(function(){
 Route::get('/',[Q::class,'mine']);Route::post('/tickets',[Q::class,'issue']);Route::post('/qr-walkin',[Q::class,'qrWalkin'])->middleware('throttle:10,1');
 Route::patch('/tickets/{ticket}',[Q::class,'action'])->whereUuid('ticket');Route::get('/tickets/{ticket}/history',[Q::class,'history'])->whereUuid('ticket');
});
Route::middleware(['auth:sanctum','verified','active.user','mfa.required',\App\Http\Middleware\LbsOperationsAccess::class,'throttle:120,1'])->prefix('admin/lbs-queue')->group(function(){
 Route::get('/',[Q::class,'admin']);Route::post('/tickets',[Q::class,'walkin']);Route::post('/lookup',[Q::class,'lookup']);
 Route::patch('/tickets/{ticket}',[Q::class,'action'])->whereUuid('ticket');Route::get('/tickets/{ticket}/history',[Q::class,'history'])->whereUuid('ticket');
 Route::post('/counters/{counter}/next',[Q::class,'next'])->whereNumber('counter');Route::post('/config/{kind}',[Q::class,'config']);
});
