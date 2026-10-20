<?php
namespace App\Services\MigrationStaging;

use RuntimeException;
use ZipArchive;

/** Private review artifacts only. No database connection, account creation or job dispatch. */
class StagingStore
{
    public const MAX_BYTES = 10485760;
    public const MAX_ROWS = 2000;
    public const FIELDS = ['legacy_id','full_name','email','phone','birth_date','nationality','address','employer','position','legacy_status'];
    public function __construct(private string $root) {}

    private function fail(string $message): never { throw new RuntimeException($message); }
    private function locked(callable $fn): mixed
    {
        if (!is_dir($this->root) && !mkdir($this->root,0700,true) && !is_dir($this->root)) $this->fail('Staging storage unavailable.');
        $lock=fopen($this->root.'/.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) $this->fail('Staging is busy. Try again.');
        chmod($this->root.'/.lock',0600);
        try { return $fn(); } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    private function save(string $file, array $data): void
    {
        $tmp=$file.'.tmp';
        $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
        if (file_put_contents($tmp,$json)===false) $this->fail('Unable to save staging review.');
        chmod($tmp,0600);
        if (!rename($tmp,$file)) $this->fail('Unable to finish staging review.');
    }
    private function dir(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) $this->fail('Staging batch not found.');
        return $this->root.'/'.$id;
    }
    private function read(string $id,string $owner): array
    {
        $file=$this->dir($id).'/batch.json';
        if (!is_file($file)) $this->fail('Staging batch not found.');
        $batch=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
        if (!hash_equals($batch['owner'],$owner)) $this->fail('Staging batch not found.');
        return $batch;
    }
    private function publicBatch(array $b): array
    {
        unset($b['owner']);return $b;
    }
    public function listing(string $owner): array
    {
        return $this->locked(function()use($owner){
            $out=[];
            foreach(glob($this->root.'/*/batch.json')?:[] as $f){
                $b=json_decode(file_get_contents($f),true,64,JSON_THROW_ON_ERROR);
                if ($b['owner']===$owner) $out[]=$this->publicBatch($b);
            }
            usort($out,fn($a,$b)=>strcmp($b['created_at'],$a['created_at']));return $out;
        });
    }
    public function show(string $id,string $owner,int $page=1): array
    {
        return $this->locked(function()use($id,$owner,$page){
            $b=$this->read($id,$owner);$path=$this->dir($id).'/review.json';
            $review=is_file($path)?json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR):null;
            if($review){$review['rows']=array_slice($review['rows'],(max(1,$page)-1)*50,50);$review['page']=max(1,$page);$review['page_size']=50;}
            return ['batch'=>$this->publicBatch($b),'review'=>$review];
        });
    }
    public function review(string $id,string $owner): array
    {
        return $this->locked(function()use($id,$owner){$this->read($id,$owner);$p=$this->dir($id).'/review.json';if(!is_file($p))$this->fail('Validate the field mapping before downloading a review.');return json_decode(file_get_contents($p),true,64,JSON_THROW_ON_ERROR);});
    }
    public function upload(string $path,string $name,string $owner,string $source,string $label,string $delimiter=','): array
    {
        return $this->locked(function()use($path,$name,$owner,$source,$label,$delimiter){
            $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
            if(!in_array($ext,['csv','json','zip','sql'],true))$this->fail('Use CSV, JSON, ZIP or SQL. XLSX continues through the existing intake workflow.');
            if(!is_file($path)||filesize($path)<1||filesize($path)>self::MAX_BYTES)$this->fail('Files must be nonempty and at most 10 MiB.');
            if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,79}$/D',$source))$this->fail('Source system must be a stable 1–80 character code using letters, numbers, dot, dash or underscore.');
            if(trim($label)===''||mb_strlen($label)>120)$this->fail('Provide a batch name of at most 120 characters.');
            if(!in_array($delimiter,[',',';',"\t"],true))$this->fail('Unsupported CSV delimiter.');
            $sha=hash_file('sha256',$path);
            $existing=glob($this->root.'/*/batch.json')?:[];
            foreach($existing as $file){$b=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);if($b['owner']===$owner&&$b['source_system']===$source&&$b['sha256']===$sha&&$b['delimiter']===$delimiter&&$b['format']===$ext)return $this->publicBatch($b)+['reused'=>true];}
            if(count($existing)>=30)$this->fail('Staging capacity reached (30 batches). Ask the technical lead to archive reviewed batches under the retention policy.');
            $parsed=$ext==='sql'?['headers'=>[],'rows'=>[],'documents'=>[]]:$this->parse($path,$ext,$delimiter);
            $id=bin2hex(random_bytes(16));$dir=$this->dir($id);mkdir($dir,0700);
            try{
                if(!copy($path,$dir.'/source.bin'))$this->fail('Unable to preserve source upload.');chmod($dir.'/source.bin',0600);
                $batch=['id'=>$id,'owner'=>$owner,'source_system'=>$source,'name'=>trim($label),'format'=>$ext,'sha256'=>$sha,'bytes'=>filesize($path),'delimiter'=>$delimiter,'created_at'=>gmdate('c'),'status'=>$ext==='sql'?'conversion_required':'awaiting_mapping','rows'=>count($parsed['rows']),'headers'=>$parsed['headers'],'document_count'=>count($parsed['documents']),'mapping'=>new \stdClass(),'production_import_enabled'=>false,'note'=>$ext==='sql'?'SQL is quarantined only. A technical lead must inspect and restore it in a disposable isolated database, then export mapped CSV/JSON. No SQL is executed by this application.':'Private staging only. Nothing is sent to the live applicant-import queue.'];
                $this->save($dir.'/parsed.json',$parsed);$this->save($dir.'/batch.json',$batch);
                $this->save($dir.'/audit.json',[['at'=>gmdate('c'),'actor'=>$owner,'action'=>'upload','sha256'=>$sha]]);
                return $this->publicBatch($batch);
            }catch(\Throwable $e){foreach(glob($dir.'/*')?:[] as $p)unlink($p);rmdir($dir);throw $e;}
        });
    }
    private function text(string $v): string
    {
        if(!mb_check_encoding($v,'UTF-8')||str_contains($v,"\0"))$this->fail('Text must be valid UTF-8 without NUL bytes.');return $v;
    }
    private function records(array $rows): array
    {
        if(!$rows||count($rows)>self::MAX_ROWS)$this->fail('Use 1–2,000 applicant rows per file. No rows were staged.');
        $headers=[];$out=[];
        foreach($rows as $row){
            if(!is_array($row)||array_is_list($row)||count($row)>50)$this->fail('Each applicant must be an object with up to 50 named, flat fields.');
            $clean=[];
            foreach($row as $k=>$v){
                $k=$this->text((string)$k);
                if($k===''||mb_strlen($k)>100||preg_match('/[\x00-\x1f]/',$k))$this->fail('Field names must be nonempty, at most 100 characters and contain no control characters.');
                if(!is_null($v)&&!is_scalar($v))$this->fail('Nested JSON fields need an explicit source mapping. Supply flat applicant records.');
                $s=$v===null?'':(is_bool($v)?($v?'true':'false'):(string)$v);$this->text($s);
                if(mb_strlen($s)>4000)$this->fail('A cell exceeds 4,000 characters. No value was truncated.');
                $clean[$k]=$s;$headers[$k]=true;
            }
            if(count($headers)>50)$this->fail('Use at most 50 distinct source columns.');
            $out[]=$clean;
        }
        return ['headers'=>array_keys($headers),'rows'=>$out,'documents'=>[]];
    }
    private function parse(string $path,string $ext,string $delimiter): array
    {
        if($ext==='zip')return $this->archive($path,$delimiter);
        if($ext==='json'){
            $raw=$this->text(file_get_contents($path));
            $v=$this->decodeJson(preg_replace('/^\xEF\xBB\xBF/','',$raw));
            $rows=is_array($v)&&array_is_list($v)?$v:($v['applicants']??null);
            if(!is_array($rows)||!array_is_list($rows))$this->fail('JSON must contain an applicants array.');
            return $this->records($rows);
        }
        $raw=$this->text(file_get_contents($path));$this->csvSyntax(preg_replace('/^\xEF\xBB\xBF/','',$raw),$delimiter);unset($raw);
        $fp=fopen($path,'rb');$headers=fgetcsv($fp,0,$delimiter,'"','');
        if(!$headers){fclose($fp);$this->fail('CSV needs a header row.');}
        $headers=array_map(fn($h)=>trim($this->text((string)$h)),$headers);$headers[0]=preg_replace('/^\xEF\xBB\xBF/','',$headers[0]);
        if(in_array('',$headers,true)||count($headers)!==count(array_unique($headers))||count($headers)>50){fclose($fp);$this->fail('CSV headers must be unique, nonempty, and at most 50 columns.');}
        $rows=[];$line=1;
        try{while(($values=fgetcsv($fp,0,$delimiter,'"',''))!==false){$line++;if($values===[null])continue;
            if(count($values)!==count($headers))$this->fail("CSV record $line has a different column count. Check the delimiter and quoting.");
            $rows[]=array_combine($headers,$values);if(count($rows)>self::MAX_ROWS)$this->fail('CSV exceeds 2,000 applicant rows. Split the source; no partial batch was saved.');
        }}finally{fclose($fp);}
        return $this->records($rows);
    }
    private function decodeJson(string $raw): mixed
    {
        // Bound structure before allocating PHP arrays, even for a small but highly fragmented file.
        $quoted=false;$escaped=false;$objects=0;$separators=0;
        for($i=0,$n=strlen($raw);$i<$n;$i++){
            $c=$raw[$i];
            if($quoted){if($escaped){$escaped=false;continue;}if($c==='\\'){$escaped=true;continue;}if($c==='"')$quoted=false;continue;}
            if($c==='"'){$quoted=true;continue;}
            if($c==='{'&&++$objects>self::MAX_ROWS+1)$this->fail('JSON exceeds the 2,000-row object budget. Split the source; no partial batch was saved.');
            if(($c===','||$c===':')&&++$separators>210000)$this->fail('JSON has too many fields or values. Use bounded applicant batches.');
        }
        try{$v=json_decode($raw,true,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\JsonException){$this->fail('Invalid JSON; use flat applicant objects with quoted field names.');}
        // Detect duplicate object keys before json_decode can silently discard an earlier value.
        preg_match_all('/"(?:\\\\.|[^"\\\\])*"|[{}\\[\\]:,]/s',$raw,$tokens);
        $stack=[];
        foreach($tokens[0] as $t){
            if($t==='{'||$t==='['){$stack[]=['object'=>$t==='{','key'=>$t==='{','seen'=>[]];continue;}
            if($t==='}'||$t===']'){array_pop($stack);continue;}
            $i=count($stack)-1;if($i<0)continue;
            if($t===','&&$stack[$i]['object']){$stack[$i]['key']=true;continue;}
            if(str_starts_with($t,'"')&&$stack[$i]['object']&&$stack[$i]['key']){
                $key=json_decode($t,true,2,JSON_THROW_ON_ERROR);if(isset($stack[$i]['seen'][$key]))$this->fail('JSON contains a duplicate field name; no values were discarded.');
                $stack[$i]['seen'][$key]=true;$stack[$i]['key']=false;
            }
        }
        return $v;
    }
    private function csvSyntax(string $raw,string $delimiter): void
    {
        $state='start';$len=strlen($raw);
        for($i=0;$i<$len;$i++){$c=$raw[$i];
            if($state==='quoted'){if($c==='"'){if($i+1<$len&&$raw[$i+1]==='"')$i++;else $state='closed';}continue;}
            if($c===$delimiter||$c==="\n"||$c==="\r"){$state='start';continue;}
            if($state==='closed'){if($c===' '||$c==="\t")continue;$this->fail('Malformed CSV: unexpected text after a closing quote.');}
            if($c==='"'){if($state!=='start')$this->fail('Malformed CSV: quote inside an unquoted field.');$state='quoted';}else $state='plain';
        }
        if($state==='quoted')$this->fail('Malformed CSV: an opening quote is not closed.');
    }
    private function archive(string $path,string $delimiter): array
    {
        $zip=new ZipArchive();if($zip->open($path)!==true)$this->fail('Invalid ZIP package.');
        try{
            if($zip->numFiles>250)$this->fail('ZIP is limited to 250 entries.');
            $entries=[];$bytes=0;
            for($i=0;$i<$zip->numFiles;$i++){
                $s=$zip->statIndex($i);$n=$s['name'];$this->text($n);
                if(!preg_match('~^[A-Za-z0-9_./ -]+$~D',$n)||str_starts_with($n,'/')||in_array('..',explode('/',$n),true)||in_array('.',explode('/',$n),true)||str_contains($n,'//'))$this->fail('ZIP contains an unsafe or unsupported path.');
                $key=strtolower(rtrim($n,'/'));if(isset($entries[$key]))$this->fail('ZIP contains duplicate or case-conflicting paths.');
                $zip->getExternalAttributesIndex($i,$os,$attributes);if((($attributes>>16)&0170000)===0120000)$this->fail('ZIP symbolic links are not allowed.');
                if(($s['encryption_method']??0)!==0)$this->fail('Encrypted ZIP entries are not supported.');
                $bytes+=$s['size'];if($s['size']>self::MAX_BYTES||$bytes>30*1024*1024||$s['size']>max(1,$s['comp_size'])*100)$this->fail('ZIP exceeds entry size, 30 MiB expanded-size or 100:1 compression limits.');
                $entries[$key]=$n;
            }
            $raw=$zip->getFromName('manifest.json');if($raw===false||strlen($raw)>262144)$this->fail('ZIP requires a manifest.json of at most 256 KiB.');
            $m=$this->decodeJson($this->text($raw));
            $data=$m['data_file']??null;$docs=$m['documents']??[];
            if(!is_string($data)||!in_array(pathinfo($data,PATHINFO_EXTENSION),['csv','json'],true)||$data==='manifest.json'||!in_array($data,$entries,true)||!is_array($docs)||!array_is_list($docs))$this->fail('Manifest needs a CSV/JSON data_file and a documents array.');
            $used=['manifest.json'=>true,$data=>true];$manifestDocs=[];
            foreach($docs as $doc){
                if(!is_array($doc)||!is_string($doc['legacy_id']??null)||trim($doc['legacy_id'])===''||strlen($doc['legacy_id'])>160||!is_string($doc['path']??null))$this->fail('Each document needs a text legacy_id and path.');
                $n=$doc['path'];$extension=strtolower(pathinfo($n,PATHINFO_EXTENSION));
                if(!in_array($n,$entries,true)||isset($used[$n])||!in_array($extension,['pdf','docx','jpg','jpeg','png'],true))$this->fail('Every document must have a unique safe path and use PDF, DOCX, JPG or PNG.');
                $content=$zip->getFromName($n);if($content===false)$this->fail('Unable to read a ZIP document.');
                $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
                $allowed=['pdf'=>['application/pdf'],'docx'=>['application/zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document'],'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png']];
                if(!in_array($mime,$allowed[$extension],true))$this->fail('A document extension does not match its detected content type.');
                $used[$n]=true;$manifestDocs[]=['legacy_id'=>trim($doc['legacy_id']),'path'=>$n,'bytes'=>strlen($content),'sha256'=>hash('sha256',$content),'mime'=>$mime,'security_status'=>'unscanned_quarantine'];unset($content);
            }
            foreach($entries as $n)if(!str_ends_with($n,'/')&&!isset($used[$n]))$this->fail('ZIP has an unlisted file. List every document in manifest.json; executable files and nested archives are not accepted.');
            $bytes=$zip->getFromName($data);if($bytes===false)$this->fail('Unable to read ZIP applicant data.');
            $tmp=tmpfile();if(!$tmp)$this->fail('Temporary staging storage unavailable.');
            try{fwrite($tmp,$bytes);$result=$this->parse(stream_get_meta_data($tmp)['uri'],pathinfo($data,PATHINFO_EXTENSION),$delimiter);}finally{fclose($tmp);}
            $result['documents']=$manifestDocs;return $result;
        }finally{$zip->close();}
    }
    /** Caller supplies read-only existing-account matches. No production mutation exists. */
    public function validate(string $id,string $owner,array $mapping,callable $existingEmails): array
    {
        return $this->locked(function()use($id,$owner,$mapping,$existingEmails){
            $b=$this->read($id,$owner);if($b['format']==='sql')$this->fail('SQL requires isolated conversion before field mapping.');
            $p=json_decode(file_get_contents($this->dir($id).'/parsed.json'),true,64,JSON_THROW_ON_ERROR);
            $mapping=array_filter($mapping,fn($v)=>$v!==''&&$v!==null);
            foreach($mapping as $field=>$header)if(!in_array($field,self::FIELDS,true)||!is_string($header)||!in_array($header,$p['headers'],true))$this->fail('Mapping contains an unknown field or source column.');
            foreach(['legacy_id','full_name','email'] as $field)if(!isset($mapping[$field]))$this->fail('Map Legacy ID, Full name and Email before validation.');
            if(count($mapping)!==count(array_unique($mapping)))$this->fail('Map each source column to at most one staging field.');
            $rows=[];$ids=[];$emails=[];
            foreach($p['rows'] as $i=>$raw){
                $v=[];foreach($mapping as $field=>$header)$v[$field]=trim($raw[$header]??'');
                $v['email']=mb_strtolower($v['email']);$issues=[];
                if($v['legacy_id']===''||mb_strlen($v['legacy_id'])>160)$issues[]='missing_or_oversized_legacy_id';
                if($v['full_name']===''||mb_strlen($v['full_name'])>160)$issues[]='missing_or_oversized_full_name';
                if(!filter_var($v['email'],FILTER_VALIDATE_EMAIL)||strlen($v['email'])>255)$issues[]='missing_or_invalid_email';
                if(($v['phone']??'')!==''&&!preg_match('/^\+[1-9][0-9]{7,14}$/D',$v['phone']))$issues[]='phone_needs_country_or_format_review';
                if(($v['birth_date']??'')!==''){$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$v['birth_date']);if(!$date||$date->format('Y-m-d')!==$v['birth_date']||$date>new \DateTimeImmutable('today'))$issues[]='invalid_or_future_birth_date';}
                foreach($v as $field=>$value)if($field!=='full_name'&&$field!=='legacy_id'&&mb_strlen($value)>255)$issues[]='oversized_'.$field;
                if(($v['legacy_status']??'')!=='')$issues[]='legacy_status_needs_approved_crosswalk';
                $ids[$v['legacy_id']][]=$i;$emails[$v['email']][]=$i;
                $rows[]=['source_row'=>$i+1,'legacy_id'=>$v['legacy_id'],'proposed'=>$v,'original'=>$raw,'issues'=>$issues,'documents'=>[]];
            }
            foreach([$ids,$emails] as $kind=>$groups)foreach($groups as $key=>$indices)if($key!==''&&count($indices)>1)foreach($indices as $i)$rows[$i]['issues'][]=$kind===0?'duplicate_legacy_id_in_batch':'duplicate_email_in_batch';
            $existing=$existingEmails(array_keys($emails));
            foreach($rows as &$row)if(in_array($row['proposed']['email'],$existing,true))$row['issues'][]='email_matches_existing_account';unset($row);
            foreach(glob($this->root.'/*/review.json')?:[] as $file){
                if(dirname($file)===$this->dir($id))continue;
                $other=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
                if($other['source_system']!==$b['source_system'])continue;
                foreach($other['rows'] as $r)foreach($ids[$r['legacy_id']]??[] as $i)$rows[$i]['issues'][]='legacy_id_in_another_staged_batch';
            }
            $unlinked=[];
            foreach($p['documents'] as $doc){$indices=$ids[$doc['legacy_id']]??[];if(count($indices)!==1){$unlinked[]=$doc;continue;}$rows[$indices[0]]['documents'][]=$doc;$rows[$indices[0]]['issues'][]='document_unscanned_quarantine';}
            $flagged=0;
            foreach($rows as &$r){$r['issues']=array_values(array_unique($r['issues']));$r['status']=$r['issues']?'needs_review':'validated_draft';if($r['issues'])$flagged++;}unset($r);
            $review=['batch_id'=>$id,'source_system'=>$b['source_system'],'source_sha256'=>$b['sha256'],'validated_at'=>gmdate('c'),'mapping'=>$mapping,'summary'=>['source_rows'=>count($rows),'staged_rows'=>count($rows),'validated_drafts'=>count($rows)-$flagged,'flagged_rows'=>$flagged,'unlinked_documents'=>count($unlinked),'production_records_written'=>0],'unlinked_documents'=>$unlinked,'unmapped_columns'=>array_values(array_diff($p['headers'],array_values($mapping))),'production_import_enabled'=>false,'limitations'=>['Validation is not approval or a full migration dry run.','Phone/name fuzzy matching and historical application/qualification mappings require staff design.','Existing-account matching checks email only, at validation time.','Document files remain quarantined; no malware clearance is claimed.'],'rows'=>$rows];
            $this->save($this->dir($id).'/review.json',$review);
            $b['status']='review_ready';$b['mapping']=$mapping;$b['summary']=$review['summary'];$this->save($this->dir($id).'/batch.json',$b);
            $audit=json_decode(file_get_contents($this->dir($id).'/audit.json'),true);$audit[]=['at'=>gmdate('c'),'actor'=>$owner,'action'=>'validate','mapping'=>$mapping,'summary'=>$review['summary']];$this->save($this->dir($id).'/audit.json',$audit);
            return ['batch'=>$this->publicBatch($b),'summary'=>$review['summary']];
        });
    }
}
