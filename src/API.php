<?php
/*
 * This file is part of the Arbor API Bundle.
 *
 * Copyright 2021 Robert Woodward.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Robwdwd\ArborApiBundle;

use DomDocument;
use Robwdwd\ArborApiBundle\Exception\ArborApiException;
use SimpleXMLElement;

/**
 * Base API class for the ArborWS and ArborSOAP APIs.
 *
 * @abstract
 *
 * @author Rob Woodward <rob@emailplus.org>
 */
abstract class API
{
    private $shouldCache;

    /**
     * Get traffic XML from Arbor Sightline using the web services API.
     *
     * @param string $queryXML Query XML string
     *
     * @return SimpleXMLElement XML traffic data
     */
    abstract public function getTrafficXML(string $queryXML);

    /**
     * Get traffic graph from Arbor Sightline using the web services API.
     *
     * @param string $queryXML Query XML string
     * @param string $graphXML Graph format XML string
     *
     * @return string returns a PNG image
     */
    abstract public function getTrafficGraph(string $queryXML, string $graphXML);

    /**
     * Get Peer Managed object traffic graph from Arbor Sightline. This is a detail graph with in, out, total.
     *
     * @param int    $arborID   Arbor Managed Object ID
     * @param string $title     Title of the graph
     * @param string $startDate Start date for the graph
     * @param string $endDate   End date for the graph
     *
     * @return string returns a PNG image
     */
    public function getPeerTrafficGraph(int $arborID, string $title, string $startDate = '7 days ago', string $endDate = 'now')
    {
        $filters = [
            ['type' => 'peer', 'value' => $arborID, 'binby' => false],
        ];

        $queryXML = $this->buildQueryXML($filters, $startDate, $endDate, 'bps', ['in', 'out', 'total']);
        $graphXML = $this->buildGraphXML($title, 'bps', true);

        return $this->getTrafficGraph($queryXML, $graphXML);
    }

    /**
     * Get ASN traffic graph traffic graph from Arbor Sightline.
     *
     * @param string $asPath    AS Patch match string
     * @param string $startDate Start date for the graph
     * @param string $endDate   End date for the graph
     *
     * @return string returns a PNG image
     */
    public function getAsPathTrafficGraph(string $asPath, string $startDate = '7 days ago', string $endDate = 'now')
    {
        $filters = [
            ['type' => 'aspath', 'value' => $asPath, 'binby' => true],
        ];

        $queryXML = $this->buildQueryXML($filters, $startDate, $endDate);
        $graphXML = $this->buildGraphXML('Traffic with AS'.$asPath, 'bps [+ to / - from ]', false, 986, 270);

        return $this->getTrafficGraph($queryXML, $graphXML);
    }

    /**
     * Get ASN traffic stats from Arbor Sightline.
     *
     * @param string $asPath    AS path string
     * @param string $startDate Start date for the graph
     * @param string $endDate   End date for the graph
     *
     * @return SimpleXMLElement Traffic data XML
     */
    public function getAsPathTrafficXML(string $asPath, string $startDate = '7 days ago', string $endDate = 'now')
    {
        $filters = [
            ['type' => 'aspath', 'value' => $asPath, 'binby' => true],
        ];

        $queryXML = $this->buildQueryXML($filters, $startDate, $endDate);

        return $this->getTrafficXML($queryXML);
    }

    /**
     * Get interface traffic graph from Arbor Sightline.
     *
     * @param int    $arborID   Arbor Interface Object ID
     * @param string $title     Title of the graph
     * @param string $startDate Start date for the graph
     * @param string $endDate   End date for the graph
     *
     * @return string returns a PNG image
     */
    public function getIntfTrafficGraph(int $arborID, string $title, string $startDate = '7 days ago', string $endDate = 'now')
    {
        $filters = [
            ['type' => 'interface', 'value' => $arborID, 'binby' => false],
        ];

        $queryXML = $this->buildQueryXML($filters, $startDate, $endDate, 'bps', ['in', 'out', 'total', 'dropped', 'backbone']);
        $graphXML = $this->buildGraphXML($title, 'bps', true);

        return $this->getTrafficGraph($queryXML, $graphXML);
    }

