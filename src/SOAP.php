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

use Psr\Cache\CacheItemPoolInterface;
use Robwdwd\ArborApiBundle\Exception\ArborApiException;
use SimpleXMLElement;
use SoapClient;
use SoapFault;

/**
 * Access the Arbor Sightline SOAP API.
 *
 * @author Rob Woodward <rob@emailplus.org>
 */
class SOAP extends API
{
    private $hostname;
    private $username;
    private $password;
    private $wsdl;

    private $cache;
    private $cacheTtl;

    /**
     * @param CacheInterface $cache
     * @param array          $config
     */
    public function __construct(CacheItemPoolInterface $cache, array $config)
    {
        $this->hostname = $config['hostname'];
        $this->username = $config['username'];
        $this->password = $config['password'];
        $this->wsdl = $config['wsdl'];
        $this->cache = $cache;

        $this->shouldCache = $config['cache'];
        $this->cacheTtl = $config['cache_ttl'];
    }

    /**
     * Get traffic graph from Arbor Sightline using the SOAP API.
     *
     * @param string $queryXML Query XML string
     * @param string $graphXML Graph format XML string
     *
     * @return string returns a PNG image
     */
    public function getTrafficGraph(string $queryXML, string $graphXML)
    {
        if (true === $this->shouldCache) {
            $cachedItem = $this->cache->getItem($this->getCacheKey($queryXML.$graphXML));

            if ($cachedItem->isHit()) {
                return $cachedItem->get();
            }
        }

        $soapClient = $this->connect();

        try {
            $result = $soapClient->getTrafficGraph($queryXML, $graphXML);
        } catch (SoapFault $th) {
            throw new ArborApiException('Error getting traffic graph.', 0, $th);
        }

        $fileInfo = finfo_open();
        $mimeType = finfo_buffer($fileInfo, $result, FILEINFO_MIME_TYPE);

        if ('image/png' === $mimeType) {
            if (true === $this->shouldCache) {
                $cachedItem->expiresAfter($this->cacheTtl);
                $cachedItem->set($result);
                $this->cache->save($cachedItem);
            }

            return $result;
        }

        // If we get here theres been an error on the graph. Errors usually come
        // out as XML for traffic queries.
        //
        $this->handleResult($result);

    }

    /**
     * Get traffic XML from Arbor Sightline using the web services API.
     *
     * @param string $queryXML Query XML string
     *
     * @return SimpleXMLElement XML traffic data
     */
    public function getTrafficXML(string $queryXML)
    {
        if (true === $this->shouldCache) {
            $cachedItem = $this->cache->getItem($this->getCacheKey($queryXML));

            if ($cachedItem->isHit()) {
                return new SimpleXMLElement($cachedItem->get());
            }
        }

        $soapClient = $this->connect();

        try {
            $result = $soapClient->runXmlQuery($queryXML, 'xml');
        } catch (SoapFault $th) {
            throw new ArborApiException('Error getting traffic xml.', 0, $th);
        }

        $outXML = $this->handleResult($result);

        // If there is a valid result, store in cache.
        //
        if (true === $this->shouldCache) {
            $cachedItem->expiresAfter($this->cacheTtl);
            $cachedItem->set($outXML->asXml());
            $this->cache->save($cachedItem);
        }

        return $outXML;
    }

    /**
     * Run a CLI command on Arbor Sightline using the SOAP API.
     *
     * @param string $command The command string to run
     * @param int    $timeout timeout in seconds
     *
     * @return string|null returns the output from the CLI or null on error
     */
    public function cliRun(string $command, int $timeout = 20)
    {
        $soapClient = $this->connect();

        try {
            return $soapClient->cliRun($command, $timeout);
        } catch (SoapFault $th) {
            throw new ArborApiException('Error connecting to CLI.', 0, $th);
        }
    }

    /**
     * Connect to the Arbor Sightline SOAP API.
     *
     * @return SoapClient
     */
    public function connect()
    {
        $opts = [
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ];

        // SOAP 1.2 client
        $params = [
            'encoding' => 'UTF-8',
            'verifypeer' => false,
            'verifyhost' => false,
            'soap_version' => SOAP_1_2,
            'trace' => 1,
            'connection_timeout' => 180,
            'stream_context' => stream_context_create($opts),
            'location' => "https://$this->hostname/soap/sp",
            'login' => $this->username,
            'password' => $this->password,
            'authentication' => SOAP_AUTHENTICATION_DIGEST,
        ];

        try {
            return new SoapClient($this->wsdl, $params);
        } catch (SoapFault $th) {
            throw new ArborApiException('Unable to connect to Arbor API.', 0, $th);
        }
    }

    /**
     * Get the cache key.
     *
     * @param string $xml XML Query string
     *
     * @return string cache key
     */
    private function getCacheKey(string $xml)
    {
        return 'arbor_soap_'.sha1($xml);
    }
}
