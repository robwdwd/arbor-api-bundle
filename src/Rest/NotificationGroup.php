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
 * Access the Arbor Sightline REST API.
 *
 * @author Rob Woodward <rob@emailplus.org>
 */
class NotificationGroup extends REST
{
    protected $cacheKeyPrefix = 'arbor_rest_ng';

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
}