    /**
     * Get ASN traffic graph broken down by interface from Arbor Sightline.
     *
     * @param string $asPath       AS Path string
     * @param array  $interfaceIds Array of interface IDs to filter on
     * @param string $title        Title of the graph
     * @param string $startDate    Start date for the graph
     * @param string $endDate      End date for the graph
     *
     * @return string returns a PNG image
     */
    public function getInterfaceAsPathTrafficGraph(
        string $asPath,
        array $interfaceIds,
        string $title,
        string $startDate = '7 days ago',
        string $endDate = 'now'
    ) {
        sort($interfaceIds, SORT_NUMERIC);

        $filters = [
            ['type' => 'interface', 'value' => $interfaceIds, 'binby' => true],
            ['type' => 'aspath', 'value' => $asPath, 'binby' => true],
        ];

        $queryXML = $this->buildQueryXML($filters, $startDate, $endDate);

        $graphXML = $this->buildGraphXML($title, 'bps (-In / +Out)', false, 986, 270);

        return $this->getTrafficGraph($queryXML, $graphXML);
    }

    /**
     * Get ASN traffic stats broken down by interface from Arbor Sightline.
     *
     * @param string $asPath       AS Path match string
     * @param array  $interfaceIds Array of interface IDs to filter on
     * @param string $startDate    Start date for the graph
     * @param string $endDate      End date for the graph
     *
     * @return SimpleXMLElement returns traffic data XML
     */
    public function getInterfaceAsPathTrafficXML(string $asPath, array $interfaceIds, string $startDate = '7 days ago', string $endDate = 'now')
    {
        sort($interfaceIds, SORT_NUMERIC);
        $filters = [
            ['type' => 'interface', 'value' => $interfaceIds, 'binby' => true],
            ['type' => 'aspath', 'value' => $asPath, 'binby' => false],
        ];

        $queryXML = $this->buildQueryXML($filters, $startDate, $endDate);

        return $this->getTrafficXML($queryXML);
    }

    /**
     * Get Interface traffic graph broken down by ASN from arbor Sigthline.
     *
     * @param int    $interfaceId Interface IDs to filter on
     * @param string $title       Title of the graph
     * @param string $startDate   Start date for the graph
     * @param string $endDate     End date for the graph
     *
     * @return string returns a PNG image
     */
    public function getIntfAsnTrafficGraph(int $interfaceId, string $title, string $startDate = '7 days ago', string $endDate = 'now')
    {
        $filters = [
            ['type' => 'interface', 'value' => $interfaceId, 'binby' => false],
            ['type' => 'as_origin', 'value' => null, 'binby' => true],
        ];

        $queryXML = $this->buildQueryXML($filters, $startDate, $endDate);

        $graphXML = $this->buildGraphXML($title, 'bps (-In / +Out)', false, 986, 270);

        return $this->getTrafficGraph($queryXML, $graphXML);
    }

    /**
     * Get interface traffic stats broken down by ASN from arbor Sightline.
     *
     * @param int    $interfaceId Interface IDs to filter on
     * @param string $startDate   Start date for the graph
     * @param string $endDate     End date for the graph
     *
     * @return SimpleXMLElement returns traffic data XML
     */
    public function getIntfAsnTrafficXML(int $interfaceId, string $startDate = '7 days ago', string $endDate = 'now')
    {
        $filters = [
            ['type' => 'interface', 'value' => $interfaceId, 'binby' => false],
            ['type' => 'as_origin', 'value' => null, 'binby' => true],
        ];

        $queryXML = $this->buildQueryXML($filters, $startDate, $endDate);

        return $this->getTrafficXML($queryXML);
    }

    /**
     * Build XML for querying the Web Services API.
     *
     * @param array  $filters   filters array
     * @param string $startDate start date/time for data
     * @param string $endDate   end date/time for data
     * @param string $unitType  Units of data to gather. bps or pps.
     * @param array  $classes   Classes of data to gather. in, out, total, backbone, dropped.
     *
     * @return string returns a XML string used to Query the WS API
     */
    public function buildQueryXML(array $filters, string $startDate = '7 days ago', string $endDate = 'now', string $unitType = 'bps', array $classes = [])
    {
        $queryXML = $this->getBaseXML();
        $baseNode = $queryXML->firstChild;

        // Create Query Node.
        $queryNode = $queryXML->createElement('query');
        $queryNode->setAttribute('type', 'traffic');
        $baseNode->appendChild($queryNode);

        // Create time Node.
        $timeNode = $queryXML->createElement('time');
        $timeNode->setAttribute('end_ascii', $endDate);
        $timeNode->setAttribute('start_ascii', $startDate);
        $queryNode->appendChild($timeNode);

        // Create unit node.
        $unitNode = $queryXML->createElement('unit');
        $unitNode->setAttribute('type', $unitType);
        $queryNode->appendChild($unitNode);

        // Create search node.
        $searchNode = $queryXML->createElement('search');
        $searchNode->setAttribute('timeout', 30);
        $searchNode->setAttribute('limit', 200);
        $queryNode->appendChild($searchNode);

        // Add the class nodes
        if (!empty($classes)) {
            foreach ($classes as $class) {
                $classNode = $queryXML->createElement('class', $class);
                $queryNode->appendChild($classNode);
            }
        }

        // Add the filters.
        if (!empty($filters)) {
            foreach ($filters as $filter) {
                if (isset($filter['type'])) {
                    $filterNode = $this->addQueryFilter($filter, $queryXML);
                    if ($filterNode) {
                        $queryNode->appendChild($filterNode);
                    }
                }
            }
        }

        return $queryXML->saveXML();
    }

