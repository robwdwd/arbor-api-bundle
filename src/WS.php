<?php
/*
 * This file is part of the Arbor API Bundle.
 *
 * Copyright 2022 Robert Woodward
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Robwdwd\ArborApiBundle;

use Psr\Cache\CacheItemPoolInterface;
use Robwdwd\ArborApiBundle\Exception\ArborApiException;
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

        $this->handleResult($output);
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

        $outXML = $this->handleResult($this->doHTTPRequest($url, $args));

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
        $args['api_key'] = $this->wsKey;

        try {
            $response = $this->client->request('GET', $url, ['query' => $args]);
            $content = $response->getContent();
        } catch (HttpExceptionInterface|DecodingExceptionInterface|TransportExceptionInterface $e) {
            throw new ArborApiException('Error in HTTP request', 0, $e);
        }

        if (empty($content)) {
            throw new ArborApiException('API Server returned no data.');
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
