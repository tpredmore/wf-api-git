<?php

declare(strict_types=1);

namespace WF\API\Automation\Clients;

use WF\API\Automation\Exceptions\BureauApiException;
use SimpleXMLElement;
use Log;

class TransUnionClient extends AbstractBureauClient
{
    private string $certificatePath = '';
    private string $certificatePassword = '';

    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->certificatePath = $config['certificate_path'] ?? '';
        $this->certificatePassword = $config['certificate_password'] ?? '';
    }

    /**
     * TransUnion uses certificate-based authentication
     */
    protected function authenticate(): string
    {
        // TransUnion doesn't use token-based auth, just certificate
        // Return a dummy token to satisfy parent class
        return 'certificate-auth';
    }

    protected function buildRequestPayload(array $consumers): array
    {
        $primary = $consumers[0];

        // Build subject record
        $subjectRecord = [
          'indicative' => [
            'name' => [
              'person' => [
                'first' => strtoupper($primary['firstName']),
                'middle' => strtoupper($primary['middleName'] ?? ''),
                'last' => strtoupper($primary['lastName']),
                'generationalSuffix' => ''
              ]
            ],
            'dateOfBirth' => date('Y-m-d', strtotime($primary['dob'])),
            'address' => [
              'current' => [
                'status' => 'current',
                'street' => [
                  'unparsed' => strtoupper($primary['address'])
                ],
                'location' => [
                  'city' => strtoupper($primary['city']),
                  'state' => strtoupper($primary['state']),
                  'zipCode' => $primary['zip']
                ]
              ]
            ],
            'socialSecurity' => [
              'number' => str_replace('-', '', $primary['ssn'] ?? '')
            ]
          ]
        ];

        // Add phone if provided
        if (!empty($primary['phone'])) {
            $phoneParts = explode('-', $primary['phone']);
            if (count($phoneParts) === 3) {
                $subjectRecord['indicative']['phone-primary'] = [
                  'number' => [
                    'areaCode' => $phoneParts[0],
                    'exchange' => $phoneParts[1],
                    'suffix' => $phoneParts[2]
                  ]
                ];
            }
        }

        return [
          'subscriberInfo' => [
            'industryCode' => $this->config['industry_code'] ?? '',
            'memberCode' => $this->config['member_code'] ?? '',
            'inquirySubscriberPrefixCode' => $this->config['subscriber_prefix'] ?? '',
            'password' => $this->config['password'] ?? '',
            'processingEnvironment' => $this->config['processing_environment'] ?? 'production'
          ],
          'subjectRecord' => $subjectRecord
        ];
    }

    /**
     * @throws BureauApiException
     */
    protected function makeRequest(array $payload): array
    {
        try {
            $xmlRequest = $this->buildXmlRequest($payload);

            Log::info("Making TransUnion request to " . $this->config['endpoint']);

            $ch = curl_init();

            // Set certificate options
            if (!file_exists($this->certificatePath)) {
                throw new BureauApiException('TransUnion certificate file not found: ' . $this->certificatePath);
            }

            curl_setopt($ch, CURLOPT_URL, $this->config['endpoint']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlRequest);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'P12');
            curl_setopt($ch, CURLOPT_SSLCERT, $this->certificatePath);
            curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $this->certificatePassword);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
              'Content-Type: text/xml',
              'Content-Length: ' . strlen($xmlRequest)
            ]);

            if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
                curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

            if ($response === false) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new BureauApiException('TransUnion request failed: ' . $error);
            }

            curl_close($ch);

            // Extract body from response
            $body = substr($response, $headerSize);

            if ($httpCode > 201) {
                throw new BureauApiException(
                  'TransUnion request failed with HTTP ' . $httpCode . ': ' . $body
                );
            }

            // Parse XML response
            $xml = new SimpleXMLElement($body);

            // Check for errors in response
            if (isset($xml->product->error)) {
                throw new BureauApiException(
                  'TransUnion error: ' . $xml->product->error->description
                );
            }

            // Convert SimpleXML to array for consistent handling
            return json_decode(json_encode($xml), true);

        } catch (\Exception $e) {
            Log::error("TransUnion request failed: " . $e->getMessage());
            throw new BureauApiException('TransUnion request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Build XML request from payload array
     */
    private function buildXmlRequest(array $payload): string
    {
        $subscriberInfo = $payload['subscriberInfo'];
        $subjectRecord = $payload['subjectRecord'];
        $indicative = $subjectRecord['indicative'];

        $xml = '<?xml version="1.0" encoding="utf-8"?>';
        $xml .= '
<creditBureau xmlns="http://www.transunion.com/namespace">
  <document>request</document>
  <version>2.38</version>
  <transactionControl>
    <userRefNumber>Credit Report 0</userRefNumber>
    <subscriber>
      <industryCode>' . $subscriberInfo['industryCode'] . '</industryCode>
      <memberCode>' . $subscriberInfo['memberCode'] . '</memberCode>
      <inquirySubscriberPrefixCode>' . $subscriberInfo['inquirySubscriberPrefixCode'] . '</inquirySubscriberPrefixCode>
      <password>' . $subscriberInfo['password'] . '</password>
    </subscriber>
    <options>
      <processingEnvironment>' . $subscriberInfo['processingEnvironment'] . '</processingEnvironment>
      <country>us</country>
      <language>en</language>
      <contractualRelationship>individual</contractualRelationship>
    </options>
  </transactionControl>
  <product>
    <code>07000</code>
    <subject>
      <number>1</number>
      <subjectRecord>
        <indicative>
          <name>
            <person>
              <first>' . $indicative['name']['person']['first'] . '</first>
              <middle>' . $indicative['name']['person']['middle'] . '</middle>
              <last>' . $indicative['name']['person']['last'] . '</last>
              <generationalSuffix>' . $indicative['name']['person']['generationalSuffix'] . '</generationalSuffix>
            </person>
          </name>
          <dateOfBirth>' . $indicative['dateOfBirth'] . '</dateOfBirth>
          <address>
            <status>' . $indicative['address']['current']['status'] . '</status>
            <street>
              <unparsed>' . $indicative['address']['current']['street']['unparsed'] . '</unparsed>
            </street>
            <location>
              <city>' . $indicative['address']['current']['location']['city'] . '</city>
              <state>' . $indicative['address']['current']['location']['state'] . '</state>
              <zipCode>' . $indicative['address']['current']['location']['zipCode'] . '</zipCode>
            </location>
          </address>';

        // Add phone if present
        if (isset($indicative['phone-primary'])) {
            $phone = $indicative['phone-primary']['number'];
            $xml .= '
          <phone>
            <number>
              <areaCode>' . $phone['areaCode'] . '</areaCode>
              <exchange>' . $phone['exchange'] . '</exchange>
              <suffix>' . $phone['suffix'] . '</suffix>
            </number>
          </phone>';
        }

        $xml .= '
          <socialSecurity>
            <number>' . $indicative['socialSecurity']['number'] . '</number>
          </socialSecurity>
        </indicative>
      </subjectRecord>
    </subject>
    <responseInstructions>
        <returnErrorText>true</returnErrorText>
        <document/>
        <printImage>cutSheetWithHeaders</printImage>
        <embeddedData>tu41</embeddedData>
    </responseInstructions>
    <permissiblePurpose>
      <inquiryECOADesignator>individual</inquiryECOADesignator>
    </permissiblePurpose>
  </product>
</creditBureau>';

        return $xml;
    }

    public function getName(): string
    {
        return 'TransUnion';
    }

    public function isAvailable(): bool
    {
        return !empty($this->certificatePath) &&
          file_exists($this->certificatePath) &&
          !empty($this->config['endpoint']);
    }
}