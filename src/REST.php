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
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Access the Arbor Sightline REST API.
 *
 * @author Rob Woodward <rob@emailplus.org>
 */
class REST
{
    private $url;
    private $restToken;
    private $client;
    private $cache;

    private $shouldCache;
    private $cacheTtl;

    private $hasError = false;
    private $errorMessages = [];

    /**
     * Contructor.
     *
     * @param HttpClientInterface $client
     * @param CacheInterface      $cache
     * @param array               $config Configuration
     */
    public function __construct(HttpClientInterface $client, CacheItemPoolInterface $cache, array $config)
    {
        $this->url = 'https://'.$config['hostname'].'/api/sp/';
        $this->restToken = $config['resttoken'];
        $this->client = $client;
        $this->cache = $cache;

        $this->shouldCache = $config['cache'];
        $this->cacheTtl = $config['cache_ttl'];
    }

    /**
     * Gets multiple managed objects with optional search filters.
     *
     * @param array $filters Search Filters
     * @param int   $perPage Number of pages to get from the server at a time. Default 50.
     *
     * @return array Returns an array with the records from the API
     */
    public function getManagedObjects(?array $filters = null, int $perPage = 50)
    {
        return $this->findRest('managed_objects', $filters, $perPage);
    }

    /**
     * Create a new managed object.
     *
     * @param string $name            Name of the managed object to create
     * @param string $family          Managed object family: peer, profile or customer
     * @param array  $tags            Tags to add the the managed object
     * @param string $matchType       What type this match is, cidr_blocks for example
     * @param string $match           What to match against
     * @param array  $relationships   Relationships to this managed object. See Arbor SDK Docs.
     * @param array  $extraAttributes Extra attributes to add to this managed object. See Arbor SDK Docs.
     *
     * @return array|null the output of the API call, null otherwise
     */
    public function createManagedObject(string $name, string $family, array $tags, string $matchType, string $match, ?array $relationships = null, ?array $extraAttributes = null)
    {
        $url = $this->url.'/managed_objects/';

        // Disable host detection settings in relationship unless
        // this has been overridden by the relationships argument.
        //
        if (null === $relationships) {
            $relationships = [
                'shared_host_detection_settings' => [
                    'data' => [
                        'type' => 'shared_host_detection_setting',
                        'id' => '0',
                    ],
                ],
            ];
        }

        // Add in the required attributes for a managed object.
        //
        $requiredAttributes = [
            'name' => $name,
            'family' => $family,
            'tags' => $tags,
            'match' => $match,
            'match_type' => $matchType,
        ];

        // Merge in extra attributes for this managed object
        //
        if (null === $extraAttributes) {
            $attributes = $requiredAttributes;
        } else {
            $attributes = array_merge($requiredAttributes, $extraAttributes);
        }

        // Create the full managed object data to be converted to json.
        //
        $moJson = [
            'data' => [
                'attributes' => $attributes,
                'relationships' => $relationships,
            ],
        ];

        $dataString = json_encode($moJson);

        // Send the API request.
        //
        return $this->doPostRequest($url, 'POST', $dataString);
    }

    /**
     * Change a managed object.
     *
     * @param string $arborID       Managed object ID to change
     * @param array  $attributes    Attributes to change on the managed object.
     *                              See Arbor API documentation for a full list of attributes.
     * @param array  $relationships Relationships to this managed object. See Arbor SDK Docs.
     *
     * @return array|null the output of the API call, null otherwise
     */
    public function changeManagedObject(string $arborID, array $attributes, ?array $relationships = null)
    {
        $url = $this->url.'/managed_objects/'.$arborID;

        $moJson = [
            'data' => [
                'attributes' => $attributes,
            ],
        ];

        if (null !== $relationships) {
            $moJson['data']['relationships'] = $relationships;
        }

        $dataString = json_encode($moJson);

        // Send the API request.
        //
        return $this->doPostRequest($url, 'PATCH', $dataString);
    }

    /**
     * Gets mitigations optional filters (See Arbor API Documents).
     *
     * @param array $filters Filters
     * @param int   $perPage Number of pages to get from the server at a time. Default 50.
     *
     * @return array|null the output of the API call, null otherwise
     */
    public function getMitigations(?array $filters = null, int $perPage = 50)
    {
        return $this->findRest('mitigations', $filters, $perPage);
    }

