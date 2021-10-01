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
 * Access the Arbor Sightline REST API Managed Object endpoints.
 *
 * @author Rob Woodward <rob@emailplus.org>
 */
class ManagedObject extends REST
{
    protected $cacheKeyPrefix = 'arbor_rest_managed_object';

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
}
