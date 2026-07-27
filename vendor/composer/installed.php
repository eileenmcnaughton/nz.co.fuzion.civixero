<?php return array(
    'root' => array(
        'name' => 'civicrm/civixero',
        'pretty_version' => 'dev-master',
        'version' => 'dev-master',
        'reference' => '7bca17609584ef3f59fd48d3f12fa9809dc27222',
        'type' => 'civicrm-ext',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'civicrm/civixero' => array(
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'reference' => '7bca17609584ef3f59fd48d3f12fa9809dc27222',
            'type' => 'civicrm-ext',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'firebase/php-jwt' => array(
            'pretty_version' => 'v7.1.0',
            'version' => '7.1.0.0',
            'reference' => 'b374a5d1a4f1f67fadc2165cdb284645945e2fc0',
            'type' => 'library',
            'install_path' => __DIR__ . '/../firebase/php-jwt',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'guzzlehttp/guzzle' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '^6.3 || ^7.3',
            ),
        ),
        'guzzlehttp/psr7' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '^1.8.5',
            ),
        ),
        'league/oauth2-client' => array(
            'pretty_version' => '2.9.0',
            'version' => '2.9.0.0',
            'reference' => '26e8c5da4f3d78cede7021e09b1330a0fc093d5e',
            'type' => 'library',
            'install_path' => __DIR__ . '/../league/oauth2-client',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'xeroapi/xero-php-oauth2' => array(
            'pretty_version' => '16.0.0',
            'version' => '16.0.0.0',
            'reference' => '0c7e07640136e8695b64e516e722341800fea3c5',
            'type' => 'library',
            'install_path' => __DIR__ . '/../xeroapi/xero-php-oauth2',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
