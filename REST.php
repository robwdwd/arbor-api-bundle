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
     * @param string $hostname  Hostname of the Arbor SP Leader
     * @param string $restToken REST API Key
     */
    public function __construct(HttpClientInterface $client, CacheInterface $cache, array $config)
    {
        $this->url = 'https://'.$config['hostname'].'/api/sp/';
        $this->restToken = $config['resttoken'];
        $this->client = $client;
        $this->cache = $cache;

        $this->shouldCache = $config['cache'];
        $this->cacheTtl = $config['cache_ttl'];
    }

    /**
     * Gets multiple managed objects with optional search fields.
     *
     * @param string $filters array of filters
     * @param int    $perPage Number of pages to get from the server at a time. Default 50.
     *
     * @return string Returns a json string with the records from the API
     */
    public function getManagedObjects($filters = null, $perPage = 50)
    {
        return $this->findRest('managed_objects', $filters, $perPage);
    }

    /**
     * Create a new managed object.
     *
     * @param string $name            Name of the managed object to create
     * @param string $family          Managed object family: peer, profile or customer
     * @param string $tags            Tags to add the the managed object
     * @param string $matchType       what type this match is, cidr_blocks for example
     * @param string $match           what to match against
     * @param object $relationships   Object for relationships to this managed object. See Arbor SDK Docs.
     * @param object $extraAttributes Object for extra attributes to add to this managed object. See Arbor SDK Docs.
     *
     * @return object returns a json decoded object with the result
     */
    public function createManagedObject($name, $family, $tags, $matchType, $match, $relationships = null, $extraAttributes = null)
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
     * @param string $arborID       managed object ID to change
     * @param string $attributes    Attributes to change on the managed object.
     *                              See Arbor API documentation for a full list of attributes.
     * @param object $relationships Object for relationships to this managed object. See Arbor SDK Docs.
     *
     * @return object returns a json decoded object with the result
     */
    public function changeManagedObject($arborID, $attributes, $relationships = null)
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
     * @param string $filters Filters
     * @param int    $perPage Number of pages to get from the server at a time. Default 50.
     *
     * @return string Returns a json string with the records from the API
     */
    public function getMitigations($filters = null, $perPage = 50)
    {
        return $this->findRest('mitigations', $filters, $perPage);
    }

    /**
     * Gets multiple mitigation templates with optional filters (See Arbor API Documents).
     *
     * @param string $filters Filters
     * @param int    $perPage Number of pages to get from the server at a time. Default 50.
     *
     * @return string Returns a json string with the records from the API
     */
    public function getMitigationTemplates($filters = null, $perPage = 50)
    {
        return $this->findRest('mitigation_templates', $filters, $perPage);
    }

    /**
     * Gets multiple mitigation templates with optional filters (See Arbor API Documents).
     *
     * @param int    $templateID  Templte ID to copy
     * @param string $name        New name for copied mitigation template
     * @param string $description New description for copied mitigation template
     *
     * @return string Returns a json string with the records from the API
     */
    public function copyMitigationTemplate($templateID, $name, $description)
    {
        $existingTemplate = $this->getByID('mitigation_templates', $templateID);

        if ($this->hasError) {
            return;
        }

        $out = $this->createMitigationTemplate(
            $name,
            $existingTemplate['data']['attributes']['ip_version'],
            $description,
            $existingTemplate['data']['attributes']['subobject'],
            $existingTemplate['data']['relationships'],
            $existingTemplate['data']['attributes']['subtype']
        );

        return $out;
    }

    /**
     * Create a new mitigation template.
     *
     * @param string $name            Name of the mitigation template to create
     * @param string $ipVersion       IP Version of the mitigation template
     * @param object $relationships   Object for relationships to this mitigation template. See Arbor SDK Docs.
     * @param object $extraAttributes Object for extra attributes to add to this mitigation template. See Arbor SDK Docs.
     * @param mixed  $description
     * @param mixed  $countermeasures
     * @param mixed  $subtype
     *
     * @return object returns a json decoded object with the result
     */
    public function createMitigationTemplate(
        $name,
        $ipVersion,
        $description,
        $countermeasures,
        $relationships = [],
        $subtype = 'tms'
    ) {
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
     * @param string $arborID       mitigation template ID to change
     * @param string $attributes    Attributes to change on the managed object.
     *                              See Arbor API documentation for a full list of attributes.
     * @param object $relationships Object for relationships to this managed object. See Arbor SDK Docs.
     *
     * @return object returns a json decoded object with the result
     */
    public function changeMitigationTemplate($arborID, $attributes, $relationships = null)
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
     * @param array $filters Filters array
     * @param int   $perPage Number of pages to get from the server at a time. Default 50.
     *
     * @return string Returns a json string with the records from the API
     */
    public function getNotificationGroups($filters = null, $perPage = 50)
    {
        return $this->findRest('notification_groups', $filters, $perPage);
    }

    /**
     * Create a new managed object.
     *
     * @param string $name            Name of the managed object to create
     * @param array  $emailAddresses  array of email addresses to add to the notification group
     * @param object $extraAttributes Object for extra attributes to add to this notification group. See Arbor SDK Docs.
     *
     * @return object returns a json decoded object with the result
     */
    public function createNotificationGroup($name, $emailAddresses = null, $extraAttributes = null)
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
     * @param string $arborID    notification group ID to change
     * @param string $attributes attributes to change on the notifciation group
     *                           See Arbor API documentation for a full list of attributes
     *
     * @return object returns a json decoded object with the result
     */
    public function changeNotificationGroup($arborID, $attributes)
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
     * @param string endpoint Type of object to get. managed_object etc
     * @param string id   object ID
     *
     * @return object returns a json decoded object with the result
     */
    public function getByID($endpoint, $arborID)
    {
        $url = $this->url.$endpoint.'/'.$arborID;

        return $this->doGetRequest($url);
    }

    /**
     * Find or search Arbor SP REST API for a particular record or set of
     * records.
     *
     * @param string     $endpoint endpoint type, Managed Object, Mitigations etc.
     *                             See Arbor API documenation for endpoint list.
     * @param array      $filters  Filters array
     * @param int        $perPage  limit the number of returned objects per page
     * @param mixed|null $filters
     *
     * @return string returns a json string with the records from the API
     */
    public function findRest($endpoint, $filters = null, $perPage = 50)
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
     * @return string the error message string
     */
    private function addErrorMessage($msg)
    {
        $this->errorMessages[] = $msg;
    }

    /**
     * Makes a connection to the Arbor API platform using HTTP Component.
     *
     * @param string $type    Request type, POST, PATCH, GET
     * @param string $url     URL to make the request against
     * @param array  $options HTTP Client component options array
     *
     * @return object The HTTP Client Response Object
     */
    private function connect($type, $url, $options = [])
    {
        $options['headers'] =
            [
                'Content-Type: application/vnd.api+json',
                'X-Arbux-APIToken: '.$this->restToken,
            ];

        try {
            $response = $this->client->request($type, $url, $options);
        } catch (DecodingExceptionInterface | TransportExceptionInterface $e) {
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
     * @return array the response from the server as an array
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
        } catch (HttpExceptionInterface | DecodingExceptionInterface | TransportExceptionInterface $e) {
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
     * @return array the output of the API call, null otherwise
     */
    private function doGetRequest($url, $args = null)
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
     * @param string $endpoint endpoint to query against, see Arbor REST API documentation
     * @param int    $perPage  Total number of objects per page. (Default 50)
     *
     * @return array the output of the API call, null otherwise
     */
    private function doMultiGetRequest($endpoint, $filters = null, $perPage = 50)
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
     * @return array the output of the API call, null otherwise
     */
    private function doPostRequest($url, $type = 'POST', $postData = null)
    {
        $this->hasError = false;
        $this->errorMessages = [];

        $options = [];

        $options['body'] = $postData;

        return $this->getResult($this->connect($type, $url, $options));
    }

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

    private function filterToUrl($filters)
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
    private function findError($errors)
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
     * @param string      $url  URL to make the request against
     * @param string|null $args URL args
     *
     * @return string cache key
     */
    private function getCacheKey($url, $args = null)
    {
        if (null === $args) {
            return 'arbor_rest_'.sha1($url);
        }

        return 'arbor_rest_'.sha1($url.http_build_query($args));
    }
}