    /**
     * Gets multiple mitigation templates with optional filters (See Arbor API Documents).
     *
     * @param array $filters Filters
     * @param int   $perPage Number of pages to get from the server at a time. Default 50.
     *
     * @return array|null the output of the API call, null otherwise
     */
    public function getMitigationTemplates(?array $filters = null, int $perPage = 50)
    {
        return $this->findRest('mitigation_templates', $filters, $perPage);
    }

    /**
     * Gets multiple mitigation templates with optional filters (See Arbor API Documents).
     *
     * @param string $templateID  Template ID to copy
     * @param string $name        New name for copied mitigation template
     * @param string $description New description for copied mitigation template
     *
     * @return array|null the output of the API call, null otherwise
     */
    public function copyMitigationTemplate(string $templateID, string $name, string $description)
    {
        $existingTemplate = $this->getByID('mitigation_templates', $templateID);

        if ($this->hasError) {
            return;
        }

        $out = $this->createMitigationTemplate($name, $existingTemplate['data']['attributes']['ip_version'], $description, $existingTemplate['data']['attributes']['subobject'], $existingTemplate['data']['relationships'], $existingTemplate['data']['attributes']['subtype']);

        return $out;
    }

    /**
     * Create a new mitigation template. See Arbor SDK Docs for countermeasure and relationship
     * settings.
     *
     * @param string $name            Name of the mitigation template to create
     * @param string $ipVersion       IP Version of the mitigation template
     * @param string $description     Description of mitigation template
     * @param array  $countermeasures Countermeasure settings for this mitigation template
     * @param array  $relationships   Relationships to this mitigation template. See Arbor SDK Docs
     * @param string $subtype         Subtype for this mitigation template
     *
     * @return array|null The output of the API call, null otherwise
     */
    public function createMitigationTemplate(string $name, string $ipVersion, string $description, array $countermeasures, array $relationships = [], string $subtype = 'tms')
    {
        $url = $this->url.'/mitigation_templates/';

        // Add in the required attributes for a managed object.
        //
        $attributes = [
            'name' => $name,
            'ip_version' => $ipVersion,
            'description' => $description,
            'subtype' => $subtype,
            'subobject' => $countermeasures,
        ];

        // Create the full mitigation template data to be converted to json.
        //
        $moJson = [
            'data' => [
                'attributes' => $attributes,
                'relationships' => $relationships,
                'type' => 'mitigation_template',
            ],
        ];

        $dataString = json_encode($moJson);

        // Send the API request.
        //
        return $this->doPostRequest($url, 'POST', $dataString);
    }

    /**
     * Change a mitigation template.
     *
     * @param string $arborID       Mitigation template ID to change
     * @param array  $attributes    Attributes to change on the managed object.
     *                              See Arbor API documentation for a full list of attributes.
     * @param array  $relationships Relationships to this managed object. See Arbor SDK Docs.
     *
     * @return array|null the output of the API call, null otherwise
     */
    public function changeMitigationTemplate(string $arborID, array $attributes, ?array $relationships = null)
    {
        $url = $this->url.'/mitigation_templates/'.$arborID;

        $moJson = [
            'data' => [
                'attributes' => $attributes,
            ],
        ];

        if (null !== $relationships) {
            $moJson['data']['relationships'] = $relationships;
        }

        $dataString = json_encode($moJson);

        // Send the API request.
        //
        return $this->doPostRequest($url, 'PATCH', $dataString);
    }

    /**
     * Gets multiple notification Groups with optional search.
     *
     * @param array $filters Search filters
     * @param int   $perPage Number of pages to get from the server at a time. Default 50.
     *
     * @return array|null the output of the API call, null otherwise
     */
    public function getNotificationGroups(?array $filters = null, int $perPage = 50)
    {
        return $this->findRest('notification_groups', $filters, $perPage);
    }

