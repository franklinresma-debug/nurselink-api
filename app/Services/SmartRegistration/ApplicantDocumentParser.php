<?php
namespace App\Services\SmartRegistration;

/** Conservative CV parsing: only document-supported values; every record needs review. */
class ApplicantDocumentParser
{
    private const PERSONAL = [
        'first name'=>'first_name','given name'=>'first_name','middle name'=>'middle_name',
        'last name'=>'last_name','surname'=>'last_name','family name'=>'last_name','full name'=>'full_name','name'=>'full_name',
        'date of birth'=>'birth_date','birth date'=>'birth_date','birthday'=>'birth_date','dob'=>'birth_date',
        'sex'=>'sex','gender'=>'sex','nationality'=>'nationality','citizenship'=>'nationality',
        'address'=>'address_line1','home address'=>'address_line1','residential address'=>'address_line1',
        'city'=>'city','province'=>'province','state'=>'province','country'=>'country','country of residence'=>'country',
        'email'=>'email','email address'=>'email','e mail'=>'email','mobile'=>'phone','mobile number'=>'phone',
        'phone'=>'phone','contact number'=>'phone','telephone'=>'phone','contact no'=>'phone',
        'current employer'=>'current_employer','current position'=>'current_position','professional title'=>'professional_title',
        'specialty'=>'specialty','specialization'=>'specialty','years of experience'=>'years_experience',
        'highest nursing education'=>'highest_nursing_education','graduation year'=>'graduation_year',
        'prc license number'=>'primary_license_number','prc license no'=>'primary_license_number',
        'license number'=>'primary_license_number','license no'=>'primary_license_number',
        'license expiry date'=>'primary_license_expiry','license country'=>'primary_license_country',
        'emergency contact'=>'emergency_contact_name_relationship','emergency contact name'=>'emergency_contact_name_relationship',
        'registration number'=>'primary_license_number','registration no'=>'primary_license_number',
        'prc registration number'=>'primary_license_number','prc no'=>'primary_license_number',
        'licence number'=>'primary_license_number','licence no'=>'primary_license_number',
        'degree'=>'highest_nursing_education','course'=>'highest_nursing_education',
        'e-mail'=>'email','contact'=>'phone','tel'=>'phone',
        'emergency contact number'=>'emergency_contact_phone','emergency contact phone'=>'emergency_contact_phone',
    ];
    private const EXTRA = ['civil status','marital status','place of birth','birth place','religion','height','weight','passport number','passport no','passport expiry','passport expiry date','national id','national id number','id number','spouse','dependents','number of dependents','preferred position','preferred country','availability','visa status','visa type','expected salary'];
    public function parse(array $pages, string $documentType = 'other'): array
    {
        $groups = []; $fields = []; $repeated = [];
        $prcDocument=$documentType==='prc_license' && preg_match('/\b(?:PRC|Professional Regulation Commission)\b/iu',implode("\n",array_column($pages,'text')));
        foreach ($pages as $p) foreach (preg_split('/\n/u',(string)$p['text']) ?: [] as $line) {
            $line=trim($line); if ($line!=='') $repeated[$line][(int)$p['page']]=true;
        } $sections = [0 => 'personal']; $headerLines = [];
        foreach ($pages as $page) {
            foreach (preg_split('/\n/u', (string) $page['text']) ?: [] as $lineIndex=>$raw) {
                if ($lineIndex<3 && (int)$page['page']>1 && count($repeated[trim($raw)] ?? [])>1 && in_array(trim($raw),array_column(array_slice($headerLines,0,3),'text'),true) && $this->nameLike(trim($raw))) continue;
                if (preg_match('/^\s*(?:page\s+)?\d+\s*(?:of|\/)\s*\d+\s*$/iu',$raw)) continue;
                if (count($repeated[trim($raw)] ?? [])>1 && preg_match('/(?:curriculum vitae|resume|résumé|confidential)/iu',$raw)) continue;
                $cells = preg_split('/\t+| {3,}/u', trim($raw)) ?: [];
                // A table label and its adjacent value form one field, not two lanes.
                if (count($cells)===2 && $this->label($cells[0])[0]!=='' && $this->label($cells[0])[1]==='' && $this->label($cells[1])[0]==='') $cells=[rtrim($cells[0],': ').': '.$cells[1]];
                $multi = count($cells) > 1 && count(array_filter($cells, fn ($c) => $this->section($c) !== null)) > 0;
                foreach ($cells as $lane => $cell) {
                    $line = trim($cell, " \t\r\n\x0B\x0C"); if ($line === '') continue;
                    $lane = $multi || isset($sections[$lane]) ? $lane : 0;
                    if (($section = $this->section($line)) !== null) { $sections[$lane] = $section; continue; }
                    $section = $sections[$lane] ?? $sections[0] ?? 'personal';
                    $row = ['text'=>$line, 'page'=>(int)$page['page'], 'method'=>$page['method'] ?? 'text'];
                    $groups[$section][] = $row;
                    if ($section === 'personal' && (int)$page['page'] === 1) $headerLines[] = $row;
                }
            }
        }
        $records = array_fill_keys(['personal_data','employment','education','credentials','languages','references','skills'], []);
        foreach (['personal','credentials'] as $section) {
            $rows = $groups[$section] ?? [];
            foreach ($rows as $i => $row) {
                if ($section === 'personal') {
                    $graduation = $this->graduationInfo($row['text'], $rows[$i+1]['text'] ?? null);
                    if ($graduation !== null) {
                        $evidence = $row;
                        if ($graduation['next_line']) $evidence['text'] .= "\n".$rows[$i+1]['text'];
                        if ($graduation['year'] !== null) {
                            $this->candidate($fields, 'graduation_year', $graduation['year'], $evidence,
                                $graduation['review_required'] ? .68 : .9);
                        } else {
                            $records['personal_data'][] = $this->record([
                                'label'=>'Graduation date (review required)', 'value'=>$graduation['value'],
                            ], [$evidence]);
                        }
                        continue;
                    }
                }
                [$label, $value] = $this->label($row['text']);
                if ($prcDocument && in_array($label,['expiry date','expiration date','valid until'],true)) $label='license expiry date';
                if ($section === 'credentials' && !str_starts_with(self::PERSONAL[$label] ?? '', 'primary_license_')
                    && !($prcDocument && in_array(self::PERSONAL[$label] ?? '',['first_name','middle_name','last_name','full_name','birth_date','sex'],true))) continue;
                if ($label !== '' && $value === '' && isset($rows[$i+1]) && $rows[$i+1]['page']===$row['page'] && $this->label($rows[$i+1]['text'])[0] === '') {
                    $value = $rows[$i+1]['text']; $row['text'].="\n".$value;
                }
                if (isset(self::PERSONAL[$label]) && $value !== '') {
                    $field = self::PERSONAL[$label]; $normalized = $value;
                    if (in_array($field, ['birth_date','primary_license_expiry'], true)) {
                        $normalized = $this->date($value);
                        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized ?? '')) {
                            $records['personal_data'][] = $this->record(['label'=>ucwords($label).' (review date)', 'value'=>$value], [$row]);
                            continue;
                        }
                    }
                    if ($field === 'sex') $normalized = (new FieldNormalizer)->normalize($field,$value);
                    if ($field === 'sex' && ! in_array($normalized, ['female','male','other','non_binary','prefer_not_to_say'], true)) continue;
                    
                    if (in_array($field, ['years_experience','graduation_year'], true)) {
                        $normalized=(new FieldNormalizer)->normalize($field,$value); if ($normalized===null) continue;
                    }
                    if ($field==='birth_date' && $normalized>=date('Y-m-d')) {
                        $records['personal_data'][]=$this->record(['label'=>'Date of birth (review date)','value'=>$value],[$row]); continue;
                    }
                    if ((new FieldNormalizer)->normalize($field,$normalized)===null) {
                        $records['personal_data'][]=$this->record(['label'=>ucwords($label).' (review required)','value'=>$value],[$row]); continue;
                    }
                    if ($field==='primary_license_number' && str_contains($label,'prc') && preg_match('/[A-Za-z]/',$value)) {
                        $records['personal_data'][]=$this->record(['label'=>'PRC number (review required)','value'=>$value],[$row]); continue;
                    }
                    $this->candidate($fields, $field, $normalized, $row, .9);
                } elseif (in_array($label, self::EXTRA, true) && $value !== '') {
                    $extra=$value; $review=false;
                    if (in_array($label,['civil status','marital status'],true)) {
                        $key=mb_strtolower($value); $review=!in_array($key,['single','married','widowed','separated','divorced','annulled'],true);
                        if (!$review) $extra=ucfirst($key);
                    } elseif (str_contains($label,'expiry')) {
                        $date=$this->date($value); $review=$date===null; $extra=$date ?? $value;
                    } elseif (preg_match('/passport|national id|id number/',$label)) {
                        $review=!preg_match('/^(?=.*\d)[A-Za-z0-9][A-Za-z0-9 -]{4,24}$/',$value);
                    }
                    $records['personal_data'][] = $this->record(['label'=>ucwords($label).($review ? ' (review required)' : ''), 'value'=>$extra], [$row]);
                } elseif (in_array($label, ['languages','languages spoken','language proficiency'], true) && $value !== '') {
                    $groups['languages'][] = [...$row, 'text'=>$value];
                }
                if ($section === 'personal') {
                    if (preg_match('/^(\d{1,2})\s+years?\s+(?:of\s+)?nursing\s+experience$/iu',$row['text'],$exp)) $this->candidate($fields,'years_experience',(int)$exp[1],$row,.85);
                    if ($label!=='' && !in_array(self::PERSONAL[$label] ?? '',['email','phone'],true)) continue;
                    if (preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu', $row['text'], $m)) $this->candidate($fields, 'email', $m[0], $row, .85);
                    if (preg_match('/(?<!\d)(?:\(63\)|\+63|63|0)[ \t.-]*9(?:[ \t.-]*\d){9}(?!\d)/u', $row['text'], $m) && ! str_contains($label, 'emergency')) $this->candidate($fields, 'phone', $m[0], $row, .82);
                }
            }
        }
        if (! isset($fields['full_name'])) {
            foreach (array_slice($headerLines, 0, 5) as $row) {
                // Strip comma-separated postnominal qualifications in a CV header,
                // never stripping words from an unqualified applicant name.
                $name = preg_replace('/(?:,\s*(?:BSN|MSN|PHRN|USRN|RN|CLDP[®\x{2122}]?))+\s*$/iu', '', $row['text']);
                $name = preg_replace('/,?\s+R\.?N\.?\s*$/iu', '', $name);
                if ($this->nameLike($name)) { $this->candidate($fields, 'full_name', $name, $row, .68); break; }
            }
        }
        if (isset($fields['full_name']) && (! isset($fields['first_name']) || ! isset($fields['last_name']))) {
            $name = $fields['full_name']; $full=(string)$name['value'];
            $row = ['page'=>$name['source_page'],'text'=>$name['source_excerpt'],'method'=>$name['source_method'] ?? 'text'];
            // Comma-separated family name is explicit. Unmarked compound given/middle names remain ambiguous.
            if (preg_match('/,\s*(?:Jr\.?|Sr\.?|II|III|IV)$/iu',$full)) {
                // Preserve the full name; a suffix is not a given name.
            } elseif (preg_match('/^([^,]+),\s*([^,]+)$/u',$full,$m)) {
                if (!isset($fields['last_name'])) $this->candidate($fields,'last_name',trim($m[1]),$row,.85);
                if (!isset($fields['first_name'])) $this->candidate($fields,'first_name',trim($m[2]),$row,.68);
            } elseif (preg_match('/^(.+?)\s+((?:de la|del|de los|de|dela|san|santa)\s+[\pL\pM\x{27}-]+)$/iu',$full,$m)) {
                if (!isset($fields['last_name'])) $this->candidate($fields,'last_name',$m[2],$row,.68);
                if (!isset($fields['first_name'])) $this->candidate($fields,'first_name',$m[1],$row,.65);
            } elseif (preg_match('/^([\pL\pM]+(?:\s+[\pL\pM]+)*)\s+([\pL]\.)\s+([\pL\pM-]+)$/u', $full, $m)) {
                // Ambiguous name split: present suggestions for applicant confirmation.
                if (!isset($fields['first_name'])) $this->candidate($fields,'first_name',$m[1], $row,.65);
                if (!isset($fields['middle_name'])) $this->candidate($fields,'middle_name',$m[2], $row,.65);
                if (!isset($fields['last_name'])) $this->candidate($fields,'last_name',$m[3], $row,.65);
            } else {
                $parts=preg_split('/\s+/u',trim($full));
                if (count($parts)===2) {
                    if (!isset($fields['first_name'])) $this->candidate($fields,'first_name',$parts[0],$row,.68);
                    if (!isset($fields['last_name'])) $this->candidate($fields,'last_name',$parts[1],$row,.68);
                }
            }
        }

