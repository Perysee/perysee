<?php

return [
    'ssr' => ['enabled' => false],
    'pages' => ['ensure_pages_exist' => false, 'paths' => [resource_path('js/Pages')], 'extensions' => ['vue']],
    'testing' => ['ensure_pages_exist' => true],
];
