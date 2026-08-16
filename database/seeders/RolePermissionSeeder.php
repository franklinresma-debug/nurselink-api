<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissionNames = [
            'application.manage.own'=>'Manage own application','profile.manage.own'=>'Manage own professional profile','portfolio.verify'=>'Verify portfolio evidence','directory.view'=>'View member directory','credential.manage.own'=>'Manage own credentials','document.manage.own'=>'Manage own professional documents','professional_development.manage.own'=>'Manage own training and CPD',
            'qualification.view'=>'View qualification framework catalog','qualification.assessment.manage.own'=>'Manage own qualification readiness assessments','qualification.framework.manage'=>'Manage validated qualification rules and crosswalks',
            'application.review'=>'Review membership applications','document.extract.own'=>'Extract own registration documents','extraction.verify'=>'Verify extracted registration data','credential.verify'=>'Verify professional credentials','assessment.perform'=>'Perform competency assessments',
            'message.view.own'=>'View own NurseLink inbox','notification_preferences.manage.own'=>'Manage own notification preferences','event.view'=>'View events and seminars','event.register.own'=>'Register for events and seminars','broadcast.send'=>'Send member broadcasts','communication.template.manage'=>'Manage communication templates','communication.delivery.view'=>'View communication delivery status','event.manage'=>'Manage events and seminars','event.attendance.manage'=>'Manage event attendance','program.view'=>'View published programs, projects and advocacy','policy.view'=>'View published policy tracker','program.manage'=>'Manage programs, projects and advocacy','program.finance.manage'=>'Manage program and project budgets','policy.manage'=>'Manage policies and advocacy','organization.document.manage'=>'Manage organizational documents','analytics.view'=>'View aggregated NurseLink analytics','reports.export'=>'Export authorized operational reports','operations.view'=>'View production readiness and operational health','privacy.manage.own'=>'Submit and view own privacy requests','privacy.manage'=>'Manage privacy requests',
            'welfare.view.assigned'=>'View assigned welfare records','settings.manage'=>'Manage system settings','audit.view'=>'View audit logs','users.manage'=>'Manage user accounts','roles.assign'=>'Assign system roles',
        ];
        foreach ($permissionNames as $code=>$name) Permission::query()->firstOrCreate(['code'=>$code],['name'=>$name]);
        $roles=[
            'applicant'=>['Applicant',['message.view.own','notification_preferences.manage.own','application.manage.own','document.extract.own','privacy.manage.own']],
            'member'=>['Member',['privacy.manage.own','message.view.own','notification_preferences.manage.own','event.view','event.register.own','program.view','policy.view','profile.manage.own','directory.view','credential.manage.own','document.manage.own','professional_development.manage.own','qualification.view','qualification.assessment.manage.own']],
            'membership_officer'=>['Membership Officer',['directory.view','application.review','extraction.verify','portfolio.verify','credential.verify','qualification.view']],
            'assessor'=>['Assessor',['directory.view','assessment.perform','qualification.view']],
            'communications_officer'=>['Communications Officer',['directory.view','broadcast.send','communication.template.manage','communication.delivery.view','event.manage','event.attendance.manage','program.view','policy.view']],
            'program_policy_officer'=>['Program & Policy Officer',['directory.view','program.view','policy.view','program.manage','program.finance.manage','policy.manage','organization.document.manage','event.manage','event.attendance.manage']],
            'welfare_officer'=>['Welfare Officer',['directory.view','welfare.view.assigned']],
            'partner_viewer'=>['Partner Viewer',['program.view','policy.view']],
            'administrator'=>['Administrator',['privacy.manage.own','analytics.view','reports.export','operations.view','privacy.manage','message.view.own','notification_preferences.manage.own','event.view','event.register.own','directory.view','application.review','extraction.verify','portfolio.verify','credential.verify','assessment.perform','qualification.view','qualification.framework.manage','broadcast.send','communication.template.manage','communication.delivery.view','event.manage','event.attendance.manage','program.view','policy.view','program.manage','program.finance.manage','policy.manage','organization.document.manage','settings.manage','audit.view','users.manage','roles.assign']],
            'super_administrator'=>['Super Administrator',array_keys($permissionNames)],
        ];
        foreach($roles as $code=>[$name,$pcs]){ $role=Role::query()->firstOrCreate(['code'=>$code],['name'=>$name]); $role->permissions()->sync(Permission::query()->whereIn('code',$pcs)->pluck('id')->all()); }
    }
}
