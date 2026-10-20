<?php
namespace App\Services\SmartRegistration;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** Page-aware local extraction; large scans resume without a page-count cutoff. */
class PdfPageExtractor
{
    private float $deadline;
    public function extract(string $path, ?callable $progress = null): array
    {
        $this->deadline = microtime(true) + 120;
        $info = $this->run(['pdfinfo', $path]);
        $total = preg_match('/^Pages:\s+(\d+)/m', $info, $m) ? (int) $m[1] : 0;
        $raw = $this->run(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']);
        $texts = explode("\f", str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ' '], $raw));
        if (end($texts) === '') array_pop($texts);
        if ($total === 0) $total = $raw !== '' ? count($texts) : 0;
        if ($total < 1) return ['pages' => [], 'meta' => ['version' => 'lbs-all-pages-20261003',
            'total_pages' => null, 'processed_pages' => 0, 'complete' => false,
            'pending_pages' => [], 'unreadable_pages' => [],
            'warning' => 'Unable to read this PDF. Check for damage or password protection.']];
        $progress && $progress(['total_pages'=>$total,'processed_pages'=>0,'complete'=>false,'pages'=>[]]);
        $largeImages = [];
        foreach (explode("\n", $this->run(['pdfimages', '-list', $path])) as $line) {
            $cells = preg_split('/\s+/', trim($line));
            if (count($cells) > 5 && ctype_digit($cells[0]) && is_numeric($cells[3]) && is_numeric($cells[4])
                && (int) $cells[3] * (int) $cells[4] >= 900000) $largeImages[(int) $cells[0]] = true;
        }
        $cache = storage_path('app/nurselink-page-extraction/'.hash('sha256', $path.'|'.hash_file('sha256', $path).'|'.AccurateImageOcrService::VERSION.'|'.config('smart_registration.ocr.engine','paddleocr_v6').'|'.(int)config('smart_registration.ocr.accuracy_enabled',true).'|'.config('smart_registration.ocr.language','eng')));
        $pages = []; $pending = []; $unreadable = [];
        for ($page = 1; $page <= $total; $page++) {
            $text = trim($texts[$page - 1] ?? '');
            $letters = preg_replace('/[^\pL\pN]/u', '', $text) ?? '';
            $needsOcr = $letters === '' || isset($largeImages[$page]);
            $method = 'pdf_text'; $status = 'readable';
            $quality=null;
            if ($needsOcr) {
                $checkpoint = $cache.'/'.$page.'.json';
                $saved = is_file($checkpoint) ? json_decode((string) file_get_contents($checkpoint), true) : null;
                if (is_array($saved)) {
                    $text = (string) ($saved['text'] ?? '');
                    $status = (string) ($saved['status'] ?? 'unreadable'); $method = 'ocr';
                    $quality=$saved['quality']??['review_required'=>true];
                } elseif (microtime(true) > $this->deadline - 105) {
                    $status = 'pending'; $method = 'pending';
                } elseif ($this->available('pdftoppm') && $this->available('tesseract')) {
                    if (! is_dir($cache) && ! mkdir($cache, 0700, true) && ! is_dir($cache))
                        throw new \RuntimeException('Unable to create private page-extraction workspace.');
                    $prefix = $cache.'/render-'.bin2hex(random_bytes(8));
                    try {
                        $this->run(['pdftoppm', '-f', (string) $page, '-l', (string) $page,
                            '-singlefile', '-scale-to', '2400', '-png', $path, $prefix]);
                        $recognized=is_file($prefix.'.png') ? app(AccurateImageOcrService::class)->extract($prefix.'.png') : [];
                        $quality=$recognized['quality']??['version'=>AccurateImageOcrService::VERSION,'review_required'=>true,'fallback'=>true];
                        $ocr=$recognized!==[] ? trim($recognized['text']) : (is_file($prefix.'.png') ? trim($this->run(['tesseract', $prefix.'.png', 'stdout',
                            '-l', (string) config('smart_registration.ocr.language', 'eng'), '--psm', '1', '-c', 'preserve_interword_spaces=1'])) : '');
                        if (preg_match('/[\pL\pN]/u', $ocr)) $text = $ocr;
                        $method = 'ocr'; $status = preg_match('/[\pL\pN]/u', $text) ? 'readable' : 'unreadable';
                        $temp = $checkpoint.'.'.bin2hex(random_bytes(4));
                        file_put_contents($temp, json_encode(['text' => $text, 'status' => $status,'quality'=>$quality], JSON_INVALID_UTF8_SUBSTITUTE));
                        chmod($temp, 0600); rename($temp, $checkpoint);
                    } finally { if (is_file($prefix.'.png')) unlink($prefix.'.png'); }
                } else { $status = 'unreadable'; $method = 'unavailable'; }
            }
            if ($status === 'pending') $pending[] = $page;
            if ($status === 'unreadable') $unreadable[] = $page;
            $pages[] = ['page' => $page, 'text' => $text, 'method' => $method, 'status' => $status];
            if($quality!==null)$pages[count($pages)-1]['ocr_quality']=$quality;
            $progress && $progress(['total_pages'=>$total,'processed_pages'=>count(array_filter($pages,fn($item)=>$item['status']!=='pending')),'complete'=>false,'pages'=>array_map(fn($item)=>array_diff_key($item,['text'=>true]),$pages)]);
        }
        if ($pending !== []) {
            if (!is_dir($cache) && !mkdir($cache,0700,true) && !is_dir($cache))
                throw new \RuntimeException('Unable to checkpoint PDF extraction.');
            $progressFile=$cache.'/_progress.json';
            $previous=is_file($progressFile) ? json_decode((string)file_get_contents($progressFile),true) : [];
            $done=$total-count($pending);
            $stalled=(($previous['done'] ?? -1)===$done) ? (int)($previous['stalled'] ?? 0)+1 : 0;
            if ($stalled>=3) {
                foreach ($pages as &$item) if ($item['status']==='pending') {
                    $item['status']='unreadable'; $item['method']='resource_limit'; $unreadable[]=$item['page'];
                }
                unset($item); $pending=[];
            } else {
                file_put_contents($progressFile,json_encode(['done'=>$done,'stalled'=>$stalled]),LOCK_EX);
                chmod($progressFile,0600);
            }
        }
        if ($pending === [] && is_dir($cache)) {
            foreach (glob($cache.'/*.json') ?: [] as $file) unlink($file);
            @rmdir($cache);
        }
        return ['pages' => $pages, 'meta' => ['version' => 'lbs-all-pages-20261003',
            'total_pages' => $total, 'processed_pages' => $total - count($pending), 'complete' => $pending === [],
            'pending_pages' => $pending, 'unreadable_pages' => $unreadable,
            'pages' => array_map(fn ($p) => ['page' => $p['page'], 'method' => $p['method'], 'status' => $p['status'],'ocr_quality'=>$p['ocr_quality']??null], $pages),
            'warning' => $unreadable === [] ? null : 'Some pages could not be read. Review the original document and enter missing information manually.']];
    }
    private function available(string $command): bool
    {
        return (new ExecutableFinder)->find($command) !== null;
    }
    private function run(array $command): string
    {
        if (! $this->available($command[0])) return '';
        try {
            $process = new Process($command, null, ['OMP_THREAD_LIMIT' => '1']);
            $process->setTimeout(max(1, min(25, $this->deadline - microtime(true))));
            $process->run();
            return $process->isSuccessful() ? $process->getOutput() : '';
        } catch (\Throwable) { return ''; }
    }
}
