<?php
return [
    'readiness_bands' => [
        ['min' => 90, 'label' => 'strong_evidence_readiness'],
        ['min' => 75, 'label' => 'ready_with_minor_gaps'],
        ['min' => 50, 'label' => 'developing_evidence_profile'],
        ['min' => 0,  'label' => 'significant_evidence_gaps'],
    ],
    'trust_levels' => [
        'unsupported' => 0,
        'self_declared' => 1,
        'member_confirmed' => 2,
        'document_supported' => 3,
        'assessed' => 4,
        'verified' => 5,
    ],
    'disclaimer' => 'NurseLink readiness results are evidence-guidance tools. They are not an official qualification award, legal equivalence determination, professional license, or immigration decision.',
];