        foreach (['employment','education','credentials','references'] as $group) $records[$group] = $this->blocks($groups[$group] ?? [], $group);
        $education = $records['education'];
        if ($education !== []) {
            $rank = fn($r) => preg_match('/doctor|PhD|DNP/iu',$r['qualification'] ?? '') ? 3 : (preg_match('/master|MSN/iu',$r['qualification'] ?? '') ? 2 : 1);
            usort($education,fn($a,$b)=>$rank($b)<=>$rank($a));
            $degree=$education[0];
            if (isset($fields['highest_nursing_education']['value'])) {
                foreach ($education as $entry) {
                    if (mb_strtolower(trim($entry['qualification'] ?? '')) === mb_strtolower(trim((string)$fields['highest_nursing_education']['value']))) {
                        $degree=$entry; break;
                    }
                }
            }
            $row=['page'=>$degree['source_page'],'text'=>$degree['source_excerpt'],'method'=>$degree['confidence']<=.72 ? 'ocr' : 'text'];
            if (!empty($degree['qualification']) && !isset($fields['highest_nursing_education'])) $this->candidate($fields,'highest_nursing_education',$degree['qualification'],$row,.76);
            if (!empty($degree['completed_on']) && preg_match('/^(19|20)\d{2}/',$degree['completed_on'],$m))
                $this->candidate($fields,'graduation_year',(int)$m[0],$row,
                    str_contains($degree['notes'] ?? '', 'Graduation date needs confirmation') ? .68 : .86);
        }
        // Do not borrow another degree's year when the selected degree has no completion date.
        if (!isset($fields['graduation_year'])) {
            $rows=$education === [] ? ($groups['education'] ?? []) : [];
            if ($education !== [] && !empty($degree['date_text'])) {
                $rows=[['text'=>$degree['date_text'],'page'=>$degree['source_page'],
                    'method'=>$degree['confidence']<=.72 ? 'ocr' : 'text']];
            }
            foreach ($rows as $i=>$row) {
                $graduation=$this->graduationInfo($row['text'], $rows[$i+1]['text'] ?? null);
                if ($graduation === null || $graduation['year'] === null) continue;
                if ($graduation['next_line']) $row['text'].="\n".$rows[$i+1]['text'];
                $this->candidate($fields,'graduation_year',$graduation['year'],$row,
                    $graduation['review_required'] ? .68 : .86);
            }
        }
        foreach ($groups['other'] ?? [] as $row) $records['personal_data'][] = $this->record(['label'=>'Professional summary / Other information','value'=>$row['text']],[$row]);
        $records['languages'] = $this->languages($groups['languages'] ?? []);
        foreach ($groups['skills'] ?? [] as $row) foreach (preg_split('/[,;•]+/u', $row['text']) ?: [] as $skill) {
            $skill = trim($skill, " \t-•"); if (preg_match('/[\pL]{2}/u',$skill) && mb_strlen($skill)<=150 && !preg_match('/[!?@#]{2,}/u',$skill)) $records['skills'][] = $this->record(['name'=>$skill], [$row]);
        }
        $jobs = $records['employment'];
        usort($jobs, fn($a,$b) => ((int)($b['is_current'] ?? false) <=> (int)($a['is_current'] ?? false)) ?: strcmp($b['started_on'] ?? '', $a['started_on'] ?? ''));
        if ($jobs !== []) {
            $job = $jobs[0]; $row = ['page'=>$job['source_page'], 'text'=>$job['source_excerpt'], 'method'=>$job['confidence']<=.72 ? 'ocr' : 'text'];
            foreach (['position_title'=>'current_position','employer'=>'current_employer'] as $key=>$field)
                if (! empty($job[$key]) && ! isset($fields[$field])) $this->candidate($fields, $field, $job[$key], $row, .76);
            foreach (['country'=>'employment_countries','employer'=>'healthcare_facility_names','position_title'=>'employment_positions'] as $key=>$field) {
                $values = array_values(array_unique(array_filter(array_column($jobs, $key))));
                if ($values) {
                    $support=['page'=>$row['page'],'source_pages'=>array_values(array_unique(array_merge(...array_column($jobs,'source_pages')))),
                        'text'=>implode("\n",array_column($jobs,'source_excerpt')),'method'=>min(array_column($jobs,'confidence'))<=.72 ? 'ocr' : 'text'];
                    $this->candidate($fields, $field, implode('; ', $values), $support, .76);
                }
            }
        }
        foreach ($records as &$items) {
            $seen = []; $items = array_values(array_filter($items, function($item) use (&$seen) {
                $key = $this->identity($item); if (isset($seen[$key])) return false; $seen[$key] = true; return true;
            }));
        }
        unset($items);
        return ['fields'=>$fields, 'records'=>$records];
    }
    private function section(string $line): ?string
    {
        $s = strtolower(trim(preg_replace('/[^\pL\pN ]/u', ' ', $line))); $s = preg_replace('/\s+/', ' ', $s);
        foreach (['personal'=>['personal data','personal details','personal information','contact details','bio data'],
            'employment'=>['certificate of employment','work experience','professional experience','employment history','work history','employment record','employment','clinical experience'],
            'education'=>['education','educational background','educational qualifications','academic qualifications'],
            'credentials'=>['professional identification card','prc license','certificate of completion','certificate of training','licenses','licences','licenses and certifications','certifications','certificates','professional licenses','training','trainings','training and seminars'],
            'languages'=>['languages','language','languages spoken','language proficiency'],'references'=>['references','character references','character reference','professional references','referees'],
            'skills'=>['skills','clinical skills','technical skills','core competencies','competencies'],
            'other'=>['objective','career objective','professional summary','summary','declaration','interests','hobbies']] as $key=>$labels)
            if (in_array($s, $labels, true)) return $key;
        return null;
    }
    private function label(string $line): array
    {
        if (preg_match('/^([^:=]{1,55})\s*[:=]\s*(.*)$/u', $line, $m)) return [$this->labelKey($m[1]), trim($m[2])];
        $key = $this->labelKey($line);
        return isset(self::PERSONAL[$key]) || in_array($key, self::EXTRA, true) ? [$key,''] : ['', $line];
    }
    private function labelKey(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower(str_replace(['.','_'], ['',' '], $value))));
    }
    private function candidate(array &$fields, string $key, mixed $value, array $row, float $confidence): void
    {
        $value=(new FieldNormalizer)->normalize($key,$value);
        if ($value === '' || $value === null) return;
        $confidence = ($row['method'] ?? '') === 'ocr' ? min(.72, $confidence) : $confidence;
        $candidate = ['value'=>$value,'confidence'=>$confidence,'source_page'=>$row['page'],'source_pages'=>$row['source_pages'] ?? [$row['page']],
            'source_excerpt'=>mb_substr($row['text'], 0, 400),'source_method'=>$row['method'] ?? 'text',
            'normalization_version'=>'lbs-field-aware-v1','review_required'=>$confidence < .85];
        if (! isset($fields[$key])) { $fields[$key] = $candidate; return; }
        if (strtolower(trim((string)$fields[$key]['value'])) === strtolower(trim((string)$value))) return;
        foreach ($fields[$key]['alternatives'] ?? [] as $alternative) if (strtolower(trim((string)$alternative['value'])) === strtolower(trim((string)$value))) return;
        $fields[$key]['alternatives'] ??= [array_diff_key($fields[$key], ['alternatives'=>true])];
        $fields[$key]['alternatives'][] = $candidate; $fields[$key]['conflict'] = true; $fields[$key]['review_required'] = true;
    }
    private function blocks(array $rows, string $group): array
    {
        $maps = [
            'employment'=>[
                'employer'=>'employer','company'=>'employer','hospital'=>'employer','facility'=>'employer',
                'position'=>'position_title','job title'=>'position_title','designation'=>'position_title',
                'department'=>'department','country'=>'country','location'=>'city','city'=>'city',
                'from'=>'started_on','start date'=>'started_on','to'=>'ended_on','end date'=>'ended_on',
                'duration'=>'date_text','dates'=>'date_text','period'=>'date_text',
                'duties'=>'responsibilities','responsibilities'=>'responsibilities',
                'reason for leaving'=>'reason_for_leaving','bed capacity'=>'bed_capacity'],
            'education'=>[
                'school'=>'institution','institution'=>'institution','university'=>'institution',
                'degree'=>'qualification','course'=>'qualification','qualification'=>'qualification',
                'field of study'=>'field_of_study','country'=>'country','from'=>'started_on',
                'to'=>'completed_on','year graduated'=>'completed_on','graduation date'=>'completed_on',
                'dates'=>'date_text','period'=>'date_text'],
            'credentials'=>[
                'title'=>'title','certificate'=>'title','certification'=>'title','license'=>'title',
                'license number'=>'credential_number','license no'=>'credential_number',
                'prc license number'=>'credential_number','prc license no'=>'credential_number',
                'number'=>'credential_number','issued by'=>'issuing_authority','issuing authority'=>'issuing_authority',
                'country'=>'country','date issued'=>'issued_on','issue date'=>'issued_on',
                'expiry date'=>'expires_on','expiration date'=>'expires_on','valid until'=>'expires_on'],
            'references'=>[
                'name'=>'name','full name'=>'name','reference name'=>'name','position'=>'position_title',
                'designation'=>'position_title','organization'=>'organization','organisation'=>'organization',
                'company'=>'organization','hospital'=>'organization','relationship'=>'relationship',
                'phone'=>'phone','mobile'=>'phone','contact number'=>'phone','contact no'=>'phone',
                'telephone'=>'phone','email'=>'email','email address'=>'email'],
        ];
        $map = $maps[$group]; $items = []; $record = []; $evidence = [];
        $flush = function() use (&$items,&$record,&$evidence,$group) {
            $required = match($group) {
                'employment'=>['employer','position_title'],'education'=>['qualification','institution'],
                'credentials'=>['title','credential_number'],default=>['name']};
            if (array_filter($required, fn($k)=>!empty($record[$k]))) $items[] = $this->record($record, $evidence);
            $record = []; $evidence = [];
        };
        $skipGraduationLine=-1;
        foreach ($rows as $i=>$row) {
            if ($i===$skipGraduationLine) continue;
            $line = trim($row['text'], " \t•");
            if ($this->label($line)[0]!=='' && $this->label($line)[1]==='' && isset($rows[$i+1]) && $rows[$i+1]['page']===$row['page'] && $this->label($rows[$i+1]['text'])[0]==='') {
                $line=rtrim($line,': ').': '.$rows[$i+1]['text']; $row['text'].="\n".$rows[$i+1]['text']; $skipGraduationLine=$i+1;
            }
            if (preg_match('/available (?:upon|on) request/iu', $line)) continue;
            if ($group === 'education') {
                $graduation=$this->graduationInfo($line, $rows[$i+1]['text'] ?? null, !empty($record['qualification']));
                if ($graduation !== null) {
                    $evidence[]=$row;
                    if ($graduation['next_line']) { $evidence[]=$rows[$i+1]; $skipGraduationLine=$i+1; }
                    if ($graduation['date'] !== null) {
                        if (empty($record['completed_on'])) {
                            $record['completed_on']=$graduation['date'];
                        } elseif ($record['completed_on'] !== $graduation['date']) {
                            $record['notes']=trim(($record['notes'] ?? '')."\nGraduation date needs confirmation: conflicting completion date ".$graduation['value']);
                        }
                    }
                    if ($graduation['review_required']) {
                        $record['notes']=trim(($record['notes'] ?? '')."\nGraduation date needs confirmation: ".$graduation['value']);
                    }
                    if ($graduation['date'] === null || $graduation['review_required']) $record['date_text']=$graduation['value'];
                    continue;
                }
            }
            [$label,$value] = $this->label($line); $key = $map[$label] ?? null;
            if ($key !== null && $value !== '') {
                if (in_array($key, ['employer','position_title','qualification','title','name'], true)
                    && !empty($record[$key]) && $record[$key] !== $value) $flush();
                if (in_array($key, ['started_on','ended_on','completed_on','issued_on','expires_on'], true)) {
                    $date=$this->date($value);
                    if ($date!==null) $record[$key]=$date;
                    else $record['notes']=trim(($record['notes'] ?? '')."\nReview ".$key.': '.$value);
                    if ($key === 'ended_on' && preg_match('/^(present|current|ongoing)$/i', $value)) {
                        $record['is_current']=true; unset($record[$key]);
                    }
                } elseif (in_array($key,['email','phone','credential_number','name'],true) && (new FieldNormalizer)->normalize($key,$value)===null) {
                    $record['notes']=trim(($record['notes'] ?? '')."\nReview ".$key.': '.$value);
                } else $record[$key] = isset($record[$key]) && $key === 'responsibilities'
                    ? $record[$key]."\n".$value : ((new FieldNormalizer)->normalize($key,$value) ?? $value);
                $evidence[] = $row;
                if ($key !== 'date_text') continue;
            }
            if (preg_match($this->rangePattern(), $line, $dates)) {
                $record['date_text'] = $dates[0]; $record['started_on'] = $this->date($dates[1]);
                if (preg_match('/present|current|ongoing/i', $dates[2])) $record['is_current']=true;
                else $record[$group === 'education' ? 'completed_on' : 'ended_on'] = $this->date($dates[2]);
                $evidence[] = $row; continue;
            }
            $role = preg_match('/^[\pL][\pL .&()\/-]{0,95}\b(?:nurse|manager|supervisor|officer|technician|assistant|intern)(?:\s*\([^)]*\))?$/iu', $line);
            $organization = preg_match('/\b(hospital|medical cent(?:er|re)|clinic|university|college|school|institute|inc\.?|ltd\.?|corporation)\b/iu', $line);
            if ($group === 'employment' && $role) {
                if (!empty($record['position_title'])) $flush(); $record['position_title']=$line;
            } elseif ($group === 'employment' && $organization && mb_strlen($line)<220) {
                if (!empty($record['employer'])) $flush(); $record['employer']=$line;
            } elseif ($group === 'education' && preg_match('/\b(bachelor|master|doctor|diploma|BSN|MSN|BSc|PhD)\b/iu', $line)) {
                if (!empty($record['qualification'])) $flush(); $record['qualification']=$line;
            } elseif ($group === 'education' && $organization) $record['institution']=$line;
            elseif ($group === 'references' && preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu', $line, $m)) $record['email']=(new FieldNormalizer)->normalize('email',$m[0]);
            elseif ($group === 'references' && preg_match('/(?<!\d)\+?\d[\d ()-]{7,22}\d(?!\d)/u', $line, $m)) $record['phone']=(new FieldNormalizer)->normalize('phone',trim($m[0]));
            elseif ($group === 'references' && $organization) $record['organization']=$line;
            elseif ($group === 'references' && $role) $record['position_title']=$line;
            elseif ($group === 'references' && $this->nameLike($line)) {
                if (!empty($record['name'])) $flush(); $record['name']=$line;
            } elseif ($group === 'credentials' && preg_match('/\b(BLS|ACLS|PALS|IELTS|OET|NCLEX|license|licence|certificate|certification|life support|registered nurse)\b/iu', $line)) {
                if (!empty($record['title'])) $flush(); $record['title']=$line;
            } elseif ($group === 'employment' && $record !== [] && ($label === '' || $key === 'responsibilities')) {
                $record['responsibilities']=trim(($record['responsibilities'] ?? '')."\n".ltrim($line, '-• '));
            } elseif ($record !== [] && mb_strlen($line)<400) $record['notes']=trim(($record['notes'] ?? '')."\n".$line);
            else continue;
            $evidence[]=$row;
        }
        $flush(); return $items;
    }
    private function languages(array $rows): array
    {
        $items=[];
        foreach ($rows as $row) {
            [$label,$value]=$this->label($row['text']);
            if (in_array($label,['speaking','reading','writing','proficiency','exam','score'],true) && $items) {
                $last=array_key_last($items); $items[$last][$label]=$value;
                $meta=$this->record([$label=>$value],[$row]);
                $items[$last]['field_provenance'][$label]=$meta['field_provenance'][$label];
                $items[$last]['source_pages']=array_values(array_unique([...$items[$last]['source_pages'],...$meta['source_pages']]));
                $items[$last]['source_excerpt']=mb_substr($items[$last]['source_excerpt']."\n".$row['text'],0,1200);
                $items[$last]['confidence']=min($items[$last]['confidence'],$meta['confidence']);
                continue;
            }
            $line=in_array($label,['language','languages'],true) ? $value : $row['text'];
            foreach (preg_split('/[,;|]+/u',$line) ?: [] as $part) {
                $part=trim($part," \t•-");
                if (!preg_match('/^([\pL][\pL -]{1,35}?)(?:\s*[:–—-]\s*|\s*\(|\s+(?=native|fluent|basic|intermediate|advanced|proficient))?(native|fluent|basic|intermediate|advanced|proficient|excellent|good|fair|[ABC][12])?\)?$/iu',$part,$m)) continue;
                $language=trim($m[1]);
                if (preg_match('/speaking|reading|writing|language|proficiency|available/i',$language)) continue;
                $values=['language'=>$language]; if (!empty($m[2])) $values['proficiency']=$m[2];
                $items[]=$this->record($values,[$row]);
            }
        }
        return $items;
    }
    private function nameLike(string $line): bool
    {
        return preg_match('/^[\pL][\pL .\x{27}-]{3,100}$/u',$line) === 1
            && count(preg_split('/\s+/u',trim($line))) >= 2 && count(preg_split('/\s+/u',trim($line))) <= 7
            && !preg_match('/\b(nurse|hospital|resume|curriculum|vitae|summary|education|experience|skills|references|language|manager|supervisor|director|officer|contact|address|university|college|school|philippines|nationality|registered|professional|telephone|mobile|email|graduated|graduation|date|year)\b/iu',$line);
    }

    /**
     * LBS_GRADUATION_LABELS_20261003_V1
     * Preserve date precision and distinguish documented completion from expectation.
     * Unlabelled education dates are suggestions, never verified graduation facts.
     */
    private function graduationInfo(string $line, ?string $next = null, bool $educationContext = false): ?array
    {
        $line=trim(preg_replace('/[\s\p{Z}]+/u', ' ', $line) ?? $line);
        $labels='(?:(?:year|yr\.?|date)\s+(?:of\s+)?graduat(?:ed|ion)|graduation(?:\s+(?:date|year))?|graduated(?:\s+(?:on|in|date|year))?)';
        $negative='/\b(expected|anticipated|pending|projected|estimated|scheduled|target|not\s+(?:yet\s+)?graduated|did\s+not\s+graduate|will\s+graduate|graduating)\b/iu';
        $hasGraduation=preg_match('/\b(?:graduated|graduation|graduate|graduating)\b/iu',$line)===1;
        $value=null; $nextLine=false; $explicit=false; $blocked=false;
        if ($hasGraduation && preg_match($negative,$line)) {
            return ['value'=>$line, 'date'=>null, 'year'=>null, 'review_required'=>true, 'next_line'=>false];
        }
        if (preg_match('/^'.$labels.'(?=$|[\s:=\-–—])\s*[:=\-–—]?\s*(.*)$/iu',$line,$match)) {
            $value=trim($match[1]); $explicit=true;
            if ($value==='' && $next!==null) {
                $possible=trim($next);
                if ($this->date($possible)!==null || preg_match('~^\d{1,2}[/-]\d{1,2}[/-](?:19|20)\d{2}$~',$possible)
                    || (preg_match($negative,$possible) && preg_match('/\b(?:19|20)\d{2}\b/',$possible))) {
                    $value=$possible; $nextLine=true;
                }
            }
        } elseif (preg_match('/^(.+?)\s*(?:[-–—,:]|\()?\s*'.$labels.'\s*\)?$/iu',$line,$match)) {
            $value=trim($match[1], " \t-–—,:"); $explicit=true;
        } elseif ($educationContext && !$hasGraduation && !str_contains($line, ':')
            && !preg_match($negative,$line)
            && !preg_match('/\b(start|started|enrol(?:l)?ed|enrol(?:l)?ment|admission|birth|born|expiry|expiration|valid|until|license|licence)\b/iu',$line)) {
            // Only a bare date or a location followed by a date, next to an education record.
            if ($this->date($line)!==null) $value=$line;
            elseif (preg_match('/^[\pL][\pL .,\x{27}]{1,100}\s+[-–—]\s+(.+)$/u',$line,$match)
                && $this->date(trim($match[1]))!==null) $value=trim($match[1]);
        }
        if ($value===null) return null;
        $raw=$nextLine ? $line."\n".$value : $line;
        if ($value==='' || preg_match($negative,$value)) {
            return ['value'=>$raw, 'date'=>null, 'year'=>null, 'review_required'=>true, 'next_line'=>$nextLine];
        }
        $value=preg_replace('/^(?:on|in)\s+/iu','',$value) ?? $value;
        $value=preg_replace('/(?<=\d)(?:st|nd|rd|th)\b/iu','',$value) ?? $value;
        $date=$this->date($value); $year=$date!==null ? (int)substr($date,0,4) : null;
        // A written D/M/Y or M/D/Y may have an unambiguous year but ambiguous day/month.
        if ($date===null && preg_match('~^(\d{1,2})[/-](\d{1,2})[/-]((?:19|20)\d{2})$~',$value,$parts)
            && (checkdate((int)$parts[1],(int)$parts[2],(int)$parts[3]) || checkdate((int)$parts[2],(int)$parts[1],(int)$parts[3]))) {
            $year=(int)$parts[3];
        }
        return ['value'=>$raw, 'date'=>$date, 'year'=>$year,
            'review_required'=>!$explicit || $date===null, 'next_line'=>$nextLine];
    }

    public function date(string $value): ?string
    {
        $value=trim(preg_replace('/[\s\p{Z}]+/u', ' ', $value) ?? $value);
        $value=preg_replace('/(?<=\d)(?:st|nd|rd|th)\b/iu','',$value) ?? $value;
        if (preg_match('~^(\d{1,2})[/-](\d{1,2})[/-]((?:19|20)\d{2})$~',$value,$m)) {
            $a=(int)$m[1];$b=(int)$m[2];$y=(int)$m[3];$md=checkdate($a,$b,$y);$dm=checkdate($b,$a,$y);
            if ($md && $dm && $a!==$b) return null;
            return $md ? sprintf('%04d-%02d-%02d',$y,$a,$b) : ($dm ? sprintf('%04d-%02d-%02d',$y,$b,$a) : null);
        }
        $value=preg_replace('/,(?=\d{4}\b)/u', ', ', $value) ?? $value;
        $value=preg_replace('/^([A-Za-z]{3,9}),\s*((?:19|20)\d{2})$/u', '$1 $2', $value) ?? $value;
        if (preg_match('/^(?:19|20)\d{2}$/',$value)) return $value;
        $partial=preg_match('~^(?:(?:19|20)\d{2}-\d{1,2}|[A-Za-z]{3,9}\s+(?:19|20)\d{2}|\d{1,2}/(?:19|20)\d{2})$~',$value);
        $formats=$partial ? ['!Y-m','!F Y','!M Y','!m/Y']
            : ['!Y-m-d','!Y/m/d','!F j, Y','!F j Y','!j F Y','!M j, Y','!M j Y','!j M Y'];
        if (!$partial && !preg_match('~^(?:(?:19|20)\d{2}[-/]\d{1,2}[-/]\d{1,2}|[A-Za-z]{3,9}\s+\d{1,2},?\s+(?:19|20)\d{2}|\d{1,2}\s+[A-Za-z]{3,9}\s+(?:19|20)\d{2})$~',$value)) return null;
        foreach ($formats as $format) {
            $date=\DateTimeImmutable::createFromFormat($format,$value);
            $errors=\DateTimeImmutable::getLastErrors();
            if ($date && (int)$date->format('Y')>=1900 && (int)$date->format('Y')<=2099
                && ($errors===false || ($errors['warning_count']===0 && $errors['error_count']===0)))
                return $date->format($partial ? 'Y-m' : 'Y-m-d');
        }
        return null;
    }
    private function rangePattern(): string
    {
        $month='(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)';
        $date='(?:(?:19|20)\d{2}(?:-\d{2}(?:-\d{2})?)?|'.$month.'\s+(?:\d{1,2},?\s+)?(?:19|20)\d{2}|\d{1,2}/(?:19|20)\d{2})';
        return '~\b('.$date.')\s*(?:to|until|up to|[-–—])\s*(present|current|ongoing|'.$date.')\b~iu';
    }
    private function record(array $values, array $rows): array
    {
        $pages=array_values(array_unique(array_column($rows,'page'))); sort($pages);
        $provenance=[];
        foreach ($values as $key=>$value) {
            if ($value===null || $value==='') continue;
            $matches=array_values(array_filter($rows,fn($r)=>mb_stripos($r['text'],(string)$value)!==false));
            $support=$matches ?: $rows;
            $provenance[$key]=['source_pages'=>array_values(array_unique(array_column($support,'page'))),
                'source_excerpt'=>mb_substr(implode("\n",array_column($support,'text')),0,1200),
                'source_methods'=>array_values(array_unique(array_column($support,'method'))),
                'confidence'=>in_array('ocr',array_column($support,'method'),true) ? .7 : ($matches ? .82 : .68),
                'review_required'=>true,'evidence_scope'=>$matches ? 'matching_rows' : 'record_context'];
        }
        if (!empty($values['started_on'])) foreach (['ended_on','completed_on'] as $end) {
            if (!empty($values[$end]) && strcmp(substr($values['started_on'],0,min(strlen($values['started_on']),strlen($values[$end]))),substr($values[$end],0,min(strlen($values['started_on']),strlen($values[$end]))))>0) {
                $values['notes']=trim(($values['notes'] ?? '')."\nReview reversed date range: ".$values['started_on'].' - '.$values[$end]);
                unset($values['started_on'],$values[$end]);
            }
        }
        return array_filter($values,fn($v)=>$v!==null && $v!=='') + ['source_page'=>$pages[0] ?? 1,
            'field_provenance'=>$provenance,'normalization_version'=>'lbs-field-aware-v1','source_pages'=>$pages,'source_excerpt'=>mb_substr(implode("\n",array_column($rows,'text')),0,1200),
            'confidence'=>in_array('ocr',array_column($rows,'method'),true) ? .7 : .82,'review_status'=>'review_required'];
    }
    public function identity(array $record): string
    {
        foreach (['source_page','source_pages','source_excerpt','source_document_id','source_name','confidence','review_status','confirmed_at','confirmed_by','source_record_id','source_kind','field_provenance','normalization_version','source_method'] as $key) unset($record[$key]);
        ksort($record);
        return hash('sha256',mb_strtolower(json_encode($record,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }
}
