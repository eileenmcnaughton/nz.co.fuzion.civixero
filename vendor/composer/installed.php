<?php return array(
    'root' => array(
        'name' => 'civicrm/civixero',
        'pretty_version' => 'dev-master',
        'version' => 'dev-master',
        'reference' => '49119a4fd27bec169289797da6e729b34a11bb1a',
        'type' => 'civicrm-ext',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'civicrm/civixero' => array(
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'reference' => '49119a4fd27bec169289797da6e729b34a11bb1a',
            'type' => 'civicrm-ext',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'firebase/php-jwt' => array(
            'pretty_version' => 'v6.11.1',
            'version' => '6.11.1.0',
            'reference' => 'd1e91ecf8c598d073d0995afa8cd5c75c6e19e66',
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
            'pretty_version' => '10.4.0',
            'version' => '10.4.0.0',
            'reference' => '65888fa19484c10c62b6a36d9c49d73a3cb4f8d6',
            'type' => 'library',
            'install_path' => __DIR__ . '/../xeroapi/xero-php-oauth2',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
