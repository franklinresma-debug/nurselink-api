<?php
namespace Database\Seeders;
use App\Models\QualificationFramework; use Illuminate\Database\Seeder;
class QualificationFrameworkSeeder extends Seeder
{
    public function run():void
    {
        $catalog=[
            ['code'=>'PH-PQF','name'=>'Philippine Qualifications Framework','scope'=>'national','jurisdiction'=>'PH','level_count'=>8,'description'=>'National qualifications framework reference catalog.','governance_status'=>'reference_only'],
            ['code'=>'ASEAN-AQRF','name'=>'ASEAN Qualifications Reference Framework','scope'=>'regional','jurisdiction'=>'ASEAN','level_count'=>8,'description'=>'Regional reference framework catalog.','governance_status'=>'reference_only'],
            ['code'=>'SA-REFERENCE','name'=>'Saudi Arabia Qualification Framework Reference','scope'=>'destination','jurisdiction'=>'SA','description'=>'Destination-country framework catalog; detailed mappings require governance validation.','governance_status'=>'reference_only'],
            ['code'=>'AE-REFERENCE','name'=>'United Arab Emirates Qualification Framework Reference','scope'=>'destination','jurisdiction'=>'AE','description'=>'Destination-country framework catalog; detailed mappings require governance validation.','governance_status'=>'reference_only'],
            ['code'=>'QA-REFERENCE','name'=>'Qatar Qualification Framework Reference','scope'=>'destination','jurisdiction'=>'QA','description'=>'Destination-country framework catalog; detailed mappings require governance validation.','governance_status'=>'reference_only'],
            ['code'=>'KW-REFERENCE','name'=>'Kuwait Qualification Framework Reference','scope'=>'destination','jurisdiction'=>'KW','description'=>'Destination-country framework catalog; detailed mappings require governance validation.','governance_status'=>'reference_only'],
            ['code'=>'OM-REFERENCE','name'=>'Oman Qualification Framework Reference','scope'=>'destination','jurisdiction'=>'OM','description'=>'Destination-country framework catalog; detailed mappings require governance validation.','governance_status'=>'reference_only'],
            ['code'=>'SG-REFERENCE','name'=>'Singapore Qualification Framework Reference','scope'=>'destination','jurisdiction'=>'SG','description'=>'Destination-country framework catalog; detailed mappings require governance validation.','governance_status'=>'reference_only'],
            ['code'=>'NZ-REFERENCE','name'=>'New Zealand Qualification Framework Reference','scope'=>'destination','jurisdiction'=>'NZ','description'=>'Destination-country framework catalog; detailed mappings require governance validation.','governance_status'=>'reference_only'],
            ['code'=>'EU-REFERENCE','name'=>'European Qualifications Framework Reference','scope'=>'regional','jurisdiction'=>'EU','description'=>'European framework reference catalog; mappings require governance validation.','governance_status'=>'reference_only'],
        ];
        foreach($catalog as $data){$framework=QualificationFramework::query()->firstOrCreate(['code'=>$data['code']],array_merge($data,['assessment_enabled'=>false,'governance_note'=>'Reference metadata only. NurseLink does not infer equivalence from matching level numbers.']));if(in_array($framework->code,['PH-PQF','ASEAN-AQRF'],true)&&$framework->levels()->count()===0){for($i=1;$i<=8;$i++)$framework->levels()->create(['ordinal'=>$i,'code'=>'L'.$i,'title'=>'Level '.$i,'descriptors'=>[]]);}}
    }
}
