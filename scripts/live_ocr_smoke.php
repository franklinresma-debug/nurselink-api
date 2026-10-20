<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$path = '/tmp/nurselink-live-fallback-test.png';
$image = imagecreatetruecolor(900, 180);
$white = imagecolorallocate($image, 255, 255, 255);
$black = imagecolorallocate($image, 0, 0, 0);
imagefilledrectangle($image, 0, 0, 899, 179, $white);
imagettftext($image, 34, 0, 30, 80, $black, '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', 'First Name: Maria');
imagepng($image, $path);
imagedestroy($image);

$start = microtime(true);
$result = app(App\Services\SmartRegistration\LocalOcrService::class)->extract($path, 'resume.png');
echo json_encode([
    'status' => $result['status'],
    'type' => $result['document_type'],
    'first_name' => $result['fields']['first_name']['value'] ?? null,
    'elapsed' => round(microtime(true) - $start, 2),
], JSON_PRETTY_PRINT), PHP_EOL;
@unlink($path);
