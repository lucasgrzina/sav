<?php

/*
 | Plausible total length (country calling code included, digits only) per country calling
 | code. Ranges are deliberately lax: they exist to catch obviously broken numbers before
 | calling the provider, not to reject valid ones. Extend only with confirmed ranges; a
 | calling code absent from this map falls back to the generic E.164 bounds (8-15 digits).
 |
 | Argentina keeps the mobile "9" marker (54 9 AREA NUMBER), hence 12-13.
 */
return [
    'e164_min' => 8,
    'e164_max' => 15,

    'by_calling_code' => [
        '54' => ['iso' => 'AR', 'min' => 12, 'max' => 13],
        '52' => ['iso' => 'MX', 'min' => 12, 'max' => 13],
        '56' => ['iso' => 'CL', 'min' => 11, 'max' => 11],
        '57' => ['iso' => 'CO', 'min' => 12, 'max' => 12],
        '51' => ['iso' => 'PE', 'min' => 11, 'max' => 11],
        '55' => ['iso' => 'BR', 'min' => 12, 'max' => 13],
    ],
];
