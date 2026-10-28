<?php

/*
|--------------------------------------------------------------------------
| Countries a nutritionist can work in
|--------------------------------------------------------------------------
|
| ISO 3166-1 alpha-2 => Arabic name. The one list the admin's country select
| and every validation reads: add a country here and it is available. 'PS' is
| the default, and the country foods.cost_tier belongs to.
|
*/

return [

    'default' => 'PS',

    'list' => [
        'PS' => 'فلسطين',
        'YE' => 'اليمن',
    ],

];
