<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ClamAvScanner
{
    /**
     * @return array{status:string,response:string}
     */
    public function scan(string $disk, string $path): array
    {
        $host = (string) config('security_scanning.clamav.host');
        $port = (int) config('security_scanning.clamav.port');
        $timeout = (int) config('security_scanning.clamav.timeout_seconds', 30);
        $chunkBytes = (int) config('security_scanning.clamav.chunk_bytes', 1048576);

        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (! $socket) {
            throw new RuntimeException("ClamAV connection failed: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, $timeout);
        fwrite($socket, "zINSTREAM\0");

        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            fclose($socket);
            throw new RuntimeException('Unable to open document stream for malware scan.');
        }

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, $chunkBytes);
                if ($chunk === false) {
                    throw new RuntimeException('Unable to read document stream during malware scan.');
                }
                if ($chunk === '') {
                    continue;
                }
                fwrite($socket, pack('N', strlen($chunk)));
                fwrite($socket, $chunk);
            }
            fwrite($socket, pack('N', 0));

            $response = '';
            while (! feof($socket)) {
                $part = fread($socket, 8192);
                if ($part === false || $part === '') {
                    break;
                }
                $response .= $part;
                if (str_contains($response, "\0")) {
                    break;
                }
            }
        } finally {
            fclose($stream);
            fclose($socket);
        }

        $response = trim(str_replace("\0", '', $response));
        if (str_contains($response, ' FOUND')) {
            return ['status' => 'infected', 'response' => $response];
        }
        if (str_contains($response, ' OK')) {
            return ['status' => 'clean', 'response' => $response];
        }

        throw new RuntimeException('Unexpected ClamAV response: '.($response ?: '[empty]'));
    }
}
