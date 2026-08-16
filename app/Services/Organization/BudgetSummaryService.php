<?php
namespace App\Services\Organization;
use App\Models\Initiative;
class BudgetSummaryService { public function summarize(Initiative $i):array{$q=$i->budgetLines();$planned=(float)(clone $q)->sum('planned_amount');$committed=(float)(clone $q)->sum('committed_amount');$spent=(float)(clone $q)->sum('spent_amount');$budget=(float)($i->budget_total?:$planned);return ['budget_total'=>$budget,'planned'=>$planned,'committed'=>$committed,'spent'=>$spent,'available'=>round($budget-$spent,2),'utilization_percent'=>$budget>0?round(($spent/$budget)*100,2):0,'is_over_budget'=>$budget>0&&$spent>$budget];} }
