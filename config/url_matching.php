<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tracking query parameters
    |--------------------------------------------------------------------------
    |
    | Query parameters dropped when building the normalised match key for a URL.
    | Values here are matched against the full parameter name.
    |
    | Set URL_MATCHING_TRACKING_PARAMS_EXTRA to a comma separated list to APPEND
    | to these defaults. After changing it, run `php artisan urls:renormalize`
    | to rebuild the stored match keys, otherwise existing rows keep the old
    | key and matching silently misses.
    |
    */

    'tracking_params' => array_merge([
        '_gl', 'aff', 'affid', 'algo_pvid', 'dclid', 'epik', 'fbclid', 'gbraid',
        'gclid', 'igshid', 'irclickid', 'keywords', 'mc_cid', 'mc_eid', 'msclkid',
        'psc', 'qid', 'ref', 'spm', 'sr', 'srsltid', 'tag', 'th', 'ttclid',
        'twclid', 'wbraid', 'yclid',
    ], explode(',', (string) env('URL_MATCHING_TRACKING_PARAMS_EXTRA', ''))),

    /*
    |--------------------------------------------------------------------------
    | Tracking query parameter prefixes
    |--------------------------------------------------------------------------
    |
    | Any parameter whose name starts with one of these is dropped. Appended to
    | with URL_MATCHING_TRACKING_PARAM_PREFIXES_EXTRA.
    |
    */

    'tracking_param_prefixes' => array_merge([
        '_bta', 'aff_', 'affiliate', 'pd_rd_', 'ref_', 'utm_',
    ], explode(',', (string) env('URL_MATCHING_TRACKING_PARAM_PREFIXES_EXTRA', ''))),

];
