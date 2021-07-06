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

use Symfony\Contracts\Cache\CacheInterface;

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
     * @param string         $hostname  Hostname of the Arbor SP Leader
     * @param string         $restToken REST API Key
     * @param CacheInterface $cache
     * @param array          $config
     */
    public function __construct(CacheInterface $cache, array $config)
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
     * Get traffic graph from Arbor Sightline using the web services API.
     *
     * @param string $queryXML Query XML string
     * @param string $graphXML Graph format XML string
     *
     * @return string returns a PNG image
     */
    public function getTrafficGraph(string $queryXML, string $graphXML)
    {
        $this->errorMessage = '';
        $this->hasError = false;

        if (true === $this->shouldCache) {
            $cachedItem = $this->cache->getItem($this->getCacheKey($queryXML.$graphXML));

            if ($cachedItem->isHit()) {
                return $cachedItem->get();
            }
        }

        $soapClient = $this->connect();

        // If there is an error from connecting return
        //
        if ($this->hasError) {
            return;
        }

        $result = $soapClient->getTrafficGraph($queryXML, $graphXML);

        if (is_soap_fault($result)) {
            $this->errorMessage = "SOAP Fault: (faultcode: ($result->faultcode), faultstring: ($result->faultstring))";
            $this->hasError = true;

            return;
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
        $outXML = new \SimpleXMLElement($output);
        if ($outXML->{'error-line'}) {
            foreach ($outXML->{'error-line'} as $error) {
                $this->errorMessage .= (string) $error."\n";
            }
            $this->hasError = true;

            return;
        }
    }

    /**
     * Get traffic XML from Arbor Sightline using the web services API.
     *
     * @param string $queryXML Query XML string
     *
     * @return string XML traffic data
     */
    public function getTrafficXML(string $queryXML)
    {
        $this->errorMessage = '';
        $this->hasError = false;

        if (true === $this->shouldCache) {
            $cachedItem = $this->cache->getItem($this->getCacheKey($queryXML));

            if ($cachedItem->isHit()) {
                return new \SimpleXMLElement($cachedItem->get());
            }
        }

        $soapClient = $this->connect();

        // If there is an error from connecting return
        //
        if ($this->hasError) {
            return;
        }

        $result = $soapClient->runXmlQuery($queryXML, 'xml');

        if (is_soap_fault($result)) {
            $this->errorMessage = "SOAP Fault: (faultcode: ($result->faultcode), faultstring: ($result->faultstring))";
            $this->hasError = true;

            return;
        }

        // If we get here theres been an error on the graph. Errors usually come
        // out as XML for traffic queries.
        //
        $outXML = new \SimpleXMLElement($result);

        if ($outXML->{'error-line'}) {
            foreach ($outXML->{'error-line'} as $error) {
                $this->errorMessage .= (string) $error."\n";
            }
            $this->hasError = true;

            return;
        }

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
        $this->errorMessage = '';
        $this->hasError = false;

        $soapClient = $this->connect();

        // If there is an error from connecting return
        //
        if ($this->hasError) {
            return;
        }

        $result = $soapClient->cliRun($command, $timeout);

        if (is_soap_fault($result)) {
            $this->errorMessage = "SOAP Fault: (faultcode: ($result->faultcode), faultstring: ($result->faultstring))";
            $this->hasError = true;

            return;
        }

        return $result;
    }

    /**
     * Connect to the Arbor Sightline SOAP API.
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
            'exceptions' => false,
            'connection_timeout' => 180,
            'stream_context' => stream_context_create($opts),
            'location' => "https://$this->hostname/soap/sp",
            'login' => $this->username,
            'password' => $this->password,
            'authentication' => SOAP_AUTHENTICATION_DIGEST,
        ];

        $client = new \SoapClient($this->wsdl, $params);

        if (is_soap_fault($client)) {
            $this->errorMessage = "SOAP Fault: (faultcode: ($client->faultcode), faultstring: ($client->faultstring))";
            $this->hasError = true;

            return;
        }

        return $client;
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
