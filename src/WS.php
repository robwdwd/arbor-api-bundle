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
use SimpleXMLElement;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Access the Arbor Sightline REST and web services API.
 *
 * @author Rob Woodward <rob@emailplus.org>
 */
class WS extends API
{
    private $wsKey;
    private $url;

    private $cacheTtl;

    private $client;
    private $cache;

    /**
     * @param HttpClientInterface $client
     * @param CacheInterface      $cache
     * @param array               $config
     */
    public function __construct(HttpClientInterface $client, CacheItemPoolInterface $cache, array $config)
    {
        $this->url = 'https://'.$config['hostname'].'/arborws/';
        $this->wsKey = $config['wskey'];
        $this->client = $client;
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
        $url = $this->url.'/traffic/';

        $args = [
            'graph' => $graphXML,
            'query' => $queryXML,
        ];

        if (true === $this->shouldCache) {
            $cachedItem = $this->cache->getItem($this->getCacheKey($url, $args));
            if ($cachedItem->isHit()) {
                return $cachedItem->get();
            }
        }

        $output = $this->doHTTPRequest($url, $args);

        if ($this->hasError) {
            return;
        }

        $fileInfo = finfo_open();
        $mimeType = finfo_buffer($fileInfo, $output, FILEINFO_MIME_TYPE);

        if ('image/png' === $mimeType) {
            if (true === $this->shouldCache) {
                $cachedItem->expiresAfter($this->cacheTtl);
                $cachedItem->set($output);
                $this->cache->save($cachedItem);
            }

            return $output;
        }

        // If we get here theres been an error on the graph. Errors usually come
        // out as XML for traffic queries.
        //
        $outXML = new SimpleXMLElement($output);
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
     * @return SimpleXMLElement XML traffic data
     */
    public function getTrafficXML(string $queryXML)
    {
        $url = $this->url.'/traffic/';

        $args = [
            'query' => $queryXML,
        ];

        if (true === $this->shouldCache) {
            $cachedItem = $this->cache->getItem($this->getCacheKey($url, $args));

            if ($cachedItem->isHit()) {
                return new SimpleXMLElement($cachedItem->get());
            }
        }

        $output = $this->doHTTPRequest($url, $args);

        if ($this->hasError) {
            return;
        }

        // If we get here theres been an error on the graph. Errors usually come
        // out as XML for traffic queries.
        //
        $outXML = new SimpleXMLElement($output);
        if ($outXML->{'error-line'}) {
            foreach ($outXML->{'error-line'} as $error) {
                $this->errorMessage .= (string) $error."\n";
            }
            $this->hasError = true;

            return;
        }

        if (true === $this->shouldCache) {
            $cachedItem->expiresAfter($this->cacheTtl);
            $cachedItem->set($outXML->asXml());
            $this->cache->save($cachedItem);
        }

        return $outXML;
    }

    /**
     * Perform HTTP Web Services request against the sightline API.
     *
     * @param string $url
     * @param array  $args
     *
     * @return string Request output content
     */
    private function doHTTPRequest(string $url, array $args)
    {
        $this->hasError = false;
        $this->errorMessage = '';

        $args['api_key'] = $this->wsKey;

        try {
            $response = $this->client->request('GET', $url, ['query' => $args]);
            $content = $response->getContent();
        } catch (HttpExceptionInterface|DecodingExceptionInterface|TransportExceptionInterface $e) {
            $this->hasError = true;
            $this->errorMessage = $e->getMessage();

            return;
        }

        if (empty($content)) {
            $this->hasError = true;
            $this->errorMessage = 'Server returned no data.';

            return;
        }

        return $content;
    }

    /**
     * Get the cache key.
     *
     * @param string $url  URL to make the request against
     * @param array  $args URL args
     *
     * @return string cache key
     */
    private function getCacheKey(string $url, array $args)
    {
        return 'arbor_ws_'.sha1($url.http_build_query($args));
    }
}
