<?php
/*
 * This file is part of the Arbor API Bundle.
 *
 * Copyright 2022-2024 Robert Woodward
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Robwdwd\ArborApiBundle\Rest;

use Psr\Cache\CacheItemPoolInterface;
use Robwdwd\ArborApiBundle\Exception\ArborApiException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Access the Arbor Sightline REST API.
 *
 * @author Rob Woodward <rob@emailplus.org>
 */
class REST
{
    protected readonly string $url;

    protected $cacheKeyPrefix = 'arbor_rest';

    private readonly string $restToken;

    private bool $shouldCache = true;

    private readonly int $cacheTtl;

    /**
     * Contructor.
     *
     * @param HttpClientInterface $client
     * @param CacheInterface      $cache
     * @param array               $config Configuration
     */
    public function __construct(protected HttpClientInterface $client, protected CacheItemPoolInterface $cache, array $config)
    {
        $this->url = 'https://'.$config['hostname'].'/api/sp/';
        $this->restToken = $config['resttoken'];

        $this->shouldCache = $config['cache'];
        $this->cacheTtl = $config['cache_ttl'];
    }

    /**
     * Get an object by it's ID.
     *
     * @param string $endpoint Type of object to get. managed_object etc
     * @param string $arborID  Object ID
     *
     * @return array the output of the API call
     */
    public function getByID(string $endpoint, string $arborID)
    {
        $url = $this->url.$endpoint.'/'.$arborID;

        return $this->doGetRequest($url);
    }

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
    public function findRest(string $endpoint, array $filters = null, int $perPage = 50, $commitFlag = false)
    {
        $results = [];

        $apiResult = $this->doMultiGetRequest($endpoint, $filters, $perPage, $commitFlag);

        if (!$apiResult) {
            return $results;
        }

        foreach ($apiResult as $result) {
            foreach ($result['data'] as $r) {
                // Store result from API in results.
                $results[] = $r;
            }
        }

        return $results;
    }

    /**
     * Turn the cache on or off.
     *
     * @param bool $cacheOn Cache or not
     */
    public function setShouldCache(bool $cacheOn)
    {
        $this->shouldCache = $cacheOn;
    }

    /**
     * Perform a GET request against the API.
     *
     * @param string $url  URL to make the request against
     * @param array  $args Optional query arguments to append to the URL
     *
     * @return array The output of the API call
     */
    protected function doGetRequest(string $url, array $args = null)
    {
        $cachedItem = null;
        $options = [];

        if (null !== $args) {
            $options['query'] = $args;
        }

        if (true === $this->shouldCache) {
            $cachedItem = $this->cache->getItem($this->getCacheKey($url, $args));

            if ($cachedItem->isHit()) {
                return $cachedItem->get();
            }
        }

        $result = $this->getResult($this->connect('GET', $url, $options));

        // If there is a result, store in cache
        //
        if (true === $this->shouldCache) {
            $cachedItem->expiresAfter($this->cacheTtl);
            $cachedItem->set($result);
            $this->cache->save($cachedItem);
        }

        return $result;
    }

    /**
     * Perform multiple requests against the Arbor REST API.
     *
     * @param string $endpoint   endpoint to query against, see Arbor REST API documentation
     * @param ?array $filters
     * @param int    $perPage    Total number of objects per page. (Default 50)
     * @param bool   $commitFlag Add config=commited to endpoints which require it, default false
     *
     * @return array The output of the API call
     */
    protected function doMultiGetRequest(string $endpoint, array $filters = null, int $perPage = 50, $commitFlag = false)
    {
        $cachedItem = null;
        $url = $this->url.$endpoint.'/';

        if (null !== $filters) {
            $url .= '?'.$this->filterToUrl($filters);
        }

        // Get the cache here which will be the whole result set
        // without the pages.
        //
        if (true === $this->shouldCache) {
            $cachedItem = $this->cache->getItem($this->getCacheKey($url));

            if ($cachedItem->isHit()) {
                return $cachedItem->get();
            }
        }

        $args = ['perPage' => $perPage, 'page' => 1];

        if (true === $commitFlag) {
            $args['config'] = 'committed';
        }

        $totalPages = 1;

        $apiResult = [];

        // Do inital REST call to the API, this helps determine the number of
        // pages in the result. Turn off caching for this request.
        //
        $oldShouldCache = $this->shouldCache;
        $this->shouldCache = false;

        $apiResult[] = $this->doGetRequest($url, $args);

        $this->shouldCache = $oldShouldCache;

        //
        // Work out the number of pages.
        //
        if (isset($apiResult[0]['links']['last'])) {
            parse_str(parse_url((string) $apiResult[0]['links']['last'])['query'], $parsed);
            $totalPages = $parsed['page'];
        }

        // Make a request per page.
        //
        $responses = [];

        for ($currentPage = 2; $currentPage <= $totalPages; ++$currentPage) {
            $args['page'] = $currentPage;
            $responses[] = $this->connect('GET', $url, ['query' => $args]);
        }

        // Get the content from the request, ignore anything where the Response
        // is null (maybe timeout, etc)
        //
        foreach ($responses as $response) {
            if (null !== $response) {
                $apiResult[] = $this->getResult($response);
            }
        }

        // If caching is enabled add valid result to the cache.
        //
        if (true === $this->shouldCache) {
            $cachedItem->expiresAfter($this->cacheTtl);
            $cachedItem->set($apiResult);
            $this->cache->save($cachedItem);
        }

        return $apiResult;
    }

