<?php
namespace WF\API\Accounting;
class ServiceRouter
{

    /**
     * Handle the accounting request routing
     *
     * @param mixed $json The request payload
     *
     * @return array
     * @throws \Exception
     */
    public static function handler (mixed $json): array {
        if (empty($json)) {
            throw new \Exception('Invalid request payload');
        }

        if(!isset($json->method) || !isset($json->class) || !isset($json->service)) {
            return [
              'success' => false,
              'error' => 'ERROR: Must define a class, method and service',
              'data' => []
            ];
        }

        if(strtolower($json->service) === 'accounting') {
            $service = '';
        } else {
            $service = str_replace('quickbooks','QuickBooks',$json->service) . '\\';
        }

        global $current_user;

        $method = $json->method;
        $class = '\WF\API\Accounting\\'.$service.'Services\\'.$json->class;

        $username = $json->data->username ?? $current_user;

        $instance = new $class($username);

        if(method_exists($instance, $method)){
            $result = $instance->$method($json->data);
            return [
              'success' => $result['success'],
              'error' => $result['error'],
              'data' => $result['data']
            ];
        } else {
            return [
              'success' => false,
              'error' => "Method '$method' does not exist in class '$class'",
              'data' => []
            ];
        }
    }
}