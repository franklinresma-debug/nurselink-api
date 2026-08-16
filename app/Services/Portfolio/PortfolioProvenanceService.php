<?php
namespace App\Services\Portfolio;
class PortfolioProvenanceService
{
    public const STATES = ['self_declared','member_confirmed','document_supported','assessed','verified'];
    public function normalize(?string $state): string
    {
        return in_array($state,self::STATES,true) ? $state : 'self_declared';
    }
    public function rank(string $state): int { return array_search($this->normalize($state), self::STATES, true); }
}
