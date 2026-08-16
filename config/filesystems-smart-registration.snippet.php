<?php
/*
Add this disk to config/filesystems.php:
'private' => [
  'driver' => 'local',
  'root' => storage_path('app/private'),
  'visibility' => 'private',
  'throw' => true,
],
Production should use encrypted access-controlled object storage and short-lived signed delivery.
*/
