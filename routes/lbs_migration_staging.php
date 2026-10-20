<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AdminMigrationStagingController;

Route::middleware(['auth:sanctum','verified','active.user',\App\Http\Middleware\EnsureNurseLinkAdminPermission::class])
    ->prefix('/nurselink/admin/migration-staging')->group(function(){
        Route::get('/',[AdminMigrationStagingController::class,'index']);
        Route::post('/',[AdminMigrationStagingController::class,'upload'])->middleware('throttle:6,1');
        Route::get('/{batch}',[AdminMigrationStagingController::class,'show'])->where('batch','[a-f0-9]{32}');
        Route::post('/{batch}/validate',[AdminMigrationStagingController::class,'validateMapping'])->where('batch','[a-f0-9]{32}')->middleware('throttle:6,1');
        Route::get('/{batch}/review',[AdminMigrationStagingController::class,'download'])->where('batch','[a-f0-9]{32}');
    });
