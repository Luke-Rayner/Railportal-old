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

use UserFrosting\Sprinkle\IntelliSense\Database\Models\Country;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Region;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Area;

/**
 * NutsClassificationController Class
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class NutsClassificationController extends SimpleController 
{
    public function pageNutsClassification(Request $request, Response $response, $args)
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

        // Get the validation rules for the various forms on this page
        $country_schema = new RequestSchema('schema://requests/country-create.yaml');
        $country_validators = new JqueryValidationAdapter($country_schema, $this->ci->translator);

        $region_schema = new RequestSchema('schema://requests/region-create.yaml');
        $region_validators = new JqueryValidationAdapter($region_schema, $this->ci->translator);

        $area_schema = new RequestSchema('schema://requests/area-create.yaml');
        $area_validators = new JqueryValidationAdapter($area_schema, $this->ci->translator);

        /**
         * get all countries for use in the forms
         */
        $countryQuery = new Country;
        $country_collection = $countryQuery->get();

        /**
         * get all regions for use in the forms
         */
        $regionQuery = new Region;
        $region_collection = $regionQuery->get();

        return $this->ci->view->render($response, 'pages/admin/nuts_classifications.html.twig', [
            'country_validators' => $country_validators->rules('json', true),
            'region_validators' => $region_validators->rules('json', true),
            'area_validators' => $area_validators->rules('json', true),
            "countries" => $country_collection->values()->toArray(),
            "regions" => $region_collection->values()->toArray()
        ]);
    }

    public function addCountry(Request $request, Response $response, $args)
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
        $schema = new RequestSchema('schema://requests/country-create.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        // Create the Country entry
        $country = new Country($data);
        $country->save();

        // send message back to the user
        $ms->addMessageTranslated('success', 'Country was successfully added');

        return $response->withJson([], 200);
    }

    public function updateCountry(Request $request, Response $response, $args)
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
        $schema = new RequestSchema('schema://requests/country-create.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        // Update the Country data
        Country::where('id', $data['id'])
            ->update(array('name' => $data['name']));

        // send message back to the user
        $ms->addMessageTranslated('success', 'Country was successfully updated');

        return $response->withJson([], 200);
    }

    public function addRegion(Request $request, Response $response, $args)
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
        $schema = new RequestSchema('schema://requests/region-create.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        // Create the Region entry
        $region = new Region($data);
        $region->save();

        // send message back to the user
        $ms->addMessageTranslated('success', 'Region was successfully added');

        return $response->withJson([], 200);
    }

    public function updateRegion(Request $request, Response $response, $args)
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
        $schema = new RequestSchema('schema://requests/region-create.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        // Update the Region data
        Region::where('id', $data['id'])
            ->update(array('country_id' => $data['country_id'], 'name' => $data['name']));

        // send message back to the user
        $ms->addMessageTranslated('success', 'Region was successfully updated');

        return $response->withJson([], 200);
    }

    public function addArea(Request $request, Response $response, $args)
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
        $schema = new RequestSchema('schema://requests/area-create.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        // Create the Area entry
        $area = new Area($data);
        $area->save();

        // send message back to the user
        $ms->addMessageTranslated('success', 'Area was successfully added');

        return $response->withJson([], 200);
    }

    public function updateArea(Request $request, Response $response, $args)
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
        $schema = new RequestSchema('schema://requests/area-create.yaml');

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

        // Update the Area data
        Area::where('id', $data['id'])
            ->update(array('region_id' => $data['region_id'], 'name' => $data['name']));

        // send message back to the user
        $ms->addMessageTranslated('success', 'Area was successfully updated');

        return $response->withJson([], 200);
    }
}