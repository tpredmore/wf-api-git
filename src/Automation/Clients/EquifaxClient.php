<?php

declare(strict_types=1);

namespace WF\API\Automation\Clients;

use WF\API\Automation\Exceptions\BureauApiException;
use Curl;
use Log;

class EquifaxClient extends AbstractBureauClient
{

    /**
     * @throws \WF\API\Automation\Exceptions\BureauApiException
     */
    protected function authenticate(): string
    {
        try {
            // Prepare form data
            $formData = http_build_query([
              'grant_type' => 'client_credentials',
              'scope' => $this->config['client_scope'] ?? 'https://api.equifax.com/business/consumer-credit/v1'
            ]);

            // Headers need to be an array of strings
            $headers = [
              'Authorization: Basic ' . base64_encode($this->config['client_id'] . ':' . $this->config['client_secret']),
              'Content-Type: application/x-www-form-urlencoded'
            ];

            $response = Curl::post(
              $this->config['token_endpoint'],
              $formData,
              $headers,
              null,
              true // return on error
            );

            // Handle response
            if (is_array($response) && isset($response['http_code'])) {
                // Error response
                throw new BureauApiException(
                  'Equifax authentication failed with HTTP ' . $response['http_code'] . ': ' . $response['response']
                );
            }

            $data = json_decode($response, true);

            if (!isset($data['access_token'])) {
                throw new BureauApiException('Failed to obtain Equifax access token: ' . $response);
            }

            return $data['access_token'];

        } catch (\Exception $e) {
            Log::error($e);
            throw new BureauApiException('Equifax authentication failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function buildRequestPayload(array $consumers): array
    {
        return [
          'consumers' => $consumers,
          'customerReferenceIdentifier' => 'JSON',
          'customerConfiguration' => [
            'equifaxUSConsumerCreditReport' => [
              'memberNumber' => $this->config['member_number'],
              'securityCode' => $this->config['security_code'],
              'codeDescriptionRequired' => true,
              'ECOAInquiryType' => count($consumers) > 1 ? 'Co-applicant' : 'Individual',
              'models' => [['identifier' => $this->config['model_id']]],
              'optionalFeatureCode' => [$this->config['optional_feature'] ?? 'Z'],
            ],
          ],
        ];
    }

    /**
     * @throws \WF\API\Automation\Exceptions\BureauApiException
     */
    protected function makeRequest(array $payload): array
    {
        try {
            $headers = [
              'Authorization: Bearer ' . $this->accessToken,
              'Content-Type: application/json',
              'Accept: application/json'
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
                // Error response
                throw new BureauApiException(
                  'Equifax request failed with HTTP ' . $response['http_code'] . ': ' . $response['response']
                );
            }

            $data = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new BureauApiException('Invalid JSON response from Equifax: ' . $response);
            }

            return $data;

        } catch (\Exception $e) {
            Log::error($e);
            throw new BureauApiException('Equifax request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getName(): string
    {
        return 'Equifax';
    }
}
