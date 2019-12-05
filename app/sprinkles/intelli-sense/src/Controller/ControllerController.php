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

use UserFrosting\Sprinkle\ElephantWifi\Database\Models\Controller;

/**
 * ControllerController Class
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class ControllerController extends SimpleController 
{
    public function pageController(Request $request, Response $response, $args)
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

        return $this->ci->view->render($response, 'pages/admin/controllers.html.twig');
    }

    public function formControllerCreate(Request $request, Response $response, $args)
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

        // Get HTPP GET parameters
        $params = $request->getQueryParams();

        /**
         * create an empty dummy controller with some default values if required
         */
        $target_controller = (object) [
            'shared' => 1,
            'url' => 'https://your_hostname:8443'
        ];

        // Load validation rules
        $schema = new RequestSchema('schema://requests/controller-shared-create.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/admin/forms/controller-info-modal.html.twig', [
            'box_id' => $params['box_id'],
            'box_title' => 'Add New Controller',
            'modal_mode' => 'new',
            'submit_button' => 'Add',
            'form_action' => $config['site.uri.public'] . '/admin/controllers/create',
            'target_controller' => $target_controller,
            'validators' => $validator->rules('json', true)
        ]);
    }

    public function formControllerEdit(Request $request, Response $response, $args)
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

        // Get HTPP GET parameters
        $params = $request->getQueryParams();

        /**
         * Get the controller to edit
         */
        $controllerQuery = new Controller;
        $target_controller = $controllerQuery->where('id', $args['controller_id'])->with('wifi_venues')->first();

        // Load validation rules
        $schema = new RequestSchema('schema://requests/controller-shared-update.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        /**
         * render the modal
         */
        return $this->ci->view->render($response, 'pages/admin/forms/controller-info-modal.html.twig', [
            'box_id' => $params['box_id'],
            'box_title' => 'Edit Controller',
            'modal_mode' => 'edit',
            'submit_button' => 'Update',
            'form_action' => $config['site.uri.public'] . '/admin/controllers/update/' . $target_controller->id,
            'target_controller' => $target_controller,
            'validators' => $validator->rules('json', true)
        ]);
    }

    public function addController(Request $request, Response $response, $args)
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
        $schema = new RequestSchema('schema://requests/controller-shared-create.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        /**
         * Create the venue
         */
        $controller = new Controller($data);
        $controller->save();

        /**
         * send message back to the user
         */
        $ms->addMessageTranslated('success', 'Controller was successfully created');

        return $response->withJson([], 200);
    }

    public function updateController(Request $request, Response $response, $args)
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
        $schema = new RequestSchema('schema://requests/controller-shared-update.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        /**
         * find the current controller
         */
        $controllerQuery = new Controller;
        $current_controller = $controllerQuery->where('id', $args['controller_id'])->first();

        /**
         * Update the current_controller data and save
         */
        $current_controller->name = $data['name'];
        $current_controller->url = $data['url'];
        $current_controller->shared = $data['shared'];
        $current_controller->user_name = $data['user_name'];
        $current_controller->password = $data['password'];
        $current_controller->contact = $data['contact'];
        $current_controller->save();

        /**
         * send message back to the user
         */
        $ms->addMessageTranslated('success', 'Controller was successfully updated');

        return $response->withJson([], 200);
    }

    public function deleteController(Request $request, Response $response, $args)
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
        $controller_to_delete = Controller::find($args['controller_id']);

        /**
         * delete the controller
         * NOTE: deletion of all associated child objects should be handled within the Controller model
         */
        $controller_to_delete->delete();

        /**
         * send message back to the user
         */
        $ms->addMessageTranslated('success', 'Controller was successfully deleted');

        return $response->withJson([], 200);
    }
}