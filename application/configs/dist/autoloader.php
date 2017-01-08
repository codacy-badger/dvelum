<?php

return [
    // PSR-0 autoload paths
    'paths' => [
        './application/controllers',
        './application/models',
        './application/library',
        './dvelum/app',
        './dvelum/library',
        './dvelum2',
        './vendor',
        './vendor/psr/log'
    ],
    // paths priority (cannot be overridden by external modules)
    'priority'=>[
        './application/controllers',
        './application/models',
        './application/library',
    ],
    // Use class maps
    'useMap' => false,
    // Use class map (Reduce IO load during autoload)
    // Class map file path (string / false)
    'map' => 'classmap.php',
    // PSR-4 autoload paths
    'psr-4' =>[
        'Zend\\Stdlib' => './vendor/zendframework/zend-stlib/src',
        'Zend\\Db' => './vendor/zendframework/zend-db/src'
    ]

];