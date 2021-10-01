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
     * Get AS Path traffic stats broken down by interface from Arbor Sightline.
     *
     * @param string $asPath     AS path match
     * @param array  $interfaces Array of interface IDs to filter on
     * @param string $startDate  Start date for the graph
     * @param string $endDate    End date for the graph
     *
     * @return array Traffic Data
     */
    public function getInterfaceAsPathTraffic(string $asPath, array $interfaces, string $startDate = '7 days ago', string $endDate = 'now')
    {
        $url = $this->url.'/traffic_queries/';

        sort($interfaces, SORT_NUMERIC);
        $filters = [
            ['facet' => 'Interface', 'values' => $interfaces, 'groupby' => true],
            ['facet' => 'AS_Path', 'values' => [$asPath], 'groupby' => true],
        ];

        $queryJson = $this->buildTrafficQueryJson($filters, $startDate, $endDate);

        return $this->doCachedPostRequest($url, 'POST', $queryJson);
    }

    /**
     * Get interface traffic stats broken down by AS Origin from arbor Sightline.
     *
     * @param string $interfaceId Interface IDs to filter on
     * @param string $startDate   Start date for the graph
     * @param string $endDate     End date for the graph
     *
     * @return array Traffic data
     */
    public function getInterfaceAsnTraffic(string $interfaceId, string $startDate = '7 days ago', string $endDate = 'now')
    {
        $url = $this->url.'/traffic_queries/';

        $filters = [
            ['facet' => 'Interface', 'values' => [$interfaceId], 'groupby' => false],
            ['facet' => 'AS_Origin', 'values' => [], 'groupby' => true],
        ];

        $queryJson = $this->buildTrafficQueryJson($filters, $startDate, $endDate);

        return $this->doCachedPostRequest($url, 'POST', $queryJson);
    }

    /**
     * Get AS Path traffic stats from Arbor Sightline.
     *
     * @param string $asPath    AS Path match
     * @param string $startDate Start date for the graph
     * @param string $endDate   End date for the graph
     *
     * @return array Traffic data
     */
    public function getAsPathTraffic(string $asPath, string $startDate = '7 days ago', string $endDate = 'now')
    {
        $url = $this->url.'/traffic_queries/';

        $filters = [
            ['facet' => 'AS_Path', 'values' => [$asPath], 'groupby' => true],
        ];

        $queryJson = $this->buildTrafficQueryJson($filters, $startDate, $endDate);

        return $this->doCachedPostRequest($url, 'POST', $queryJson);
    }

    /**
     * Get Peer managed object traffic stats from Arbor Sightline.
     *
     * @param string $peerId    Peer managed object ID
     * @param string $startDate Start date for the graph
     * @param string $endDate   End date for the graph
     *
     * @return array Traffic data
     */
    public function getPeerTraffic(string $peerId, string $startDate = '7 days ago', string $endDate = 'now')
    {
        $url = $this->url.'/traffic_queries/';

        $filters = [
            ['facet' => 'Peer', 'values' => [$peerId], 'groupby' => true],
        ];

        $queryJson = $this->buildTrafficQueryJson($filters, $startDate, $endDate, 'bps', 100, ['in', 'out', 'total']);

        return $this->doCachedPostRequest($url, 'POST', $queryJson);
    }

    /**
     * Get interface traffic stats from Arbor Sightline.
     *
     * @param string $interfaceId Interface ID
     * @param string $startDate   Start date for the graph
     * @param string $endDate     End date for the graph
     *
     * @return array Traffic data
     */
    public function getInterfaceTraffic(string $interfaceId, string $startDate = '7 days ago', string $endDate = 'now')
    {
        $url = $this->url.'/traffic_queries/';

        $filters = [
            ['facet' => 'Interface', 'values' => [$interfaceId], 'groupby' => true],
        ];

        $queryJson = $this->buildTrafficQueryJson($filters, $startDate, $endDate, 'bps', 100, ['in', 'out', 'total', 'dropped']);

        return $this->doCachedPostRequest($url, 'POST', $queryJson);
    }

    public function buildTrafficQueryJson($filters, $start, $end, string $unit = 'bps', $limit = 100, array $trafficClasses = ['in', 'out'])
    {
        $start = new \Datetime($start);
        $end = new \Datetime($end);

        // Round down to the nearest hour.
        $start->setTime($start->format('H'), 0);
        $end->setTime($end->format('H'), 0);

        $query = [
            'data' => [
                'attributes' => [
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
