<?php

namespace UserFrosting\Sprinkle\ElephantWifi\Controller;

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
use SendinBlue\Client\Configuration as SendinBlueConfiguration;
use SendinBlue\Client\Api\AccountApi as SendinBlueAccountApi;
use SendinBlue\Client\Api\ResellerApi as SendinBlueResellerApi;
use SendinBlue\Client\Api\ListsApi as SendinBlueListsApi;
use SendinBlue\Client\Api\FoldersApi as SendinBlueFoldersApi;
use SendinBlue\Client\Api\AttributesApi as SendinBlueAttributesApi;
use \DrewM\MailChimp\MailChimp;

use UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue;

use UserFrosting\Sprinkle\ElephantWiFi\Database\Models\VenueFreeAccessSettings;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\MarketingList;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\MarketingListType;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\Device;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\VenueWifi;

/**
 * MarketingController Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class MarketingController extends SimpleController 
{
    public function pageList(Request $request, Response $response, $args)
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

        $venue = Venue::where('id', $currentUser->primary_venue_id)->with('venue_wifi.controller', 'venue_wifi.text_labels', 'venue_wifi.free_access_settings', 'venue_wifi.custom_css')->first();
        $marketing_lists = MarketingList::with('marketing_list_type')->where('venue_id', $currentUser->primary_venue_id)->get();

        // Get the validation rules for the form on this page
        if ($venue->venue_wifi->mail_type == 'mailchimp')
            $schema = new RequestSchema('schema://requests/mailchimp-list-create.yaml');
        else
            $schema = new RequestSchema('schema://requests/sendinBlue-list-create.yaml');

        // Load validation rules
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        $schema = new RequestSchema('schema://requests/venue-marketing-details.yaml');
        $venue_marketing_details_validators = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/elephantwifi/admin/marketing_lists.html.twig', [
            'validators' => $validator->rules('json', true),
            'venue_marketing_details_validators' => $venue_marketing_details_validators->rules('json', true),
            'marketing_lists' => $marketing_lists,
            'unifi_venue' => $venue
        ]);
    }

    public function pageListType(Request $request, Response $response, $args)
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

        $marketing_list_types = MarketingListType::get();

        // Get the validation rules for the form on this page
        $schema = new RequestSchema('schema://requests/marketing-list-type-create.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/elephantwifi/admin/marketing_list_type.html.twig', [
            'validators' => $validator->rules('json', true),
            'marketing_list_types' => $marketing_list_types
        ]);
    }

    public function formSendinBlueListCreate(Request $request, Response $response, $args)
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

        // Get HTTP GET parameters
        $get = $request->getQueryParams();

        $list_types = MarketingListType::get();

        // Load validation rules
        $schema = new RequestSchema('schema://requests/sendinBlue-list-create.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/elephantwifi/admin/forms/sendinBlue-list-create-modal.html.twig', [
            'box_id' => $get['box_id'],
            'box_title' => 'Add List',
            'modal_mode' => 'create',
            'submit_button' => 'Add List',
            'form_action' => $config['site.uri.public'] . '/admin/elephantwifi/sendinblue/marketing/list',
            'validators' => $validator->rules('json', true),
            'list_types' => $list_types
        ]);
    }

    public function formSendinBlueListUpdate(Request $request, Response $response, $args)
    {
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

        // Get HTTP GET parameters
        $get = $request->getQueryParams();

        /**
         * Get the venue to edit
         */
        $listQuery = new MarketingList;
        $target_list = $listQuery->where('id', $args['marketing_list_id'])->first();

        $list_types = MarketingListType::get();

        // Load validation rules
        $schema = new RequestSchema('schema://requests/sendinBlue-list-create.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/elephantwifi/admin/forms/sendinBlue-list-create-modal.html.twig', [
            'validators' => $validator->rules('json', true),
            'box_id' => $get['box_id'],
            'box_title' => 'Edit List',
            'modal_mode' => 'edit',
            'submit_button' => 'Update List',
            'form_action' => $config['site.uri.public'] . '/admin/elephantwifi/sendinblue/marketing/list/u/' . $target_list->id,
            'target_list' => $target_list,
            'list_types' => $list_types
        ]);
    }

    public function formMailchimpListCreate(Request $request, Response $response, $args)
    {
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

        // Get HTTP GET parameters
        $get = $request->getQueryParams();

        $list_types = MarketingListType::get();

        // Load validation rules
        $schema = new RequestSchema('schema://requests/mailchimp-list-create.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/elephantwifi/admin/forms/mailchimp-list-create-modal.html.twig', [
            'box_id' => $get['box_id'],
            'box_title' => 'Add List',
            'modal_mode' => 'create',
            'submit_button' => 'Add List',
            'form_action' => $config['site.uri.public'] . '/admin/elephantwifi/mailchimp/marketing/list',
            'validators' => $validator->rules('json', true),
            'list_types' => $list_types
        ]);
    }

    public function formMailchimpListUpdate(Request $request, Response $response, $args)
    {
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

        // Get HTTP GET parameters
        $get = $request->getQueryParams();

        /**
         * Get the venue to edit
         */
        $listQuery = new MarketingList;
        $target_list = $listQuery->where('id', $args['marketing_list_id'])->first();

        $list_types = MarketingListType::get();

        // Load validation rules
        $schema = new RequestSchema('schema://requests/mailchimp-list-create.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/elephantwifi/admin/forms/mailchimp-list-create-modal.html.twig', [
            'box_id' => $get['box_id'],
            'box_title' => 'Edit List',
            'modal_mode' => 'edit',
            'submit_button' => 'Update List',
            'form_action' => $config['site.uri.public'] . '/admin/elephantwifi/mailchimp/marketing/list/u/' . $target_list->id,
            'target_list' => $target_list,
            'validators' => $validator->rules('json', true),
            'list_types' => $list_types
        ]);
    }

    public function createSendinBlueList(Request $request, Response $response, $args)
    {
        // Get POST parameters
        $params = $request->getParsedBody();

        // Load validation rules
        $schema = new RequestSchema('schema://requests/sendinBlue-list-create.yaml');

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

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        $venueQuery = new Venue;
        $venue = $venueQuery->with('venue_wifi')->where('id', $currentUser->primary_venue_id)->first();

        /**
         * SendinBlue API request
         * - create list
         */
        $config = SendinBlueConfiguration::getDefaultConfiguration()->setApiKey('api-key', $venue->venue_wifi->marketing_public_key);

        $apiFolderInstance = new SendinBlueFoldersApi(
            new \GuzzleHttp\Client(),
            $config
        );
        $limit = 10; // int | Number of documents per page
        $offset = 0; // int | Index of the first document of the page

        try {
            $foldersList = $apiFolderInstance->getFolders($limit, $offset)->getFolders();
        } 
        catch (Exception $e) {
            return $response->withJson([], 400);
        }

        $folder_exists = false;
        $folder_id;
        foreach($foldersList as $folder) {
            if ($folder['name'] == 'ElephantWiFi') {
                $folder_exists = true;
                $folder_id = $folder['id'];
            }
        }

        if (!$folder_exists) {
            $name = (object)[
                'name' => 'ElephantWiFi'
            ];

            $newFolder = $apiFolderInstance->createFolder($name);
            $folder_id = $newFolder->getId();
        }

        // Create the list
        $apiListInstance = new SendinBlueListsApi(
            new \GuzzleHttp\Client(),
            $config
        );

        $listId = 0;
        try {
            $createList = (object)[
                'name' => $data['list_name'],
                'folderId' => $folder_id
            ];

            $newList = $apiListInstance->createList($createList);
            $listId = $newList->getId();

            error_log(print_r($result, true));
        } catch (Exception $e) {
            return $response->withJson([], 400);
        }

        // Store the new list in the database if the API was a success
        if ($listId > 0) {
            $marketing_list = new MarketingList();
            $marketing_list->venue_id = $venue->id;
            $marketing_list->marketing_list_type_id = $data['list_type'];
            $marketing_list->mail_type = $venue->venue_wifi->mail_type;
            $marketing_list->list_uid = $listId;
            $marketing_list->list_name = $data['list_name'];

            $marketing_list->save();
        }

        // send message back to the user
        $ms->addMessageTranslated("success", "Marketing List Stored");

        return $response->withJson([], 200);
    }

    public function updateSendinBlueList(Request $request, Response $response, $args)
    {
        // Get POST parameters
        $params = $request->getParsedBody();

        // Load validation rules
        $schema = new RequestSchema('schema://requests/sendinBlue-list-create.yaml');

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

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        $venueQuery = new Venue;
        $venue = $venueQuery->with('venue_wifi')->where('id', $currentUser->primary_venue_id)->first();

        /**
         * Get the marketing_list we are updating
         */
        $marketing_list = MarketingList::where('id', $args['marketing_list_id'])->first();

        /**
         * SendinBlue API request
         * - update list
         */
        $config = SendinBlueConfiguration::getDefaultConfiguration()->setApiKey('api-key', $venue->venue_wifi->marketing_public_key);

        $apiFolderInstance = new SendinBlueFoldersApi(
            new \GuzzleHttp\Client(),
            $config
        );
        $limit = 10; // int | Number of documents per page
        $offset = 0; // int | Index of the first document of the page

        try {
            $foldersList = $apiFolderInstance->getFolders($limit, $offset)->getFolders();
        } 
        catch (Exception $e) {
            return $response->withJson([], 400);
        }

        $folder_exists = false;
        $folder_id;
        foreach($foldersList as $folder) {
            if ($folder['name'] == 'ElephantWiFi') {
                $folder_exists = true;
                $folder_id = $folder['id'];
            }
        }

        // Create the folder if it doesnt exist
        if (!$folder_exists) {
            $name = (object)[
                'name' => 'ElephantWiFi'
            ];

            $newFolder = $apiFolderInstance->createFolder($name);
            $folder_id = $newFolder->getId();
        }

        // Create the list
        $apiListsInstance = new SendinBlueListsApi(
            new \GuzzleHttp\Client(),
            $config
        );
        $listId = $marketing_list->list_uid;

        $updateList = (object)[
            'name' => $data['list_name']
        ];

        error_log(print_r($updateList, true));

        try {
            $apiListsInstance->updateList($listId, $updateList);
        } 
        catch (Exception $e) {
            return $response->withJson([], 400);
        }

        // Store the updated values in the DB
        $marketing_list->marketing_list_type_id = $data['list_type'];
        $marketing_list->list_name = $data['list_name'];
        $marketing_list->save();

        // send message back to the user
        $ms->addMessageTranslated("success", "Marketing list has been updated");

        return $response->withJson([], 200);
    }

    public function createMailchimpList(Request $request, Response $response, $args)
    {
        // Get POST parameters
        $params = $request->getParsedBody();

        // Load validation rules
        $schema = new RequestSchema('schema://requests/mailchimp-list-create.yaml');

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

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        $venueQuery = new Venue;
        $venue = $venueQuery->with('venue_wifi')->where('id', $currentUser->primary_venue_id)->first();

        /**
         * Mailchimp API call
         */
        $MailChimp = new MailChimp($venue->venue_wifi['marketing_public_key']);
        $mailchimp_api_lists = $MailChimp->get('lists');

        /**
         * Get the list details we need
         */
        $marketing_api_list_array = [];
        foreach ($mailchimp_api_lists['lists'] as $mailchimp_api_list) {
            $marketing_api_list_array[$mailchimp_api_list['id']] = [
                'list_name' => $mailchimp_api_list['name'],
                'from_name' => $mailchimp_api_list['campaign_defaults']['from_name'],
                'from_email' => $mailchimp_api_list['campaign_defaults']['from_email'],
                'reply_to' => $mailchimp_api_list['campaign_defaults']['from_email'],
                'subject' => $mailchimp_api_list['campaign_defaults']['subject'],
                'company_name' => $mailchimp_api_list['contact']['company'],
                'company_country' => $mailchimp_api_list['contact']['country'],
                'company_county' => $mailchimp_api_list['contact']['state'],
                'company_address_1' => $mailchimp_api_list['contact']['address1'],
                'company_address_2' => $mailchimp_api_list['contact']['address2'],
                'company_city' => $mailchimp_api_list['contact']['city'],
                'company_postcode' => $mailchimp_api_list['contact']['zip']
            ];
        }
    
        // Store the values retreived from the api call
        if(!array_key_exists($data['list_uid'], $marketing_api_list_array)) {
            $ms->addMessageTranslated("danger", "List ID is incorrect or does not exist.");
            throw new ForbiddenException();
        }

        $mailchimp_api_list = $marketing_api_list_array[$data['list_uid']];

        $marketing_list = new MarketingList();
        $marketing_list->venue_id = $venue->id;
        $marketing_list->marketing_list_type_id = $data['marketing_list_type_id'];
        $marketing_list->mail_type = 'mailchimp';
        $marketing_list->list_uid = $data['list_uid'];
        $marketing_list->list_name = $mailchimp_api_list['list_name'];
        $marketing_list->list_description = 'N/A';
        $marketing_list->from_name = $mailchimp_api_list['from_name'];
        $marketing_list->from_email = $mailchimp_api_list['from_email'];
        $marketing_list->reply_to = $mailchimp_api_list['reply_to'];
        $marketing_list->subject = $mailchimp_api_list['subject'];
        $marketing_list->company_name = $mailchimp_api_list['company_name'];
        $marketing_list->company_country = $mailchimp_api_list['company_country'];
        $marketing_list->company_county = $mailchimp_api_list['company_county'];
        $marketing_list->company_address_1 = $mailchimp_api_list['company_address_1'];
        $marketing_list->company_address_2 = $mailchimp_api_list['company_address_2'];
        $marketing_list->company_city = $mailchimp_api_list['company_city'];
        $marketing_list->company_postcode = $mailchimp_api_list['company_postcode'];
        $marketing_list->save();

        // send message back to the user
        $ms->addMessageTranslated("success", "Marketing List Stored");

        return $response->withJson([], 200);
    }

    public function deleteList(Request $request, Response $response, $args)
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

        $venueQuery = new Venue;
        $venue = $venueQuery->where('id', $currentUser->primary_venue_id)->first();

        /**
         * get the venue to be deleted
         */
        $list_to_delete = MarketingList::find($args['marketing_list_id']);

        /**
         * Mailwizz API
         */
        // $endpoint = $this->SendinBlue($venue);
        // $api_response = $endpoint->delete($list_to_delete['list_uid']);

        /**
         * delete the venue
         * NOTE: deletion of all associated child objects is handled within the Venue model (Venue.php) by the delete function
         */
        $list_to_delete->delete();

        // send message back to the user
        $ms->addMessageTranslated('success', 'Marketing list was successfully deleted');

        return $response->withJson([], 200);
    }

    public function createListType(Request $request, Response $response, $args)
    {
        // Get POST parameters
        $params = $request->getParsedBody();

        // Load validation rules
        $schema = new RequestSchema('schema://requests/marketing-list-type-create.yaml');

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

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        // Create the listType entry
        $listType = new MarketingListType;
        $listType['name'] = $data['list_type_name'];
        $listType['text'] = $data['list_type_text'];
        $listType->save();

        // send message back to the user
        $ms->addMessageTranslated("success", "MARKETING_LIST_TYPE_NEW_STORED");

        return $response->withJson([], 200);
    }

    public function updateListType(Request $request, Response $response, $args) 
    {
        // Get POST parameters
        $params = $request->getParsedBody();

        // Load validation rules
        $schema = new RequestSchema('schema://requests/marketing-list-type-create.yaml');

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

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        // Update the zone data
        MarketingListType::where('id', $data['id'])
                    ->update(array('name' => $data['list_type_name'], 'text' => $data['list_type_text']));

        // send message back to the user
        $ms->addMessageTranslated("success", "MARKETING_LIST_TYPE_UPDATE_STORED");

        return $response->withJson([], 200);
    }

    public function deleteListType(Request $request, Response $response, $args)
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

        /**
         * get the venue to be deleted
         */
        $list_type_to_delete = MarketingListType::find($args['list_type_id']);

        /**
         * delete the venue
         * NOTE: deletion of all associated child objects is handled within the Venue model (Venue.php) by the delete function
         */
        $name = $list_type_to_delete->name;
        $list_type_to_delete->delete();

        /**
         * send message back to the user
         */
        $ms->addMessageTranslated('success', 'MARKETING_LIST_TYPE_DELETED', ['name' => $name]);

        return $response->withJson([], 200);
    }

    public function reshowMarketing(Request $request, Response $response, $args)
    {
        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        $venue_id = $currentUser->primary_venue_id;
        $now  = Carbon::now()->timestamp;

        /**
         * Check if the selected time was now, if not update DB
         */
        if ($args['time'] != 'now') {
            $date = $now + $args['time'];
            $venue = VenueFreeAccessSettings::where('venue_id', $currentUser->primary_venue_id)->update(['marketing_reshow_time' => $args['time']]);
        } else {
            $date = $now;
        }

        /**
         * Ask for marketing if the user originally selected no
         * reshow_marketing will equal 0 if they selected marketing (check captive portal)
         */
        $devices = Device::where('venue_id', $venue_id)
            ->where('reshow_marketing', '!=', 0)
            ->where('registration_expiry_date', '!=', 0)
            ->where('registration_expiry_date', '>', $now)
            ->update(array('reshow_marketing' => $date));
    }

    public function updateVenueMarketingDetails(Request $request, Response $response, $args)
    {
        // Get POST parameters
        $params = $request->getParsedBody();

        // Check what mailtype this venue will use
        $mail_type = '';
        if ($params['mailchimp'] == 1) {
            $mail_type = 'mailchimp';
        }
        else if ($params['sendinblue'] == 1) {
            $mail_type = 'sendinblue';
        }

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

        // Load validation rules
        $schema = new RequestSchema('schema://requests/venue-marketing-details.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        // Get the venue wifi details
        $venueWifi = VenueWifi::where('venue_id', $currentUser->primary_venue_id)->first();

        $venueWifi->mail_type = $mail_type;
        $venueWifi->marketing_public_key = $data['marketing_public_key'];

        $api_key = '';

        // Create the sendinblue child account for this venue
        if ($mail_type == "sendinblue") {
            // Configure API key authorization: api-key
            $config = SendinBlueConfiguration::getDefaultConfiguration()->setApiKey('api-key', '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');

            $apiResellerInstance = new SendinBlueResellerApi(
                new \GuzzleHttp\Client(),
                $config
            );

            $resellerChild = (object)[
                'email' => $data['marketing_email'],
                'firstName' => $data['marketing_first_name'],
                'lastName' => $data['marketing_last_name'],
                'companyName' => $data['marketing_company_name'],
                'password' => 'P4ssword!'
            ];

            try {
                $api_key = $apiResellerInstance->createResellerChild($resellerChild)->getAuthKey();

                // Set the new public api key
                $venueWifi->marketing_public_key = $api_key;
            } catch (Exception $e) {
                return $response->withJson([], 400);
            }

            $venueWifi->save();

            // // Create the custom attributes
            // $customer_config = SendinBlueConfiguration::getDefaultConfiguration()->setApiKey('api-key', $api_key);

            // $apiAttributesInstance = new SendinBlueAttributesApi(
            //     new \GuzzleHttp\Client(),
            //     $customer_config
            // );

            // $createAttribute = new \SendinBlue\Client\Model\CreateAttribute([
            //     'type' => 'text'
            // ]);

            // try {
            //     $apiAttributesInstance->createAttribute('normal', 'gender', $createAttribute);
            //     $apiAttributesInstance->createAttribute('normal', 'location', $createAttribute);
            //     $apiAttributesInstance->createAttribute('normal', 'age', $createAttribute);
            //     $apiAttributesInstance->createAttribute('normal', 'dob', $createAttribute);
            // } catch (Exception $e) {
            //     return $response->withJson([], 400);
            // }
        }

        $ms->addMessageTranslated('success', 'Venue marketing details have been updated');

        return $response->withJson([], 200);
    }
}