    /**
     * Build XML for graph output.
     *
     * @param string $title  title of the graph
     * @param string $yLabel label for the Y-Axis on the graph
     * @param bool   $detail sets the graph to be a detail graph type when true
     * @param int    $width  graph width
     * @param int    $height graph height
     *
     * @return string returns a XML string used to configure the graph returned by the WS API
     */
    public function buildGraphXML(string $title, string $yLabel, $detail = false, int $width = 986, int $height = 180)
    {
        $graphXML = $this->getBaseXML();
        $baseNode = $graphXML->firstChild;

        $graphNode = $graphXML->createElement('graph');
        $graphNode->setAttribute('id', 'graph1');
        $baseNode->appendChild($graphNode);

        $graphNode->appendChild($graphXML->createElement('title', $title));
        $graphNode->appendChild($graphXML->createElement('ylabel', $yLabel));
        $graphNode->appendChild($graphXML->createElement('width', $width));
        $graphNode->appendChild($graphXML->createElement('height', $height));
        $graphNode->appendChild($graphXML->createElement('legend', 1));

        if (true === $detail) {
            $graphNode->appendChild($graphXML->createElement('type', 'detail'));
        }

        return $graphXML->saveXML();
    }

    /**
     * Turn the cache on or off.
     *
     * @param bool $cacheOn Cache or not
     */
    public function shouldCache(bool $cacheOn)
    {
        $this->shouldCache = $cacheOn;
    }

    /**
     * Handle error messages.
     *
     * @param string $output
     *
     * @return SimpleXMLElement
     */
    public function handleResult(string $output)
    {
        $outXML = new SimpleXMLElement($output);

                // If we get here theres been an error on the graph. Errors usually come
        // out as XML for traffic queries.
        //
        if ($outXML->{'error-line'}) {
            $errorMessage = '';
            foreach ($outXML->{'error-line'} as $error) {
                $errorMessage .= (string) $error."\n";
            }

            throw new ArborApiException($errorMessage);
        }

        return $outXML;
    }

    /**
     * Gets a base XML DOM document.
     *
     * @return DomDocument The DOM document to use as the base XML
     */
    private function getBaseXML()
    {
        $baseXML = new DomDocument('1.0', 'UTF-8');
        $baseXML->formatOutput = true;
        $peakflowNode = $baseXML->createElement('peakflow');
        $peakflowNode->setAttribute('version', '2.0');
        $baseXML->appendChild($peakflowNode);

        return $baseXML;
    }

    /**
     * Get a Dom Element for use in the Query XML.
     *
     * @param array       $filter the filter array to build the filter node for the XML
     * @param DomDocument $xmlDOM the DOMDocument object
     *
     * @return DomDocument the DOM element to include in the query XML
     */
    private function addQueryFilter(array $filter, $xmlDOM)
    {
        $filterNode = $xmlDOM->createElement('filter');
        $filterNode->setAttribute('type', $filter['type']);

        if (true === $filter['binby']) {
            $filterNode->setAttribute('binby', 1);
        }

        if (null !== $filter['value']) {
            if (is_array($filter['value'])) {
                foreach ($filter['value'] as $fvalue) {
                    $instanceNode = $xmlDOM->createElement('instance');
                    $instanceNode->setAttribute('value', $fvalue);
                    $filterNode->appendChild($instanceNode);
                }
            } else {
                $instanceNode = $xmlDOM->createElement('instance');
                $instanceNode->setAttribute('value', $filter['value']);
                $filterNode->appendChild($instanceNode);
            }
        }

        return $filterNode;
    }
}
