<?php

// Only the rules the panel can actually put in front of somebody. A rule
// that no form can trigger is left out on purpose: an unused line here is a
// line nobody ever proof-reads.
return [
    'array' => ':attribute deve essere un elenco.',
    'between' => [
        'numeric' => ':attribute deve essere tra :min e :max.',
    ],
    'confirmed' => 'La conferma di :attribute non corrisponde.',
    'current_password' => 'La password non è corretta.',
    'distinct' => ':attribute contiene un valore duplicato.',
    'email' => ':attribute deve essere un indirizzo email valido.',
    'enum' => 'Il valore selezionato per :attribute non è valido.',
    'exists' => 'Il valore selezionato per :attribute non è valido.',
    'in' => 'Il valore selezionato per :attribute non è valido.',
    'integer' => ':attribute deve essere un numero intero.',
    'max' => [
        'string' => ':attribute non può superare :max caratteri.',
    ],
    'min' => [
        'array' => ':attribute deve contenere almeno :min elementi.',
        'string' => ':attribute deve contenere almeno :min caratteri.',
    ],
    'password' => [
        'letters' => ':attribute deve contenere almeno una lettera.',
        'mixed' => ':attribute deve contenere almeno una maiuscola e una minuscola.',
        'numbers' => ':attribute deve contenere almeno un numero.',
        'symbols' => ':attribute deve contenere almeno un simbolo.',
        'uncompromised' => ':attribute compare in una violazione di dati: scegline un\'altra.',
    ],
    'regex' => 'Il formato di :attribute non è valido.',
    'required' => 'Il campo :attribute è obbligatorio.',
    'required_if' => 'Il campo :attribute è obbligatorio quando :other è :value.',
    'required_with' => 'Il campo :attribute è obbligatorio quando :values è presente.',
    'required_without' => 'Il campo :attribute è obbligatorio quando :values non è presente.',
    'string' => ':attribute deve essere un testo.',
    'unique' => ':attribute è già in uso.',
    'url' => ':attribute deve essere un URL valido.',

    // The wizard validates a nested payload, so its fields reach the
    // validator as "application.name" and "environments.0.horizonUrl". Both
    // shapes are spelled out: without the nested ones the message would
    // print the raw path, which is how the field names leaked into the
    // Italian interface in the first place.
    'attributes' => [
        'application.host' => 'host',
        'application.name' => 'nome',
        'basicAuthPassword' => 'password basic auth',
        'basicAuthUser' => 'username basic auth',
        'color' => 'colore',
        'email' => 'email',
        'environmentIds' => 'ambienti',
        'environmentIds.*' => 'ambiente',
        'environments' => 'ambienti',
        'environments.*.basicAuthPassword' => 'password basic auth',
        'environments.*.basicAuthUser' => 'username basic auth',
        'environments.*.color' => 'colore',
        'environments.*.horizonUrl' => 'URL Horizon',
        'environments.*.name' => 'nome ambiente',
        'environments.*.pollIntervalSeconds' => 'intervallo di polling',
        'horizonUrl' => 'URL Horizon',
        'host' => 'host',
        'locale' => 'lingua',
        'name' => 'nome',
        'organization' => 'nome organizzazione',
        'password' => 'password',
        'pollIntervalSeconds' => 'intervallo di polling',
        'role' => 'ruolo',
        'visibility' => 'visibilità',
    ],
];
