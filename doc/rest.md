# REST API

The rest API allows for access to Arbor REST API services. Documentation
is avaiable from Arbor and from the Portal UI.

## Getting Elements

getByID gets an endpoint element from the Arbor Leader. See the arbor
documentation for a list of valid endpoints.

The following example gets Managed object data for a peer.

```php
use Robwdwd\ArborApiBundle\REST as ArborRest;

public function show(Peer $peer, Request $request ArborRest $arborRest): Response
{
    if ($peer->getArborMoId()) {
        $arborMO = $arborRest->getByID('managed_objects', $peer->getArborMoId());
        dump ($arborMO);
    }
}
```

You can get multiple managed objects with the managed object helper
function. This searches the Attributes of any returned managed object
for an exact match for the search string. By default it gets 50 objects
per page which can be changed with the third parameter.

Filters is an array with type, operator, field and search term.

Type can be, 'a' or 'r', attribute or relationship. Operator can be eq
(equal to) or cn (contains). Field is the field to search. Search can be
a string.

The following gets all peer managed objects retrieving 25 per page.

```php
public function list(Request $request ArborRest $arborRest): Response
{
    $filter = ['type' => 'a', 'operator' => 'eq', 'field' => 'family', 'search' => 'peer'];

    $arborMOs = $arborRest->getManagedObjects($filter, 25);
    dump ($arborMOs);

}
```

The following gets all managed objects matching SomeNetwork as the name
(this would be just one).

```php
public function list(Request $request ArborRest $arborRest): Response
{

    $arborMOs = $arborRest->getManagedObjects('name', 'SomeNetwork');
    dump ($arborMOs);

}
```

The following gets a notification group.

```php
public function list(Request $request ArborRest $arborRest): Response
{

    $ArborNG = $arborRest->getNotificationGroups('name', 'Group1');
    dump ($ArborNG);

}
```

### findRest()

Most of the helper functions such as `` `getManagedObjects ``\` us the
low level findRest function. This adds a wrapper around retrieving
managed objects from the Arbor Leader which currently doesn't provide
any useful filtering (with ArborSP 8.2).

> `` `findRest($endpoint, $field = null, $search = null, $perPage = 50) ``\`

The following retrieves all customer managed objects from the Arbor
Leader.

```php
public function list(Request $request ArborRest $arborRest): Response
{

    $arborMOs = $arborRest->findRest('managed_objects', 'family', 'customer');
    dump ($arborMOs);

}
```

Updating a managed object
-------------------------

Managed objects can be updated. You need to provide a
`` `$attributes ``\` array which changes any attributes on an existing
managed object. The `` `$relationships ``\` array is optional. For
specificys on what fields the attributes and relationships can have
check the ArborREST documentation.

```php
public function updateMo(Peer $peer, ArborRest $arborRest): Response
{
    if ($peer->getArborMoId()) {
        $arborPeer = $peerRepository->getForArbor($peer->getId());

        // Change the name, match type, match and tags
        //
        $attributes = ['name' => $peer->getName(),
            'match' => "702, 701, 703"  // Match is a string.
            'match_type' => 'peer_as',  // Match peer ASN.
            'tags' => ['Downstream', 'ISP'],   // Tags is an array.
        ];


        // Set the shared host detection settings.
        $relationships = [
            'shared_host_detection_settings' => [
                'data' => [
                    'type' => 'shared_host_detection_setting',
                    'id' => '0', // This is the ID for disabled (Turns host detection off).
                ],
            ],
        ];

        $output[] = $arborRest->changeManagedObject($peer->getArborMoId(), $attributes, $relationships);

        // Check for errors.
        if ($arborRest->hasError()) {
            foreach ($arborRest->errorMessage() as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('peer_view', ['id' => $peer->getId()]);
        }

        $this->addFlash('success', 'Peer managed object updated.');
    } else {
        $this->addFlash('error', 'Peer does not have an Arbor managed object ID.');
    }

    return $this->redirectToRoute('peer_view', ['id' => $peer->getId()]);
}
```

## Error Checking

The REST class uses the HTTP client to handle the error checking based
on HTTP status code or a transport/network error but it also tries to
find errors in the returned response from the Arbor API. If there is an
error most functions will return null but you should specifically check
for errors using `` `hasError() ``\` function. Error messages can be
read using the `` `errorMessage() ``\` function which will return an
array of all errors.

```php
// Check for errors.
if ($arborRest->hasError()) {
    foreach ($arborRest->errorMessage() as $error) {
        $this->addFlash('error', $error);
    }

    return $this->redirectToRoute('peer_view', ['id' => $peer->getId()]);
}
```
