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
use UserFrosting\Sprinkle\Site\Account\Registration;
use UserFrosting\Sprinkle\Account\Controller\Exception\SpammyRequestException;
use UserFrosting\Sprinkle\Account\Facades\Password;
use UserFrosting\Sprinkle\Account\Util\Util as AccountUtil;
use UserFrosting\Sprinkle\IntelliSense\Controller\SimpleController;
use UserFrosting\Sprinkle\Core\Mail\EmailRecipient;
use UserFrosting\Sprinkle\Core\Mail\TwigMailMessage;
use UserFrosting\Sprinkle\Core\Util\Captcha;
use UserFrosting\Support\Exception\BadRequestException;
use UserFrosting\Support\Exception\ForbiddenException;
use UserFrosting\Support\Exception\NotFoundException;
use UserFrosting\Sprinkle\Account\Authenticate\Exception\InvalidCredentialsException;

/**
 * AccountController Class
 *
 * Controller class for /account/* URLs.  Handles account-related activities, including login, registration, password recovery, and account settings.
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class AccountController extends SimpleController 
{
    /**
     * Render the IntelliSense home page
     * No AUTH required
     * Request type: GET
     */
    public function pageHome(Request $request, Response $response, $args)
    {
        // Authentication Handler
        $authenticator = $this->ci->authenticator;

        // Forward to home page if user is already logged in
        if ($authenticator->check()) {
            $redirect = $this->ci->get('redirect.onAlreadyLoggedIn');

            return $redirect($request, $response, $args);
        }

        return $this->ci->view->render($response, 'pages/home.html.twig');
    }

    /**
     * Renders the login page for IntelliSense.
     * No AUTH required
     * Request type: GET
     */
    public function pageLogin(Request $request, Response $response, $args)
    {
        // Load validation rules
        $schema = new RequestSchema('schema://requests/login.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/account/login.html.twig', [
            'validators' => $validator->rules('json', true)
        ]);
    }

    /**
     * Processes an account login request.
     * No AUTH required
     * Request type: POST
     */
    public function login(Request $request, Response $response, $args)
    {
        /** @var \UserFrosting\Sprinkle\Core\Alert\AlertStream $ms */
        $ms = $this->ci->alerts;

        /** @var \UserFrosting\Sprinkle\Account\Database\Models\Interfaces\UserInterface $currentUser */
        $currentUser = $this->ci->currentUser;

        /** @var \UserFrosting\Sprinkle\Account\Authenticate\Authenticator $authenticator */
        $authenticator = $this->ci->authenticator;

        // Return 200 success if user is already logged in
        if ($authenticator->check()) {
            $ms->addMessageTranslated('warning', 'LOGIN.ALREADY_COMPLETE');

            return $response->withJson([], 200);
        }

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        // Get POST parameters
        $params = $request->getParsedBody();

        // Load the request schema
        $schema = new RequestSchema('schema://requests/login.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        // Determine whether we are trying to log in with an email address or a username
        $isEmail = filter_var($data['user_name'], FILTER_VALIDATE_EMAIL);

        // Throttle requests

        /** @var \UserFrosting\Sprinkle\Core\Throttle\Throttler $throttler */
        $throttler = $this->ci->throttler;

        $userIdentifier = $data['user_name'];

        $throttleData = [
            'user_identifier' => $userIdentifier
        ];

        $delay = $throttler->getDelay('sign_in_attempt', $throttleData);
        if ($delay > 0) {
            $ms->addMessageTranslated('danger', 'RATE_LIMIT_EXCEEDED', [
                'delay' => $delay
            ]);

            return $response->withJson([], 429);
        }

        // Log throttleable event
        $throttler->logEvent('sign_in_attempt', $throttleData);

        // If credential is an email address, but email login is not enabled, raise an error.
        // Note that we do this after logging throttle event, so this error counts towards throttling limit.
        if ($isEmail && !$config['site.login.enable_email']) {
            $ms->addMessageTranslated('danger', 'USER_OR_PASS_INVALID');

            return $response->withJson([], 403);
        }

        // Try to authenticate the user.  Authenticator will throw an exception on failure.
        /** @var \UserFrosting\Sprinkle\Account\Authenticate\Authenticator $authenticator */
        $authenticator = $this->ci->authenticator;

        $currentUser = $authenticator->attempt(($isEmail ? 'email' : 'user_name'), $userIdentifier, $data['password'], $data['rememberme']);

        $ms->addMessageTranslated('success', 'WELCOME', $currentUser->export());

        // Set redirect, if relevant
        $redirectOnLogin = $this->ci->get('redirect.onLogin');

        return $redirectOnLogin($request, $response, $args);
    }

    /**
     * Renders the forgot-password page for IntelliSense.
     * No AUTH required
     * Request type: GET
     */
    public function pageForgotPassword(Request $request, Response $response, $args)
    {
    	// Load validation rules
        $schema = new RequestSchema('schema://requests/forgot-password.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/account/forgot-password.html.twig', [
            'validators' => $validator->rules('json', true)
        ]);
    }

    /**
     * Processes a request to email a forgotten password reset link to the user.
     * No AUTH required
     * Request type: POST
     */
    public function forgotPassword(Request $request, Response $response, $args)
    {
        $ms = $this->ci->alerts;

        $classMapper = $this->ci->classMapper;
        
        // Get POST parameters
        $params = $request->getParsedBody();

        $config = $this->ci->config;
        
        // Load the request schema
        $schema = new RequestSchema('schema://requests/forgot-password.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);
        
        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);
            return $response->withJson([], 400);
        }

        // Throttle requests
        $throttler = $this->ci->throttler;

        $throttleData = [
            'email' => $data['email']
        ];
        $delay = $throttler->getDelay('password_reset_request', $throttleData);

        if ($delay > 0) {
            $ms->addMessageTranslated('danger', 'RATE_LIMIT_EXCEEDED', ['delay' => $delay]);

            return $response->withJson([], 429);
        }

        // All checks passed!  log events/activities, update user, and send email
        // Begin transaction - DB will be rolled back if an exception occurs
        Capsule::transaction(function () use ($classMapper, $data, $throttler, $throttleData, $config) {
            // Log throttleable event
            $throttler->logEvent('password_reset_request', $throttleData);

            $user = $classMapper->staticMethod('user', 'where', 'email', $data['email'])->first();

            // Check that the email exists.
            // If there is no user with that email address, we should still pretend like we succeeded, to prevent account enumeration
            if ($user) {
                // Try to generate a new password reset request.
                // Use timeout for "reset password"
                $passwordReset = $this->ci->repoPasswordReset->create($user, $config['password_reset.timeouts.reset']);

                // Create and send email
                $message = new TwigMailMessage($this->ci->view, 'mail/password-reset.html.twig');

                $message->from($config['address_book.admin'])
                        ->addEmailRecipient(new EmailRecipient($user->email, $user->full_name))
                        ->setFromEmail($config['address_book.admin'])
                        ->setReplyEmail($config['address_book.admin'])
                        ->addParams([
                            'user'         => $user,
                            'token'        => $passwordReset->getToken(),
                            'request_date' => Carbon::now()->format('Y-m-d H:i:s')
                        ]);

                $this->ci->mailer->send($message);
            }
        });

        $ms->addMessageTranslated('success', 'PASSWORD.FORGET.REQUEST_SENT', ['email' => $data['email']]);

        return $response->withJson([], 200);
    }

    /**
     * Renders the resend activation page for IntelliSense.
     * No AUTH required
     * Request type: GET
     */
    public function pageResendActivation(Request $request, Response $response, $args)
    {
		// Load validation rules
        $schema = new RequestSchema('schema://requests/resend-activation.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/account/resend-activation.html.twig', [
            'validators' => $validator->rules('json', true)
        ]);
    }

    /**
     * Processes a request to resend the activation email for a new user account.
     * No AUTH required
     * Request type: POST
     */
    public function resendActivation(Request $request, Response $response, $args)
    {
        /** @var \UserFrosting\Sprinkle\Core\Alert\AlertStream $ms */
        $ms = $this->ci->alerts;

        /** @var \UserFrosting\Sprinkle\Core\Util\ClassMapper $classMapper */
        $classMapper = $this->ci->classMapper;

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        // Get POST parameters
        $params = $request->getParsedBody();

        // Load the request schema
        $schema = new RequestSchema('schema://requests/resend-activation.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        // Throttle requests

        /** @var \UserFrosting\Sprinkle\Core\Throttle\Throttler $throttler */
        $throttler = $this->ci->throttler;

        $throttleData = [
            'email' => $data['email']
        ];
        $delay = $throttler->getDelay('verification_request', $throttleData);

        if ($delay > 0) {
            $ms->addMessageTranslated('danger', 'RATE_LIMIT_EXCEEDED', ['delay' => $delay]);

            return $response->withJson([], 429);
        }

        // All checks passed!  log events/activities, create user, and send verification email (if required)
        // Begin transaction - DB will be rolled back if an exception occurs
        Capsule::transaction(function () use ($classMapper, $data, $throttler, $throttleData, $config) {
            // Log throttleable event
            $throttler->logEvent('verification_request', $throttleData);

            // Load the user, by email address
            $user = $classMapper->staticMethod('user', 'where', 'email', $data['email'])->first();

            // Check that the user exists and is not already verified.
            // If there is no user with that email address, or the user exists and is already verified,
            // we pretend like we succeeded to prevent account enumeration
            if ($user && $user->flag_verified != '1') {
                // We're good to go - record user activity and send the email
                $verification = $this->ci->repoVerification->create($user, $config['verification.timeout']);

                // Create and send verification email
                $message = new TwigMailMessage($this->ci->view, 'mail/resend-activation.html.twig');

                $message->from($config['address_book.admin'])
                        ->addEmailRecipient(new EmailRecipient($user->email, $user->full_name))
                        ->setFromEmail($config['address_book.admin'])
                        ->setReplyEmail($config['address_book.admin'])
                        ->addParams([
                            'user'  => $user,
                            'token' => $verification->getToken()
                        ]);

                $this->ci->mailer->send($message);
            }
        });

        $ms->addMessageTranslated('success', 'ACCOUNT.VERIFICATION.NEW_LINK_SENT', ['email' => $data['email']]);

        return $response->withJson([], 200);
    }

    /**
     * Renders the reset password page for IntelliSense.
     * No AUTH required
     * Request type: GET
     */
    public function pageResetPassword(Request $request, Response $response, $args)
    {
        // Insert the user's secret token from the link into the password reset form
        $params = $request->getQueryParams();

        // Load validation rules - note this uses the same schema as "set password"
        $schema = new RequestSchema('schema://requests/set-password.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/account/reset-password.html.twig', [
            'validators' => $validator->rules('json', true),
            'token' => isset($params['token']) ? $params['token'] : '',
        ]);
    }

    /**
     * Processes a request to set the password for a new or current user.
     * No AUTH required
     * Request type: POST
     */
    public function setPassword(Request $request, Response $response, $args)
    {
        /** @var \UserFrosting\Sprinkle\Core\Alert\AlertStream $ms */
        $ms = $this->ci->alerts;

        /** @var \UserFrosting\Sprinkle\Core\Util\ClassMapper $classMapper */
        $classMapper = $this->ci->classMapper;

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        // Get POST parameters
        $params = $request->getParsedBody();

        // Load the request schema
        $schema = new RequestSchema('schema://requests/set-password.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        $forgotPasswordPage = $this->ci->router->pathFor('forgot-password');

        // Ok, try to complete the request with the specified token and new password
        $passwordReset = $this->ci->repoPasswordReset->complete($data['token'], [
            'password' => $data['password']
        ]);

        if (!$passwordReset) {
            $ms->addMessageTranslated('danger', 'PASSWORD.FORGET.INVALID', ['url' => $forgotPasswordPage]);

            return $response->withJson([], 400);
        }

        $ms->addMessageTranslated('success', 'PASSWORD.UPDATED');

        /** @var \UserFrosting\Sprinkle\Account\Authenticate\Authenticator $authenticator */
        $authenticator = $this->ci->authenticator;

        // Log out any existing user, and create a new session
        if ($authenticator->check()) {
            $authenticator->logout();
        }

        // Auto-login the user (without "remember me")
        $user = $passwordReset->user;
        $authenticator->login($user);

        $ms->addMessageTranslated('success', 'WELCOME', $user->export());

        return $response->withJson([], 200);
    }

    /**
     * Processes an new email verification request.
     * No AUTH required
     * Request type: GET
     */
    public function verify(Request $request, Response $response, $args)
    {
        /** @var \UserFrosting\Sprinkle\Core\Alert\AlertStream $ms */
        $ms = $this->ci->alerts;

        /** @var \UserFrosting\Sprinkle\Core\Util\ClassMapper $classMapper */
        $classMapper = $this->ci->classMapper;

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        $loginPage = $this->ci->router->pathFor('sign-in');

        // GET parameters
        $params = $request->getQueryParams();

        // Load request schema
        $schema = new RequestSchema('schema://requests/account-verify.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  This is a GET request, so we redirect on validation error.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withRedirect($loginPage);
        }

        $verification = $this->ci->repoVerification->complete($data['token']);

        if (!$verification) {
            $ms->addMessageTranslated('danger', 'ACCOUNT.VERIFICATION.TOKEN_NOT_FOUND');

            return $response->withRedirect($loginPage);
        }

        $ms->addMessageTranslated('success', 'ACCOUNT.VERIFICATION.COMPLETE');

        // Forward to login page
        return $response->withRedirect($loginPage);
    }

    /**
     * Renders the account setting page for IntelliSense.
     * No AUTH required
     * Request type: GET
     */
    public function pageAccountSettings(Request $request, Response $response, $args)
    {
        $config = $this->ci->config;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        // Load validation rules
        $schema = new RequestSchema('schema://requests/account-settings.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/account/account-settings.html.twig', [
            "locales" => $config['site.locales.available'],
            "validators" => $validator->rules('json', true)
        ]);
    }

    /**
     * Processes a request to set the current user account settings.
     * No AUTH required
     * Request type: POST
     */
    public function accountSettings(Request $request, Response $response, $args)
    {
        /** @var \UserFrosting\Sprinkle\Core\Alert\AlertStream $ms */
        $ms = $this->ci->alerts;

        /** @var \UserFrosting\Sprinkle\Core\Util\ClassMapper $classMapper */
        $classMapper = $this->ci->classMapper;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_account_settings')) {
            throw new NotFoundException($request, $response);
        }

        // Remove csrf_token
        unset($data->csrf_token);

        // Get POST parameters
        $params = $request->getParsedBody();

        // Load the request schema
        $schema = new RequestSchema('schema://requests/account-settings.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        if (!isset($data['passwordcheck']) || !Password::verify($data['passwordcheck'], $currentUser->password)) {
            $ms->addMessageTranslated("danger", "The password you entered was invalid");
            throw new ForbiddenException();
        }

        // Validate new email, if specified
        if (isset($data['email']) && $data['email'] != $currentUser->email) {
            // Check if address is in use
            if ($classMapper->staticMethod('user', 'where', 'email', $data['email'])->first()){
                $ms->addMessageTranslated("danger", "This email is already in use.", $data);
                return $response->withJson([], 400);
            }
        } else {
            $data['email'] = $currentUser->email;
        }

        // Validate password, if specified and not empty
        if (!isset($data['password']) && empty($data['password'])) {
            // Do not pass to model if no password is specified
            unset($data['password']);
            unset($data['passwordc']);
        }

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        // If a new password was specified, hash it.
        if (isset($data['password']))
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);

        // Remove passwordc, passwordcheck
        unset($data['passwordc']);
        unset($data['passwordcheck']);

        // Looks good, let's update with new values!
        foreach ($data as $name => $value){
            $currentUser->$name = $value;
        }

        $currentUser->store();

        $ms->addMessageTranslated("success", "Accounts settins were successfully updated");

        return $response->withJson([], 200);
    }
}