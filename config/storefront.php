<?php

return [
    /*
    | The platform domain that hosts seller subdomains (<shop>.<domain>).
    | Locally the Playwright suites map *.scalenowbd.test to 127.0.0.1 and
    | set STOREFRONT_DOMAIN=scalenowbd.test in the local .env.
    */
    'domain' => env('STOREFRONT_DOMAIN', 'scalenowbd.com'),

    /* Fixed server IP sellers point their root (apex) A record at. */
    'server_ip' => env('STOREFRONT_SERVER_IP', '151.158.44.86'),

    /* CNAME target for a custom domain's www record. */
    'cname_target' => env('STOREFRONT_CNAME_TARGET', 'scalenowbd.com'),
];
