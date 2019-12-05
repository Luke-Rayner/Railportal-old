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

use UserFrosting\Sprinkle\IntelliSense\Database\Models\Event;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\EventCategory;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue;

/**
 * EventController Class
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class EventController extends SimpleController 
{
    public function pageEventCalendar(Request $request, Response $response, $args)
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

        return $this->ci->view->render($response, 'pages/event_calendar.html.twig');
    }

    public function pageAdminEventCalendar(Request $request, Response $response, $args)
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

        return $this->ci->view->render($response, 'pages/event_calendar.html.twig');
    }

    public function pageEvents(Request $request, Response $response, $args)
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

        return $this->ci->view->render($response, 'pages/admin/events.html.twig');
    }

    public function formEventcreate(Request $request, Response $response, $args)
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
        if (!$authorizer->checkAccess($currentUser, 'uri_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        // Load validation rules
        $schema = new RequestSchema('schema://requests/event-create.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        if ($authorizer->checkAccess($currentUser, 'uri_site_admin')){
            $event_categories = EventCategory::get();
        }
        else {
            $event_categories = EventCategory::where('admin_category', 0)->get();
        }

        return $this->ci->view->render($response, 'pages/admin/forms/event-create-modal.html.twig', [
            'validators' => $validator->rules('json', true),
            'categories' => $event_categories,
            'form_action' => $config['site.uri.public'] . '/admin/event'
        ]);
    }

    public function formEventupdate(Request $request, Response $response, $args)
    {
        /// Get the alert message stream
        $ms = $this->ci->alerts;

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        // Load validation rules
        $schema = new RequestSchema('schema://requests/event-create.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        $event_categories = EventCategory::get();
        $target_event = Event::with('event_category')->where('id', $args['event_id'])->first();

        return $this->ci->view->render($response, 'pages/admin/forms/event-create-modal.html.twig', [
            'validators' => $validator->rules('json', true),
            'categories' => $event_categories,
            'target_event' => $target_event,
            'form_action' => $config['site.uri.public'] . '/admin/event/u/' . $args['event_id'],
            'mode' => 'edit'
        ]);
    }

    public function addEvent(Request $request, Response $response, $args)
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
        if (!$authorizer->checkAccess($currentUser, 'uri_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        // Load validation rules
        $schema = new RequestSchema('schema://requests/event-create.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        // Perform desired data transformations on required fields.
        $current_venue = Venue::where('id', $currentUser->primary_venue_id)->first();

        $data['venue_id'] = $currentUser->primary_venue_id;

        $start_date = new Carbon($data['start_date'], $current_venue['time_zone']);
        $end_date = new Carbon($data['end_date'], $current_venue['time_zone']);

        $data['start_date'] = $start_date->timestamp;        
        $data['end_date'] = $end_date->timestamp;

        // Create the Tag entry
        $event = new Event($data);
        $event->save();

        $ms->addMessageTranslated('success', 'The event was successfully stored.');

        return $response->withJson([], 200);
    }

    public function updateEvent(Request $request, Response $response, $args)
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
        if (!$authorizer->checkAccess($currentUser, 'uri_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        // Load validation rules
        $schema = new RequestSchema('schema://requests/event-create.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        // Perform desired data transformations on required fields.
        $current_venue = Venue::where('id', $currentUser->primary_venue_id)->first();

        $start_date = new Carbon($data['start_date'], $current_venue['time_zone']);
        $end_date = new Carbon($data['end_date'], $current_venue['time_zone']);

        // Update the tag object
        Event::where('id', $args['event_id'])
            ->update(array(
                'name' => $data['name'], 
                'notes' => $data['notes'], 
                'event_category_id' => $data['event_category_id'],
                'start_date' => $start_date->timestamp,
                'end_date' => $end_date->timestamp,
                'recurring' => $data['recurring'],
                'admin_event' => $data['admin_event'],
                'admin_notes' => $data['admin_notes']
        ));

        $ms->addMessageTranslated('success', 'The event was successfully updated.');

        return $response->withJson([], 200);
    }

    public function deleteEvent(Request $request, Response $response, $args)
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
        if (!$authorizer->checkAccess($currentUser, 'uri_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * get the venue to be deleted
         */
        $event_to_delete = Event::find($args['event_id']);

        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin') && $event_to_delete->event_category->admin_category == 1) {
            throw new NotFoundException($request, $response);
        }

        /**
         * delete the zone
         */
        $event_name = $event_to_delete->name;

        $event_to_delete->delete();

        /**
         * send message back to the user
         */
        $ms->addMessageTranslated('success', 'Event was deleted successfully');

        return $response->withJson([], 200);
    }
}