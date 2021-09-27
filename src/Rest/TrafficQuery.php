<?php
/*
 * This file is part of the Arbor API Bundle.
 *
 * Copyright 2021 Robert Woodward.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Robwdwd\ArborApiBundle\Rest;

/**
 * Access the Arbor Sightline REST API for the traffic queries endpoint.
 *
 * @author Rob Woodward <rob@emailplus.org>
 */
class TrafficQuery extends REST
{
    protected $cacheKeyPrefix = 'arbor_rest_tquery';

    /**
     * Get ASN traffic stats broken down by interface from Arbor Sightline.
     *
     * @param int    $asn        AS number
     * @param array  $interfaces Array of interface IDs to filter on
     * @param string $startDate  Start date for the graph
     * @param string $endDate    End date for the graph
     *
     * @return array returns traffic data
     */
    public function getASNIntfTraffic(int $asn, array $interfaces, string $startDate = '7 days ago', string $endDate = 'now')
    {
        $url = $this->url.'/traffic_queries/';

        sort($interfaces, SORT_NUMERIC);
        $filters = [
            ['facet' => 'Interface', 'values' => $interfaces, 'groupby' => true],
            ['facet' => 'AS_Path', 'values' => ['_'.$asn.'_'], 'groupby' => false],
        ];

        $queryJson = $this->buildJson($filters, $startDate, $endDate);

        // Send the API request.
        //
        return $this->doPostRequest($url, 'POST', $queryJson);
    }

    private function buildJson($filters, $start, $end, string $unit = 'bps', $limit = 100, array $trafficClasses = ['in', 'out'])
    {
        $start = new \Datetime($start);
        $end = new \Datetime($end);

        $query = ['data' => ['attributes' => [
            'query_start_time' => $start->format('c'),
            'query_end_time' => $end->format('c'),
            'unit' => $unit,
            'limit' => $limit,
            'traffic_classes' => $trafficClasses,
            'filters' => $filters,
        ],
        ],
        ];

        return json_encode($query);
    }
}