    /**
     * Create a new managed object.
     *
     * @param string $name            Name of the managed object to create
     * @param array  $emailAddresses  Email addresses to add to the notification group
     * @param array  $extraAttributes Extra attributes to add to this notification group. See Arbor SDK Docs.
     *
     * @return array|null the output of the API call, null otherwise
     */
    public function createNotificationGroup(string $name, ?array $emailAddresses = null, ?array $extraAttributes = null)
    {
        $url = $this->url.'/notification_groups/';

        // Add in the required attributes for a notification group.
        //
        $requiredAttributes = ['name' => $name];

        if (isset($emailAddresses)) {
            $requiredAttributes['smtp_email_addresses'] = implode(',', $emailAddresses);
        }

        // Merge in extra attributes for this managed object
        //
        if (null === $extraAttributes) {
            $attributes = $requiredAttributes;
        } else {
            $attributes = array_merge($requiredAttributes, $extraAttributes);
        }

        // Create the full managed object data to be converted to json.
        //
        $ngJson = [
            'data' => [
                'attributes' => $attributes,
            ],
        ];

        $dataString = json_encode($ngJson);

        // Send the API request.
        //
        return $this->doPostRequest($url, 'POST', $dataString);
    }

    /**
     * Change a notification group.
     *
     * @param string $arborID    Notification group ID to change
     * @param array  $attributes Attributes to change on the notifciation group
     *                           See Arbor API documentation for a full list of attributes
     *
     * @return array|null the output of the API call, null otherwise
     */
    public function changeNotificationGroup(string $arborID, array $attributes)
    {
        $url = $this->url.'/notification_groups/'.$arborID;

        $ngJson = [
            'data' => [
                'attributes' => $attributes,
            ],
        ];

        $dataString = json_encode($ngJson);

        // Send the API request.
        //
        return $this->doPostRequest($url, 'PATCH', $dataString);
    }

