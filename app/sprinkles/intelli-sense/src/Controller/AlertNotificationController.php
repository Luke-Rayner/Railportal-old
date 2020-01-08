<?php

namespace UserFrosting\Sprinkle\IntelliSense\Controller;

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

use UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\AlertNotification;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\ExtendedUser;

use UserFrosting\Sprinkle\Account\Database\Models\User;

/**
 * AlertNotificationController Class
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class AlertNotificationController extends SimpleController
{
    public function pageAlertNotifications(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $venues = Venue::get();
        $activeNotifications = AlertNotification::with('venues')->where('status', 1)->whereHas('venues', function($query) use ($currentUser) {
            $query->where('venue_id', $currentUser->primaryVenue->id);
        })->get();

        if ($authorizer->checkAccess($currentUser, 'uri_site_admin')){
            $user_group = 'admin';
        } else {
            $user_group = 'portal_user';
        }

        // Load validation rules
        $schema = new RequestSchema('schema://requests/alert-notification.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/admin/alert_notifications.html.twig', [
            'validators' => $validator->rules('json', true),
            'activeNotifications' => $activeNotifications,
            'venues' => $venues,
            'user_group' => $user_group
        ]);
    }

    public function createAlertNotification(Request $request, Response $response, $args)
    {
        // Get POST parameters
        $params = $request->getParsedBody();

        error_log(print_r($params, true));

        /**
         * If the array is empty add the alert to all venues
         */
        if (!empty($params['venues_allowed'])) {
            $venues = explode(',', $params['venues_allowed']);
            unset($params['venues_allowed']);
        } else {
            $venues = [];
            $listVenues = Venue::get();
            foreach($listVenues as $venue) {
                array_push($venues, $venue->id);
            }
        }

        error_log(print_r($venues, true));

        // Get the alert message stream
        $ms = $this->ci->alerts;

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Load validation rules
        $schema = new RequestSchema('schema://requests/alert-notification.yaml');

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

        // Add a new Alert
        $alert = new AlertNotification;
        $alert->title = $data['title'];
        $alert->message = $data['message'];
        $alert->status = $data['status'];
        $alert->set_date = Carbon::now()->timestamp;
        $alert->link = $data['link'];

        $alert->save();

        // Add alert to venue
        $alert->venues()->sync($venues);
        // Save user again
        $alert->save();

        $users = [];
        $allUsers = User::where('group_id', '!=', 3)->get();
        foreach($allUsers as $user) {
            array_push($users, $user->id);
        }

        // Add alert to user
        $alert->users()->sync($users);
        // Save user again
        $alert->save();

        // send message back to the user
        $ms->addMessageTranslated('success', 'Alert was successfully created');

        return $response->withJson([], 200);
    }

    public function updateAlertNotification(Request $request, Response $response, $args)
    {
        // Get POST parameters
        $params = $request->getParsedBody();

        error_log(print_r($params['update_venues_allowed'], true));

        /**
         * If the array is empty add the alert to all venues
         */
        if (!empty($params['update_venues_allowed'])) {
            $venues = explode(',', $params['update_venues_allowed']);
            unset($params['update_venues_allowed']);
        } else {
            $venues = [];
            $listVenues = Venue::get();
            foreach($listVenues as $venue) {
                array_push($venues, $venue->id);
            }
        }

        // Get the alert message stream
        $ms = $this->ci->alerts;

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Load validation rules
        $schema = new RequestSchema('schema://requests/alert-notification.yaml');

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

        // Get current alert
        $alert = AlertNotification::find($data['alert_notification_id']);

        // If you deactivate the alert the users are removed from the alert
        if ($data['status'] == 0 && $alert->status == 1)
            $alert->users()->detach();

        // If you activate the alert the users are added to the alert
        if ($data['status'] == 1 && $alert->status == 0) {
            $users = [];
            $allUsers = User::where('group_id', '!=', 3)->get();
            foreach($allUsers as $user) {
                array_push($users, $user->id);
            }

            // Add alert to user
            $alert->users()->sync($users);
        }

        // Add a new Alert
        $alert->title = $data['title'];
        $alert->message = $data['message'];
        $alert->status = $data['status'];
        $alert->link = $data['link'];

        $alert->save();

        // Add alert to venue
        $alert->venues()->sync($venues);
        // Save user again
        $alert->save();

        // send message back to the user
        $ms->addMessageTranslated('success', 'Alert was successfully updated');

        return $response->withJson([], 200);
    }

    public function deleteAlertNotification(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Load validation rules
        $schema = new RequestSchema('schema://requests/company-create.yaml');

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * get the venue to be deleted
         */
        $alert_to_delete = AlertNotification::find($args['alert_id']);

        /**
         * delete the venue
         * NOTE: deletion of all associated child objects is handled within the Venue model (Venue.php) by the delete function
         */
        $alert_to_delete->delete();
        $alert_to_delete->users()->detach();
        $alert_to_delete->venues()->detach();

        /**
         * send message back to the user
         */
        $ms->addMessageTranslated('success', 'Alert was successfully deleted');

        return $response->withJson([], 200);
    }

    public function pageMessageCenter(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $current_user = ExtendedUser::with('alerts')->where('users.id', $currentUser->id)->first();

        $unseen_alert_ids = [];
        $unseen_alerts = [];
        foreach($current_user->alerts as $alert) {
            $unseen_alert_ids[] = $alert->id;

            // Mark the alert as unread
            $alert->read = 0;
            $unseen_alerts[] = $alert;
        }

        $seenNotifications = AlertNotification::with('venues')->where('status', 1)->whereHas('venues', function($query) use ($currentUser) {
            $query->where('venue_id', $currentUser->primaryVenue->id);
        })->whereNotIn('id', $unseen_alert_ids)->get();

        $seen_alerts = [];
        foreach($seenNotifications as $seenNotification) {
            $seenNotification->read = 1;
            $seen_alerts[] = $seenNotification;
        }

        $alerts = array_merge($unseen_alerts, $seen_alerts);

        return $this->ci->view->render($response, 'pages/message_center.html.twig', [
            'alerts' => json_encode($alerts),
        ]);
    }

    public function acknowledgeAlert(Request $request, Response $response, $args)
    {
        $alert_notification = AlertNotification::with('users')->where('id', $args['alert_id'])->first();

        $users = [];
        foreach($alert_notification->users as $user) {
            if ($user->id != $this->ci->currentUser->id) {
                array_push($users, $user->id);
            }
        }

        $alert_notification->users()->sync($users);
    }
}