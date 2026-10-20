<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$service = app(App\Services\BulkIntake\BulkNurseIntakeIntelligenceService::class);
$service->build(2);
$c = Illuminate\Support\Facades\DB::table('nurselink_bulk_intake_candidates')
    ->where('batch_id', 2)->first();

echo json_encode([
    'candidate_id' => $c->id ?? null,
    'status' => $c->status ?? null,
    'confidence' => $c->confidence ?? null,
    'display_name' => $c->display_name ?? null,
    'first_name' => $c->first_name ?? null,
    'middle_name' => $c->middle_name ?? null,
    'last_name' => $c->last_name ?? null,
    'email' => $c->email ?? null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