    /**
     * Get an object by it's ID.
     *
     * @param string $endpoint Type of object to get. managed_object etc
     * @param string $arborID  Object ID
     *
     * @return array|null the output of the API call, null otherwise
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
     * @param string $endpoint Endpoint type, Managed Object, Mitigations etc.
     *                         See Arbor API documenation for endpoint list.
     * @param array  $filters  Search filters
     * @param int    $perPage  Limit the number of returned objects per page, default 50
     *
     * @return array returns an array with the records from the API
     */
    public function findRest(string $endpoint, ?array $filters = null, int $perPage = 50)
    {
        $results = [];

        $apiResult = $this->doMultiGetRequest($endpoint, $filters, $perPage);

        if (!$apiResult) {
            return;
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
    public function shouldCache(bool $cacheOn)
    {
        $this->shouldCache = $cacheOn;
    }

    /**
     * Gets the current error state.
     *
     * @return bool true if there is a current error, false otherwise
     */
    public function hasError()
    {
        return $this->hasError;
    }

    /**
     * Gets the current error messages.
     *
     * @return array the error messages
     */
    public function errorMessage()
    {
        return $this->errorMessages;
    }

    /**
     * Adds an error message to the error array.
     *
     * @param string $msg
     *
     * @return string the error message string
     */
    private function addErrorMessage(string $msg)
    {
        $this->errorMessages[] = $msg;
    }

    /**
     * Makes a connection to the Arbor API platform using HTTP Component.
     *
     * @param string $method  Request method, POST, PATCH, GET
     * @param string $url     URL to make the request against
     * @param array  $options HTTP Client component options
     *
     * @return object|null the HTTP Client Response Object, null on error
     */
    private function connect(string $method, string $url, array $options = [])
    {
        $options['headers'] =
            [
                'Content-Type: application/vnd.api+json',
                'X-Arbux-APIToken: '.$this->restToken,
            ];

        try {
            $response = $this->client->request($method, $url, $options);
        } catch (DecodingExceptionInterface|TransportExceptionInterface $e) {
            $this->hasError = true;
            $this->addErrorMessage($e->getMessage());

            return;
        }

        return $response;
    }

    /**
     * Get's the returned content from the request.
     *
     * @param object $response a Valid HTTP Client reponse object
     *
     * @return array|null The response from the server as an array. Null if error.
     */
    private function getResult($response)
    {
        // check the response object is valid.
        //
        if (!$response) {
            return;
        }

        // Get the content.
        try {
            $apiResult = $response->toArray(false);
        } catch (HttpExceptionInterface|DecodingExceptionInterface|TransportExceptionInterface $e) {
            $this->hasError = true;
            $this->addErrorMessage($e->getMessage());

            return;
        }

        if (empty($apiResult)) {
            $this->hasError = true;
            $this->addErrorMessage('ArborAPI: Server returned no data.');

            return;
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode >= 300) {
            $this->hasError = true;
            $this->addErrorMessage('Arbor Leader returned status code: '.$statusCode);

            if (isset($apiResult['errors']) && !empty($apiResult['errors'])) {
                $this->hasError = true;
                $this->findError($apiResult['errors']);

                return;
            }

            return;
        }

        return $apiResult;
    }

    /**
     * Perform a GET request against the API.
     *
     * @param string $url  URL to make the request against
     * @param array  $args Optional query arguments to append to the URL
     *
     * @return array|null the output of the API call, null otherwise
     */
    private function doGetRequest(string $url, ?array $args = null)
    {
        $this->hasError = false;
        $this->errorMessages = [];

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
        if (null !== $result && true === $this->shouldCache) {
            $cachedItem->expiresAfter($this->cacheTtl);
            $cachedItem->set($result);
            $this->cache->save($cachedItem);
        }

        return $result;
    }

    /**
     * Perform multiple requests against the Arbor REST API.
     *
     * @param string     $endpoint endpoint to query against, see Arbor REST API documentation
     * @param array|null $filters
     * @param int        $perPage  Total number of objects per page. (Default 50)
     *
     * @return array|null the output of the API call, null otherwise
     */
    private function doMultiGetRequest(string $endpoint, ?array $filters = null, int $perPage = 50)
    {
        $this->hasError = false;
        $this->errorMessages = [];

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

        $totalPages = 1;

        $apiResult = [];

        // Do inital REST call to the API, this helps determine the number of
        // pages in the result. Turn off caching for this request.
        //
        $oldShouldCache = $this->shouldCache;
        $this->shouldCache = false;

        $apiResult[] = $this->doGetRequest($url, $args);

        $this->shouldCache = $oldShouldCache;

        // If there is an error return here.
        //
        if ($this->hasError) {
            return;
        }

        //
        // Work out the number of pages.
        //
        if (isset($apiResult[0]['links']['last'])) {
            parse_str(parse_url($apiResult[0]['links']['last'])['query'], $parsed);
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
                // get the result, if null there's an error so don't add to final result.
                $res = $this->getResult($response);
                if (null !== $res) {
                    $apiResult[] = $res;
                }
            }
        }

        // If caching is enabled add valid result to the cache.
        // If there is an error (for example a partial result because a request
        // to get a page had an error) then don't cache the result.
        //
        if (true !== $this->hasError && true === $this->shouldCache) {
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
     * @return array|null the output of the API call, null otherwise
     */
    private function doPostRequest(string $url, string $type = 'POST', string $postData = null)
    {
        $this->hasError = false;
        $this->errorMessages = [];

        $options = [];

        $options['body'] = $postData;

        return $this->getResult($this->connect($type, $url, $options));
    }

    /**
     * Converts a search filter into a valid url encoded search string.
     *
     * @param mixed $search
     *
     * @return string Encoded URL string
     */
    private function searchFilterToUrl($search)
    {
        $searchUrl = [];

        if (is_array($search)) {
            foreach ($search as $term) {
                $searchUrl[] = urlencode($term);
            }

            return implode('|', $searchUrl);
        }

        return urlencode($search);
    }

    /**
     * Converts a filter into a valid URL.
     *
     * @param array $filters
     *
     * @return string Encoded URL string
     */
    private function filterToUrl(array $filters)
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
     * Find an error in the results of the REST API which gave an Error.
     *
     * @param array $errors an array of errors returned by the API
     */
    private function findError(array $errors)
    {
        foreach ($errors as $error) {
            if (isset($error['id'])) {
                $this->errorMessages[] = $error['id']."\n ";
            }
            if (isset($error['message'])) {
                $this->errorMessages[] = $error['message']."\n ";
            }
            if (isset($error['title'])) {
                $this->errorMessages[] = $error['title']."\n ";
            }
            if (isset($error['detail'])) {
                $this->errorMessages[] = $error['detail']."\n ";
            }
        }
    }

    /**
     * Get the cache Key.
     *
     * @param string     $url  URL to make the request against
     * @param array|null $args URL args
     *
     * @return string cache key
     */
    private function getCacheKey(string $url, ?array $args = null)
    {
        if (null === $args) {
            return 'arbor_rest_'.sha1($url);
        }

        return 'arbor_rest_'.sha1($url.http_build_query($args));
    }
}
