<?php

namespace UserFrosting\Sprinkle\EnviroSense\Controller;

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
use UserFrosting\Sprinkle\EnviroSense\Database\Models\EnviroSensor;
use UserFrosting\Sprinkle\EnviroSense\Database\Models\EnviroSensorModule;
use UserFrosting\Sprinkle\EnviroSense\Database\Models\EnviroSensorModuleType;

/**
 * EnviroSensorController Class
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class EnviroSensorController extends SimpleController 
{
    public function pageEnviroSensors(Request $request, Response $response, $args)
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

        $venues = Venue::get();

        // Load validation rules
        $schema = new RequestSchema('schema://requests/enviro-sensor-update.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/enviro_sense/admin/enviro_sensors.html.twig', [
            'validators' => $validator->rules('json', true),
            'venues' => $venues
        ]);
    }

    public function addEnviroSensors(Request $request, Response $response, $args)
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
        $schema = new RequestSchema('schema://requests/enviro-sensor-update.yaml');

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

        $data['life_indicator'] = Carbon::now()->addYears(2)->timestamp;

        // Create the enviro_sensor
        $enviro_sensor = new EnviroSensor($data);
        $enviro_sensor->save();

        $enviro_sensor_serial_id = $enviro_sensor->serial_id;

        $curl = curl_init();
        $headers = array(
            'X-User-id: 5244', 
            'X-User-hash:d4d75193d828decdbc532487322a58df',
        );
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_URL, "https://data.uradmonitor.com/api/v1/devices/$enviro_sensor_serial_id");
        $resp = curl_exec($curl);
        curl_close($curl);
        $modules = json_decode($resp, true);

        if (count($modules) != 0) {
            foreach($modules as $key => $value) {
                if ($key != "all" && $key != "timelocal") {
                    $module_type = EnviroSensorModuleType::where('name', $value[0])->first();

                    $enviro_sensor_module = new EnviroSensorModule;
                    $enviro_sensor_module->enviro_sensor_id = $enviro_sensor->id;
                    $enviro_sensor_module->key_name = $key;
                    $enviro_sensor_module->enviro_sensor_module_type_id = $module_type->id;
                    $enviro_sensor_module->save();
                }
            }
        }

        // send message back to the user
        $ms->addMessageTranslated('success', 'Enviro sensor was successfully created');

        return $response->withJson([], 200);
    }

    public function updateEnviroSensor(Request $request, Response $response, $args)
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
        $schema = new RequestSchema('schema://requests/enviro-sensor-update.yaml');

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

        /**
         * find the primary venue for the current user, get it's details and it's locale etc
         */
        $enviroSensorQuery = new EnviroSensor;
        $current_enviro_sensor = $enviroSensorQuery->where('id', $data['id'])->first();

        $current_enviro_sensor->name = $data['name'];
        $current_enviro_sensor->venue_id = $data['venue_id'];
        $current_enviro_sensor->connection_type = $data['connection_type'];
        $current_enviro_sensor->serial_id = $data['serial_id'];
        $current_enviro_sensor->lat = $data['lat'];
        $current_enviro_sensor->lon = $data['lon'];
        $current_enviro_sensor->save();

        /**
         * send message back to the user
         */
        $ms->addMessageTranslated('success', 'Enviro sensor was successfully updated');

        return $response->withJson([], 200);
    }
}