<?php
/*
 * This file is part of the Arbor API Bundle.
 *
 * Copyright 2022 Robert Woodward
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Robwdwd\ArborApiBundle\Rest;

/**
 * Access the Arbor Sightline REST API.
 *
 * @author Rob Woodward <rob@emailplus.org>
 */
class Paged extends REST
{
    protected $cacheKeyPrefix = 'arbor_rest_paged';

    private int $currentPage = 1;

    private int $totalPages = 0;

    private $currentData;

    /**
     * Find or search Arbor SP REST API for a particular record or set of
     * records.
     *
     * @param string $endpoint   Endpoint type, Managed Object, Mitigations etc.
     *                           See Arbor API documenation for endpoint list.
     * @param array  $filters    Search filters
     * @param int    $perPage    Limit the number of returned objects per page, default 50
     * @param bool   $commitFlag Add config=commited to endpoints which require it, default false
     *
     * @return array returns an array with the records from the API
     */
    public function findRestPaged(string $endpoint, array $filters = null, int $perPage = 50, $commitFlag = false)
    {
        $url = $this->url.$endpoint.'/';

        if (null !== $filters) {
            $url .= '?'.$this->filterToUrl($filters);
        }

        $args = ['perPage' => $perPage, 'page' => $this->currentPage];

        if (true === $commitFlag) {
            $args['config'] = 'committed';
        }

        $this->currentData = $this->doGetRequest($url, $args);

        //
        // Work out the number of pages.
        //
        if (isset($apiResult[0]['links']['last'])) {
            parse_str(parse_url((string) $apiResult[0]['links']['last'])['query'], $parsed);
            $this->totalPages = $parsed['page'];
        }
    }
}
