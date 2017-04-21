<?php

// For Support email: support@rocketship.it
// For usage: https://docs.rocketship.it/2-0/
namespace Impgds\RocketShipIt;

class RocketShipIt
{
    public $carrier = '';
    public $data = array();
    public $response = array();
    public $debugInformation = array();
    public $binWorkingDir = '';
    public $binPath = '';
    public $command = './RocketShipIt';
    public $options = array(
        // change to your own endpoint if running as a service
        'http_endpoint' => '',
    );

    public function __construct()
    {
        $this->binWorkingDir = __DIR__. '/../../';
        $this->binPath = $this->binWorkingDir. 'RocketShipIt';
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->binPath = $this->binPath. '.exe';
            $this->command = 'RocketShipIt.exe';
        }
    }

    public function request($carrier, $action, $params)
    {
        $data['params'] = array(
            'packages' => array(),
            'customs' => array(),
            //'debug' => true,
        );

        $data['carrier'] = $carrier;
        $data['action'] = $action;
        $data['params'] = array_merge($data['params'], $params);

        return $this->irequest($data);
    }

    public function irequest($data)
    {
        if ($this->options['http_endpoint']) {
            return $this->httpRequest($data);
        }
        
        if (function_exists('rocketshipit_request')) {
            // RocketShipIt PHP extension is installed, use that.
            $result = rocketshipit_request(json_encode($data));
            $resp = json_decode($result, true);
            if (!$resp) {
                return array(
                    'meta' => array(
                        'code' => 500,
                        'error_message' => 'Unable to parse JSON, got: '. $result,
                    ),
                );

            }
            $this->response = $resp;

            return $this->response;
        }
        if (file_exists(__DIR__. '/RocketShipIt')) {
            $this->binWorkingDir = __DIR__.'/';
            $this->binPath = __DIR__. '/RocketShipIt';
        }

        if (!file_exists($this->binPath)) {
            echo 'RocketShipIt binary file is missing.  Please make sure to upload all files.'. "\n";
            exit;
        }

        return $this->binstubRequest($data);
    }

    public function httpRequest($data)
    {
        $dataString = json_encode($data);

        $ch = curl_init($this->options['http_endpoint']);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $dataString);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'x-api-key: '. $this->data['params']['apiKey'],
            'Content-Type: application/json',
            )
        );

        $result = curl_exec($ch);

        $resp = json_decode($result, true);
        if (!$resp) {
            return array(
                'meta' => array(
                    'code' => 500,
                    'error_message' => 'Unable to parse JSON, got: '. $result,
                ),
            );

        }

        $this->response = $resp;

        if (isset($this->response['meta']['debug_information'])) {
            $this->debugInformation = $this->response['meta']['debug_information'];
            unset($this->response['meta']['debug_information']);
        }

        return $this->response;
    }

    public function binstubRequest($data)
    {
        $descriptorspec = array(
           0 => array('pipe', 'r'),  // stdin is a pipe that the child will read from
           1 => array('pipe', 'w'),  // stdout is a pipe that the child will write to
           2 => array('pipe', 'a')   // stderr is a file to write to
        );

        $pipes = array();

        if (!is_executable($this->binPath)) {
            chmod($this->binPath, 0755);
        }

        if (!is_executable($this->binPath)) {
            return array(
                'meta' => array(
                    'code' => 500,
                    'error_message' => 'RocketShipIt binary is missing or not executable.',
                ),
            );
        }

        $process = proc_open($this->command, $descriptorspec, $pipes, $this->binWorkingDir);

        if (is_resource($process)) {
            fwrite($pipes[0], json_encode($data));
            fclose($pipes[0]);

            $result = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            // It is important that you close any pipes before calling
            // proc_close in order to avoid a deadlock
            fclose($pipes[2]);
            $returnValue = proc_close($process);
        }

        $resp = json_decode($result, true);
        if (!$resp) {
            return array(
                'meta' => array(
                    'code' => 500,
                    'error_message' => 'Unable to communicate with RocketShipIt binary or parse JSON, got: '. $result,
                ),
            );

        }

        $this->response = $resp;

        return $this->response;
    }
}