    /**
     * Perform a Curl request against the API.
     *
     * @param string $url      URL to make the request against
     * @param string $type     Type of post request, PATCH, POST
     * @param string $postData json data to send with the post request
     *
     * @return array The output of the API call
     */
    protected function doCachedPostRequest(string $url, string $type = 'POST', string $postData = null)
    {
        $cachedItem = null;
        if (true === $this->shouldCache) {
            $cachedItem = $this->cache->getItem($this->getPostCacheKey($url, $type, $postData));

            if ($cachedItem->isHit()) {
                return $cachedItem->get();
            }
        }

        $result = $this->doPostRequest($url, $type, $postData);

        // Store in cache
        //
        if (true === $this->shouldCache) {
            $cachedItem->expiresAfter($this->cacheTtl);
            $cachedItem->set($result);
            $this->cache->save($cachedItem);
        }

        return $result;
    }

    /**
     * Perform a Curl request against the API.
     *
     * @param string $url      URL to make the request against
     * @param string $type     Type of post request, PATCH, POST
     * @param string $postData json data to send with the post request
     *
     * @return array the output of the API call
     */
    protected function doPostRequest(string $url, string $type = 'POST', string $postData = null)
    {
        $options = [];

        $options['body'] = $postData;

        return $this->getResult($this->connect($type, $url, $options));
    }

    /**
     * Converts a filter into a valid URL.
     *
     * @param array $filters
     *
     * @return string Encoded URL string
     */
    protected function filterToUrl(array $filters)
    {
        if (isset($filters['type'])) {
            return 'filter='.$filters['type'].'/'.$filters['field'].'.'.$filters['operator'].'.'.$this->searchFilterToUrl($filters['search']);
        }
        $filterArgs = [];

        foreach ($filters as $filter) {
            if ('eq' !== $filter['operator'] and 'cn' !== $filter['operator']) {
                continue;
            }

            if ('a' !== $filter['type'] and 'r' !== $filter['type']) {
                continue;
            }
            $filterArgs[] = 'filter[]='.$filter['type'].'/'.$filter['field'].'.'.$filter['operator'].'.'.$this->searchFilterToUrl($filter['search']);
        }

        return implode('&', $filterArgs);
    }

    /**
     * Converts a search filter into a valid url encoded search string.
     *
     * @param mixed $search
     *
     * @return string Encoded URL string
     */
    private function searchFilterToUrl(mixed $search)
    {
        $searchUrl = [];

        if (is_array($search)) {
            foreach ($search as $term) {
                $searchUrl[] = urlencode((string) $term);
            }

            return implode('|', $searchUrl);
        }

        return urlencode((string) $search);
    }

    /**
     * Makes a connection to the Arbor API platform using HTTP Component.
     *
     * @param string $method  Request method, POST, PATCH, GET
     * @param string $url     URL to make the request against
     * @param array  $options HTTP Client component options
     *
     * @return ResponseInterface the HTTP Client Response Object
     */
    private function connect(string $method, string $url, array $options = [])
    {
        $options['headers'] =
            [
                'Content-Type: application/vnd.api+json',
                'X-Arbux-APIToken: '.$this->restToken,
            ];

        try {
            return $this->client->request($method, $url, $options);
        } catch (ExceptionInterface $e) {
            throw new ArborApiException('Error connecting to the server.', 0, $e);
        }
    }

    /**
     * Get's the returned content from the request.
     *
     * @param object $response a Valid HTTP Client reponse object
     *
     * @return array the response from the server as an array
     */
    private function getResult(ResponseInterface $response)
    {
        // check the response object is valid.
        //
        if (!$response) {
            throw new ArborApiException('Invalid response object from HTTP client.');
        }

        // Get the content.
        try {
            $apiResult = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new ArborApiException('Error getting result from server.', 0, $e);
        }

        if (empty($apiResult)) {
            throw new ArborApiException('API server returned no data.');
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode >= 300) {
            $errorMessage = 'API server returned status code: '.$statusCode;

            if (isset($apiResult['errors']) && !empty($apiResult['errors'])) {
                $errorMessage .= $this->findError($apiResult['errors']);
            }

            throw new ArborApiException($errorMessage);
        }

        return $apiResult;
    }

    /**
     * Find an error in the results of the REST API which gave an Error.
     *
     * @param array $errors an array of errors returned by the API
     */
    private function findError(array $errors)
    {
        $errorMessages = '';
        foreach ($errors as $error) {
            if (isset($error['id'])) {
                $errorMessages .= $error['id']."\n ";
            }
            if (isset($error['message'])) {
                $errorMessages .= $error['message']."\n ";
            }
            if (isset($error['title'])) {
                $errorMessages .= $error['title']."\n ";
            }
            if (isset($error['detail'])) {
                if (isset($error['source']['pointer'])) {
                    $errorMessages .= $error['detail'].' : '.$error['source']['pointer']."\n ";
                } else {
                    $errorMessages .= $error['detail']."\n ";
                }
            }
        }

        return $errorMessages;
    }

    /**
     * Get the cache Key.
     *
     * @param string     $url  URL to make the request against
     * @param array|null $args URL args
     *
     * @return string cache key
     */
    private function getCacheKey(string $url, array $args = null)
    {
        if (null === $args) {
            return $this->cacheKeyPrefix.'_'.sha1($url);
        }

        return $this->cacheKeyPrefix.'_'.sha1($url.http_build_query($args));
    }

    /**
     * Get the cache Key.
     *
     * @param string $url      URL to make the request against
     * @param string $type
     * @param mixed  $postData
     *
     * @return string cache key
     */
    private function getPostCacheKey(string $url, string $type, string $postData)
    {
        return $this->cacheKeyPrefix.'_'.sha1($url.$type.$postData);
    }
}
