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

use UserFrosting\Sprinkle\IntelliSense\Database\Models\Company;

/**
 * CompanyController Class
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class CompanyController extends SimpleController 
{
    public function pageCompanies(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_company_add')) {
            throw new NotFoundException($request, $response);
        }

        // show the "add new" company button when user has sufficient permissions
        if ($authorizer->checkAccess($currentUser, 'uri_company_add')){
            $shown_buttons = ['new'];
        } else {
            $shown_buttons = [];
        }

        // Load validation rules
        $schema = new RequestSchema('schema://requests/company-create.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/admin/companies.html.twig', [
            'validators' => $validator->rules('json', true),
            'buttons' => [
                'shown' => $shown_buttons
            ]
        ]);
    }

    public function addCompany(Request $request, Response $response, $args)
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

        // Load validation rules
        $schema = new RequestSchema('schema://requests/company-create.yaml');

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_company_add')) {
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

        // Create the company entry
        $company = new Company($data);
        $company->save();

        // send message back to the user
        $ms->addMessageTranslated('success', 'Company was successfully created');

        return $response->withJson([], 200);
    }

    public function updateCompany(Request $request, Response $response, $args)
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

        // Load validation rules
        $schema = new RequestSchema('schema://requests/company-create.yaml');

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_company_update')) {
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

        // Update the company data
        Company::where('id', $data['id'])->update(array('name' => $data['name']));

        // send message back to the user
        $ms->addMessageTranslated('success', 'Company was successfully updated');

        return $response->withJson([], 200);
    }
}