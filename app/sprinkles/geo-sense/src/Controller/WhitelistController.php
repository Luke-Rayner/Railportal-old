<?php

namespace UserFrosting\Sprinkle\GeoSense\Controller;

use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use UserFrosting\Fortress\RequestDataTransformer;
use UserFrosting\Fortress\RequestSchema;
use UserFrosting\Fortress\ServerSideValidator;
use UserFrosting\Fortress\Adapter\JqueryValidationAdapter;
use UserFrosting\Sprinkle\Account\Controller\Exception\SpammyRequestException;
use UserFrosting\Sprinkle\Account\Facades\Password;
use UserFrosting\Sprinkle\Account\Util\Util as AccountUtil;
use UserFrosting\Sprinkle\Core\Controller\SimpleController;
use UserFrosting\Sprinkle\Core\Mail\EmailRecipient;
use UserFrosting\Sprinkle\Core\Mail\TwigMailMessage;
use UserFrosting\Sprinkle\Core\Util\Captcha;
use UserFrosting\Support\Exception\BadRequestException;
use UserFrosting\Support\Exception\ForbiddenException;
use UserFrosting\Support\Exception\NotFoundException;

use UserFrosting\Sprinkle\GeoSense\Database\Models\Whitelist;
use UserFrosting\Sprinkle\GeoSense\Database\Models\MacPrefix;

/**
 * WhitelistController Class
 *
 * @package GeoSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class WhitelistController extends SimpleController 
{
    public function pageWhitelist(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        // show the "add new" whitelist button when user has sufficient permissions
        if ($authorizer->checkAccess($currentUser, 'uri_mac_add')){
            $shown_buttons = ['new'];
        } else {
            $shown_buttons = [];
        }

        // Load validation rules
        $schema = new RequestSchema('schema://requests/mac-create.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/geo-sense/admin/whitelist.html.twig', [
            'validators' => $validator->rules('json', true),
            'buttons' => [
                'shown' => $shown_buttons
            ]
        ]);
    }

    public function addWhitelistEntry(Request $request, Response $response, $args)
    {
        // Get POST parameters
        $params = $request->getParsedBody();

        // Get the alert message stream
        $ms = $this->ci->alerts;

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        // Load validation rules
        $schema = new RequestSchema('schema://requests/mac-create.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        // validate mac address
        if (!empty($data['mac']) && !filter_var($data['mac'], FILTER_VALIDATE_MAC)) {
            $ms->addMessageTranslated("danger", "Mac address is invalid");
            $error = true;
        }

        // Halt on any validation errors
        if ($error) {
            return $response->withJson([], 400);
        }

        /**
         * find device vendor for MAC address provided
         */
        $mac_prefix = MacPrefix::with('device_vendor')
            ->where('prefix', substr($data['mac'], 0, 8))
            ->first();

        if (!empty($mac_prefix)) {
            $data['device_vendor_id'] = $mac_prefix->device_vendor->id;
        }

        /**
         * Perform desired data transformations on required fields.
         * Since we are accessing a single object directly here we do not need to transform to BINARY format,
         * only generate the HEX md5 hash
         */
        $data['device_uuid'] = md5($data['mac'] . $data['venue_id']);

        // Create the mac whitelist object
        $mac = new Whitelist($data);
        $mac->save();

        $ms->addMessageTranslated("success", "New whitelist has been added");

        return $response->withJson([], 200);
    }

    public function updateWhitelistEntry(Request $request, Response $response, $args)
    {
        // Get POST parameters
        $params = $request->getParsedBody();

        // Get the alert message stream
        $ms = $this->ci->alerts;

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        // Load validation rules
        $schema = new RequestSchema('schema://requests/mac-create.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        // validate mac address
        if (!empty($data['mac']) && !filter_var($data['mac'], FILTER_VALIDATE_MAC)) {
            $ms->addMessageTranslated("danger", "Mac address is invalid");
            $error = true;
        }

        // Halt on any validation errors
        if ($error) {
            return $response->withJson([], 400);
        }

        /**
         * First fetch then update the mac whitelist object
         *
         * As necessary, perform desired data transformations on required fields.
         * Since we are accessing a single object directly here we do not need to transform device_uuid to BINARY format,
         * only generate the HEX md5 hash
         */
        $whitelist_entry = Whitelist::find($data['id']);
        $whitelist_entry->label = $data['label'];
        $whitelist_entry->venue_id = $data['venue_id'];
        $whitelist_entry->whitelist = $data['whitelist'];
        if (!empty($data['mac'])) {
            $whitelist_entry->mac = $data['mac'];
            $whitelist_entry->device_uuid = md5($data['mac'] . $data['venue_id']);
        }

        $whitelist_entry->save();

        $ms->addMessageTranslated("success", "New whitelist has been added");

        return $response->withJson([], 200);
    }

    public function addWhitelistEntryByDeviceUUID(Request $request, Response $response, $args)
    {
        // Get POST parameters
        $params = $request->getParsedBody();

        // Get the alert message stream
        $ms = $this->ci->alerts;

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        // Load validation rules
        $schema = new RequestSchema('schema://requests/mac-create.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        // Create the mac whitelist object
        $mac = new Whitelist($data);
        $mac->save();

        $ms->addMessageTranslated("success", "New whitelist has been added");

        return $response->withJson([], 200);
    }
}