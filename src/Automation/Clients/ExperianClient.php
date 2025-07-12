<?php

declare(strict_types=1);

namespace WF\API\Automation\Clients;

use WF\API\Automation\Exceptions\BureauApiException;
use Curl;
use Log;

class ExperianClient extends AbstractBureauClient
{

    /**
     * @throws \WF\API\Automation\Exceptions\BureauApiException
     */
    protected function authenticate(): string
    {
        try {
            // Prepare authentication data
            $authData = json_encode([
              'username' => $this->config['experian_user'],
              'password' => $this->config['experian_password'],
              'client_id' => $this->config['client_id'],
              'client_secret' => $this->config['client_secret']
            ]);

            Log::info('Authenticating with Experian...'.$authData);
            $headers = [
              'Content-Type: application/json',
              'Accept: application/json',
              'Grant_type: password'
            ];

            $response = Curl::post(
              $this->config['token_endpoint'],
              $authData,
              $headers,
              null,
              true // return on error
            );

            // Handle response
            if (is_array($response) && isset($response['http_code'])) {
                $errorMsg = "HTTP code: " . $response['http_code'];
                if ($response['http_code'] == 0) {
                    $errorMsg .= ", cURL error: " . ($response['curl_errno'] ?? "UNKNOWN");
                }

                $responseData = json_decode($response['response'] ?? '', true);
                if (isset($responseData['errors'])) {
                    foreach ($responseData['errors'] as $error) {
                        $errorMsg .= ", Error: " . $error['errorType'] . ", " . $error['message'];
                    }
                }

                throw new BureauApiException('Experian authentication failed: ' . $errorMsg);
            }

            $data = json_decode($response, true);

            if (!isset($data['access_token'])) {
                $errorMsg = 'Failed to obtain Experian access token';
                if (isset($data['errors'])) {
                    $errorMsg = $data['errors'][0]['errorCode'] . " " . $data['errors'][0]['message'];
                }
                throw new BureauApiException($errorMsg);
            }

            // Store refresh token if available
            if (isset($data['refresh_token'])) {
                $this->refreshToken = $data['refresh_token'];
            }

            return $data['access_token'];

        } catch (\Exception $e) {
            Log::error($e);
            throw new BureauApiException('Experian authentication failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function buildRequestPayload(array $consumers): array
    {
        $primary = $consumers[0];
        $hasCoApp = isset($consumers[1]);

        $payload = [
          'consumerPii' => [
            'primaryApplicant' => [
              'name' => [
                'firstName' => $primary['firstName'],
                'lastName' => $primary['lastName'],
              ],
              'dob' => [
                'dob' => date("mdY", strtotime($primary['dob'])),
              ],
              'currentAddress' => [
                'line1' => strtoupper($primary['address']),
                'city' => strtoupper($primary['city']),
                'state' => strtoupper($primary['state']),
                'zipCode' => $primary['zip'],
              ]
            ],
          ],
          'requestor' => [
            'subscriberCode' => $this->config['subscriber_code'] ?? '',
          ],
          'addOns' => [
            'riskModels' => [
              'modelIndicator' => [
                $this->config['model_indicator'] ?? 'V4' // Default to VantageScore 4
              ],
              'scorePercentile' => 'Y'
            ],
            'mla' => 'Y',
            'fraudShield' => 'N',
            'ofacmsg' => 'Y',
            'paymentHistory84' => 'Y',
            'outputType' => 'PARALLELPROFILE'
          ],
          'permissiblePurpose' => [
            'type' => '3F' // Application for credit
          ]
        ];

        // Add SSN only if provided
        if (!empty($primary['ssn'])) {
            $payload['consumerPii']['primaryApplicant']['ssn'] = [
              'ssn' => str_replace("-", "", $primary['ssn'])
            ];
        }

        // Add phone if provided
        if (!empty($primary['phone'])) {
            $payload['consumerPii']['primaryApplicant']['phone'] = [
              ['number' => $primary['phone']]
            ];
        }

        // Add co-applicant if present
        if ($hasCoApp) {
            $secondary = $consumers[1];
            $payload['consumerPii']['secondaryApplicant'] = [
              'name' => [
                'firstName' => $secondary['firstName'],
                'lastName' => $secondary['lastName'],
              ],
              'dob' => [
                'dob' => date("mdY", strtotime($secondary['dob'])),
              ],
              'currentAddress' => [
                'line1' => strtoupper($secondary['address']),
                'city' => strtoupper($secondary['city']),
                'state' => strtoupper($secondary['state']),
                'zipCode' => $secondary['zip'],
              ]
            ];

            if (!empty($secondary['ssn'])) {
                $payload['consumerPii']['secondaryApplicant']['ssn'] = [
                  'ssn' => str_replace("-", "", $secondary['ssn'])
                ];
            }

            if (!empty($secondary['phone'])) {
                $payload['consumerPii']['secondaryApplicant']['phone'] = [
                  ['number' => $secondary['phone']]
                ];
            }
        }

        return $payload;
    }

    /**
     * @throws \WF\API\Automation\Exceptions\BureauApiException
     */
    protected function makeRequest(array $payload): array
    {
        try {
            $headers = [
              'Content-Type: application/json',
              'Accept: application/json',
              'Authorization: Bearer ' . $this->accessToken
            ];

            $response = Curl::post(
              $this->config['report_endpoint'],
              json_encode($payload),
              $headers,
              null,
              true
            );

            // Handle response
            if (is_array($response) && isset($response['http_code'])) {
                $errorMsg = "HTTP code: " . $response['http_code'];
                $responseData = json_decode($response['response'] ?? '', true);

                if (isset($responseData['errors'])) {
                    foreach ($responseData['errors'] as $error) {
                        $errorMsg .= ", Error Code: " . $error['errorCode'] . ", " . $error['message'];
                    }
                }

                throw new BureauApiException('Experian request failed: ' . $errorMsg);
            }

            $data = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new BureauApiException('Invalid JSON response from Experian');
            }

            return $data;

        } catch (\Exception $e) {
            Log::error($e);
            throw new BureauApiException('Experian request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getName(): string
    {
        return 'Experian';
    }

    /**
     * Get the configured score model (FICO or VANTAGE)
     */
    public function getScoreModel(): string
    {
        $modelIndicator = $this->config['model_indicator'] ?? 'V4';

        // Check if it's a FICO model
        if (in_array($modelIndicator, ['AB', 'AD', 'AA', 'AF', 'F9', 'FX', 'FT'])) {
            return 'FICO';
        }

        return 'VANTAGE';
    }

    private ?string $refreshToken = null;
}