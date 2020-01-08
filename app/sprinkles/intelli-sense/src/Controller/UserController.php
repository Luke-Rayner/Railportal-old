<?php

namespace UserFrosting\Sprinkle\IntelliSense\Controller;

use Carbon\Carbon;
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
use UserFrosting\Sprinkle\IntelliSense\Database\Models\ExtendedUser;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\ExtendedUserAux;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Zone;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Company;

use UserFrosting\Sprinkle\Account\Database\Models\Role;
use UserFrosting\Sprinkle\Account\Database\Models\User;

/**
 * UserController Class
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class UserController extends SimpleController 
{
    public function switchVenue(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        $classMapper = $this->ci->classMapper;

        $session = $this->ci->session;

        /**
         * we remove the controller cookie from the SESSION
         */
        unset($_SESSION['controller_cookies']);

        /**
         * check whether the new primary venue exists
         */
        $new_primary_venue = Venue::where('id', $args['requested_venue_id'])->first();
        $old_primary_venue = Venue::where('id', $args['current_venue_id'])->first();

        /**
         * throw an error when new primary venue doesn't exist
         */
        if (!$new_primary_venue) {
            $ms->addMessageTranslated("danger", "Unknown venue.");
            throw new NotFoundException($request, $response);
        } else {
            /**
             * pass the venue name to the alert
             */
            $data = array('name' => $new_primary_venue['name']);
            $ms->addMessageTranslated("success", "User switched venue.", $data);
        }

        /**
         * update user
         */
        $primary_venue_id['primary_venue_id'] = $args['requested_venue_id'];
        $currentUser->fill($primary_venue_id);
        $currentUser->save();

        $result = 'OK';

        /**
         * when you are switching between wifi and geo-sense venues we need to redirect them to the correct page
         */
        if ($new_primary_venue->tracking_venue == 1) {
            if ($new_primary_venue->wifi_venue != 1)
                $result = 'geo-sense/dashboard';
        }
        else {
            if ($new_primary_venue->tracking_venue != 1)
                $result = 'wifi/dashboard';
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($result, 200, JSON_PRETTY_PRINT);
    }

    public function formSessionExpiryTimeEdit(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_account_settings')) {
            throw new NotFoundException($request, $response);
        }

        // Load validation rules
        $schema = new RequestSchema('schema://requests/user_session_expiry_time_update.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/forms/user-session_timeout-modal.html.twig', [
            "box_id" => $args['box_id'],
            "box_title" => "Update session expiry time",
            "submit_button" => "Update",
            "form_action" => $config['site.uri.public']. "/user/session_expiry_time/u",
            "session_timeout_validators" => $validator->rules('json', true)
        ]);
    }

    public function updateSessionExpiryTime(Request $request, Response $response, $args) 
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        /** @var \UserFrosting\Sprinkle\Core\Util\ClassMapper $classMapper */
        $classMapper = $this->ci->classMapper;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_account_settings')) {
            throw new NotFoundException($request, $response);
        }

        // Get POST parameters
        $params = $request->getParsedBody();

        // Load the request schema
        $schema = new RequestSchema('schema://requests/user_session_expiry_time_update.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        $classMapper->staticMethod('user', 'where', 'users.id', $currentUser->id)
            ->update(array('session_expiry_time' => $data['session_expiry_time']));

        // send message back to the user
        $ms->addMessageTranslated("success", "New session expiry time has been stored");

        return $response->withJson([], 200);
    }

    public function pageUsers(Request $request, Response $response, $args)
    {
        /** @var \UserFrosting\Sprinkle\Account\Authorize\AuthorizationManager $authorizer */
        $authorizer = $this->ci->authorizer;

        /** @var \UserFrosting\Sprinkle\Account\Database\Models\Interfaces\UserInterface $currentUser */
        $currentUser = $this->ci->currentUser;

        // Access-controlled page
        if (!$authorizer->checkAccess($currentUser, 'uri_account_settings')) {
            throw new ForbiddenException();
        }

        return $this->ci->view->render($response, 'pages/users/users.html.twig');
    }

    public function formUserCreate(Request $request, Response $response, $args)
    {
        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        // Get a list of all locales
        $locale_list = $config['site.locales.available'];

        /**
         * if active user is a global admin we get all venues,
         * if user is a site admin we only get venues the user has access to
         */
        if ($authorizer->checkAccess($currentUser, 'uri_system_admin')) {
            // Get a list of all venues
            $venues = Venue::with('zones')->orderBy('name', 'asc')->get();
        } else {
            // Get a list of all venues the current user has access to
            $venues = $currentUser->getVenues();
        }

        /**
         * Get a list of all zones
         */
        $zones = Zone::get();

        $roles = Role::get();

        /**
         * Get a list of all companies
         */
        $companies = Company::orderBy('name', 'asc')->get();

        $schema = new RequestSchema('schema://requests/user-create.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/forms/create-user-form.html.twig', [
            "box_title" => "Create User",
            "submit_button" => "Create user",
            "form_action" => $config['site.uri.public'] . "/users",
            "locales" => $locale_list,
            "venues" => $venues,
            "zones" => $zones,
            "groups" => $roles,
            "companies" => $companies,
            "buttons" => [
                "hidden" => [
                    "edit", "delete"
                ]
            ],
            "validators" => $validator->rules('json', true)
        ]);
    }

    public function formUserEdit(Request $request, Response $response, $args)
    {
        $classMapper = $this->ci->classMapper;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_users')) {
            throw new NotFoundException($request, $response);
        }

        // Get the user to edit
        // $target_user = User::find($args['user_id']);
        // $target_user = $classMapper->staticMethod('user', 'where', 'users.id', $args['user_id'])->first();
        // GET parameters
        $params = $request->getQueryParams();
        $target_user = $this->getUserFromParams($params);

        // Get a list of all roles
        $roles = Role::get();

        // Get a list of all locales
        $locale_list = $config['site.locales.available'];

        /**
         * if active user is a global admin we get all venues,
         * if user is a site admin we only get venues the user has access to
         */
        if ($authorizer->checkAccess($currentUser, 'uri_system_admin')) {
            // Get a list of all venues
            $venues = Venue::with('zones')->orderBy('name', 'asc')->get();
        } else {
            // Get a list of all venues the current user has access to
            $venues = $currentUser->getVenues();
        }

        // Get a list of venues the target user has access to
        $allowed_venues = $target_user->getVenues();

        /**
         * create an array containing the id's of the venues the target user has access to
         */
        $allowed_venues_ids = array();
        foreach ($allowed_venues as $allowed_venue) {
            $allowed_venues_ids[] = $allowed_venue->id;
        }

        /**
         * Get a list of all zones
         */
        $zones = Zone::get();

        /**
         * Get a list of all companies
         */
        $companies = Company::orderBy('name', 'asc')->get();

        // Determine which groups this user is a member of
        $user_groups = $target_user->getRoles();
        foreach ($roles as $role){
            $group_id = $role->id;
            $group_list[$group_id] = $role->export();
            if (isset($user_groups[$group_id]))
                $group_list[$group_id]['member'] = true;
            else
                $group_list[$group_id]['member'] = false;
        }

        /**
         * get a collection containing the zones the target user has access to
         */
        $zones_access_list = $target_user->zones;

        /**
         * create an array containing the id's of the zones the target user has access to
         */
        $zones_access_ids = array();
        foreach ($zones_access_list as $zones_access) {
            $zones_access_ids[] = $zones_access->id;
        }

        // Load validator rules
        $schema = new RequestSchema('schema://requests/user-update.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        // var_dump($target_user);

        return $this->ci->view->render($response, 'pages/forms/create-user-form.html.twig', [
            "box_title" => "Edit User",
            "submit_button" => "Update user",
            "form_action" => $config['site.uri.public'] . "/users/u/" . $target_user->id,
            "target_user" => $target_user,
            "groups" => $group_list,
            "locales" => $locale_list,
            "venues" => $venues,
            "zones" => $zones,
            "zones_access" => $zones_access_list,
            "zones_access_ids" => $zones_access_ids,
            "companies" => $companies,
            "allowed_venues" => $allowed_venues,
            "allowed_venues_ids" => $allowed_venues_ids,
            "buttons" => [
                "hidden" => [
                    "edit", "enable", "delete", "activate"
                ]
            ],
            "validators" => $validator->rules('json', true)
        ]);
    }

    public function createUser(Request $request, Response $response, $args)
    {
        // Get POST parameters
        $params = $request->getParsedBody();

        /** @var \UserFrosting\Sprinkle\Core\Util\ClassMapper $classMapper */
        $classMapper = $this->ci->classMapper;

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

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
         * Extract and remove allowed venues and zones, place both in an array
         * same applies for the full_venue_view_allowed value
         *
         * There must be a cleaner way to do this instead of bypassing the auth rules for the admin group
         * but it works..
         */
        if (isset($params['venues_allowed'])) {
            $venues_allowed = explode(',', $params['venues_allowed']);
            unset($params['venues_allowed']);
        }
        if (isset($params['zones_allowed'])) {
            $zones_allowed = explode(',', $params['zones_allowed']);
            unset($params['zones_allowed']);
        }
        if (isset($params['full_venue_view_allowed'])) {
            $full_venue_view_allowed = $params['full_venue_view_allowed'];
            unset($params['full_venue_view_allowed']);
        }

        var_dump($venues_allowed);

        // Load validation rules
        $schema = new RequestSchema('schema://requests/user-create.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        // Perform desired data transformations on required fields.  Is this a feature we could add to Fortress?
        $data['flag_verified'] = 1;
        // Set password as empty on initial creation.  We will then send email so new user can set it themselves via secret token
        $data['password'] = "";

        // Check if username or email already exists
        if (User::where('user_name', $data['user_name'])->first()){
            $ms->addMessageTranslated("danger", "This user name is already in use, please select a new one.");
            $error = true;
        }

        if (User::where('email', $data['email'])->first()){
            $ms->addMessageTranslated("danger", "This email is already in use, please select a new one.");
            $error = true;
        }

        // Halt on any validation errors
        if ($error) {
            return $response->withJson([], 400);
        }

        // Set default values if not specified or not authorized
        if (!isset($data['locale'])) {
            $data['locale'] = 'en_US';
        }

        if (!isset($data['group_id'])) {
            $data['group_id'] = 3;
        }

        // Set groups to default groups if not specified or not authorized to set groups
        if (!isset($data['groups'])) {
            $data['groups'][3] = "1";
        }

        // set the last_name
        $data['last_name'] = '';

        // All checks passed!  log events/activities, create user, and send verification email (if required)
        // Begin transaction - DB will be rolled back if an exception occurs
        Capsule::transaction(function () use ($classMapper, $data, $ms, $config, $currentUser, $venues_allowed, $zones_allowed) {
            // Create the user
            $user = $classMapper->createInstance('user', $data);
            $user->save();

            // Create activity record
            $this->ci->userActivityLogger->info("User {$currentUser->user_name} created a new account for {$user->user_name}.", [
                'type' => 'account_create',
                'user_id' => $currentUser->id,
            ]);            

            $user_role_ids = [$data['group_id']];
            foreach ($data['groups'] as $group_id => $is_member) {
                if ($is_member == "1"){
                    array_push($user_role_ids, $group_id);
                }
            }
            // Attach default roles
            $user->roles()->sync($user_role_ids);

            // $user_ = ExtendedUser::find($user->id);

            if (isset($venues_allowed)) {
                if (!in_array($user->aux->primary_venue_id, $venues_allowed)) {
                    $venues_allowed[] = $user->primary_venue_id;
                }

                $user->venues()->sync($venues_allowed);
            }

            if (isset($zones_allowed)) {
                $user->zones()->sync($zones_allowed);
            }

            $user->save();

            // Try to generate a new password request
            $passwordRequest = $this->ci->repoPasswordReset->create($user, $config['password_reset.timeouts.create']);

            // If the password_mode is manual, do not send an email to set it. Else, send the email.
            if ($data['password'] === '') {
                // Create and send welcome email with password set link
                $message = new TwigMailMessage($this->ci->view, 'mail/password-create.html.twig');

                $message->from($config['address_book.admin'])
                    ->addEmailRecipient(new EmailRecipient($user->email, $user->full_name))
                    ->setFromEmail($config['address_book.admin'])
                    ->setReplyEmail($config['address_book.admin'])
                    ->addParams([
                        'user' => $user,
                        'create_password_expiration' => $config['password_reset.timeouts.create'] / 3600 . ' hours',
                        'token' => $passwordRequest->getToken(),
                    ]);

                $this->ci->mailer->send($message);
            }

            $ms->addMessageTranslated('success', 'USER.CREATED', $data);
        });

        return $response->withJson([], 200);
    }

    public function updateUser(Request $request, Response $response, $args)
    {
        /** @var \UserFrosting\Sprinkle\Core\Util\ClassMapper $classMapper */
        $classMapper = $this->ci->classMapper;

        $user = $classMapper->getClassMapping('user')
            ::where('users.id', $args['user_id'])
            ->first();

        if (!$user) {
            throw new NotFoundException();
        }

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        // Get PUT parameters
        $params = $request->getParsedBody();

        // Get the current user
        $currentUser = $this->ci->currentUser;

        /**
         * Extract and remove allowed venues, place in an array
         *
         * There must be a cleaner way to do this instead of bypassing the auth rules for the admin group
         * but it works..
         *
         * When using one of this variable later on, first check whether they are set or not!
         */
        if (isset($params['venues_allowed'])) {
            $venues_allowed = explode(',', $params['venues_allowed']);
            unset($params['venues_allowed']);
        }
        if (isset($params['zones_allowed'])) {
            $zones_allowed = explode(',', $params['zones_allowed']);
            unset($params['zones_allowed']);
        }
        if (isset($params['full_venue_view_allowed'])) {
            $full_venue_view_allowed = $params['full_venue_view_allowed'];
            unset($params['full_venue_view_allowed']);
        }

        /** @var \UserFrosting\Sprinkle\Core\Alert\AlertStream $ms */
        $ms = $this->ci->alerts;

        // Load the request schema
        $schema = new RequestSchema('schema://requests/user-update.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        $error = false;

        // Validate request data
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);
            $error = true;
        }

        // Only the master account can edit the master account!
        if (
            ($user->id == $config['reserved_user_ids.master']) &&
            ($currentUser->id != $config['reserved_user_ids.master'])
        ) {
            throw new ForbiddenException();
        }

        // Check if email already exists
        if (
            isset($data['email']) &&
            $data['email'] != $user->email &&
            $classMapper->getClassMapping('user')::findUnique($data['email'], 'email')
        ) {
            $ms->addMessageTranslated('danger', 'EMAIL.IN_USE', $data);
            $error = true;
        }

        if ($error) {
            return $response->withJson([], 400);
        }

        if (isset($data['group_id']) && $data['group_id'] == 0) {
            $data['group_id'] = null;
        }

        // Begin transaction - DB will be rolled back if an exception occurs
        Capsule::transaction(function () use ($classMapper, $data, $user, $data_extended_user, $currentUser, $venues_allowed, $zones_allowed, $full_venue_view_allowed) {

            // Update user groups
            if (isset($data['groups'])){
                $user_role_ids = [$data['group_id']];
                foreach ($data['groups'] as $group_id => $is_member) {
                    if ($is_member == "1"){
                        array_push($user_role_ids, $group_id);
                    }
                }
                // Attach default roles
                $user->roles()->sync($user_role_ids);
            }
            unset($data['groups']);

            // Update the user records
            $user->fill($data);

            if (isset($venues_allowed)) {
                if (!in_array($user->primary_venue_id, $venues_allowed)) {
                    $venues_allowed[] = $user->primary_venue_id;
                }

                $user->venues()->sync($venues_allowed);
            }

            if (isset($zones_allowed)) {
                $user->zones()->sync($zones_allowed);
            }

            if (isset($full_venue_view_allowed)) {
                $user->full_venue_view_allowed = $full_venue_view_allowed;
            }

            $user->save();

            // Create activity record
            $this->ci->userActivityLogger->info("User {$currentUser->user_name} updated basic account info for user {$user->user_name}.", [
                'type'    => 'account_update_info',
                'user_id' => $currentUser->id,
            ]);
        });

        $ms->addMessageTranslated('success', 'DETAILS_UPDATED', [
            'user_name' => $user->user_name,
        ]);

        return $response->withJson([], 200);
    }

    /**
     * Processes the request to send a user a password reset email.
     *
     * Processes the request from the user update form, checking that:
     * 1. The target user's new email address, if specified, is not already in use;
     * 2. The logged-in user has the necessary permissions to update the posted field(s);
     * 3. We're not trying to disable the master account;
     * 4. The submitted data is valid.
     * This route requires authentication.
     *
     * Request type: POST
     *
     * @param Request  $request
     * @param Response $response
     * @param array    $args
     *
     * @throws NotFoundException  If user is not found
     * @throws ForbiddenException If user is not authozied to access page
     */
    public function createPasswordReset(Request $request, Response $response, $args)
    {
        // Get the username from the URL
        $user = $this->getUserFromParams($args);

        if (!$user) {
            throw new NotFoundException();
        }

        /** @var \UserFrosting\Sprinkle\Account\Authorize\AuthorizationManager $authorizer */
        $authorizer = $this->ci->authorizer;

        /** @var \UserFrosting\Sprinkle\Account\Database\Models\Interfaces\UserInterface $currentUser */
        $currentUser = $this->ci->currentUser;

        // Access-controlled resource - check that currentUser has permission to edit "password" for this user
        if (!$authorizer->checkAccess($currentUser, 'uri_users', [
            'user' => $user,
            'fields' => ['password'],
        ])) {
            throw new ForbiddenException();
        }

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        /** @var \UserFrosting\Sprinkle\Core\Alert\AlertStream $ms */
        $ms = $this->ci->alerts;

        // Begin transaction - DB will be rolled back if an exception occurs
        Capsule::transaction(function () use ($user, $config) {

            // Create a password reset and shoot off an email
            $passwordReset = $this->ci->repoPasswordReset->create($user, $config['password_reset.timeouts.reset']);

            // Create and send welcome email with password set link
            $message = new TwigMailMessage($this->ci->view, 'mail/password-reset.html.twig');

            $message->from($config['address_book.admin'])
                    ->addEmailRecipient(new EmailRecipient($user->email, $user->full_name))
                    ->setFromEmail($config['address_book.admin'])
                    ->setReplyEmail($config['address_book.admin'])
                    ->addParams([
                        'user'         => $user,
                        'token'        => $passwordReset->getToken(),
                        'request_date' => Carbon::now()->format('Y-m-d H:i:s'),
                    ]);

            $this->ci->mailer->send($message);
        });

        $ms->addMessageTranslated('success', 'PASSWORD.FORGET.REQUEST_SENT', [
            'email' => $user->email,
        ]);

        return $response->withJson([], 200);
    }

    protected function getUserFromParams($params)
    {
        // Load the request schema
        $schema = new RequestSchema('schema://requests/user/get-by-username.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and throw exception on validation errors.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            // TODO: encapsulate the communication of error messages from ServerSideValidator to the BadRequestException
            $e = new BadRequestException();
            foreach ($validator->errors() as $idx => $field) {
                foreach ($field as $eidx => $error) {
                    $e->addUserMessage($error);
                }
            }

            throw $e;
        }

        /** @var \UserFrosting\Sprinkle\Core\Util\ClassMapper $classMapper */
        $classMapper = $this->ci->classMapper;

        // Get the user to delete
        $user = $classMapper->getClassMapping('user')
            ::where('user_name', $data['user_name'])
            ->first();

        return $user;
    }
}