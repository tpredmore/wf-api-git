<?php

declare(strict_types=1);

namespace WF\API\Automation\Services;

use WF\API\Automation\Exceptions\ValidationException;
use WF\API\Automation\Models\Applicant;

/**
 * Builds bureau-specific request payloads from applicant data
 */
class BureauRequestBuilder
{
    public function buildEquifaxRequest(Applicant $applicant, string $scoreModel = 'VANTAGE'): array
    {
        $consumers = [
          'name' => [
            [
              'identifier' => 'Current',
              'firstName' => $applicant->firstName,
              'lastName' => $applicant->lastName
            ]
          ],
          'dateOfBirth' => $this->formatDateForEquifax($applicant->dateOfBirth),
          'addresses' => [
            [
              'identifier' => 'Current',
              'city' => $applicant->city,
              'state' => $applicant->state,
              'zip' => $applicant->zipCode
            ]
          ]
        ];

        // Add SSN if available
        if (!empty($applicant->ssn)) {
            $consumers['socialNum'] = [
              [
                'identifier' => 'Current',
                'number' => str_replace('-', '', $applicant->ssn)
              ]
            ];
        }

        // Add co-applicant if present
        if ($applicant->hasCoApplicant()) {
            $coApp = $applicant->coApplicant;

            $consumers['name'][] = [
              'identifier' => 'Co-applicant',
              'firstName' => $coApp['first_name'],
              'lastName' => $coApp['last_name']
            ];

            if (!empty($coApp['ssn'])) {
                $consumers['socialNum'][] = [
                  'identifier' => 'Co-applicant',
                  'number' => str_replace('-', '', $coApp['ssn'])
                ];
            }

            $consumers['addresses'][] = [
              'identifier' => 'Co-applicant',
              'city' => $coApp['city'],
              'state' => $coApp['state'],
              'zip' => $coApp['zip']
            ];
        }

        return [
          'consumers' => $consumers,
          'customerReferenceIdentifier' => 'JSON',
          'customerConfiguration' => [
            'equifaxUSConsumerCreditReport' => [
              'memberNumber' => $_ENV['EQUIFAX_MEMBER_NUMBER'],
              'securityCode' => $_ENV['EQUIFAX_SECURITY_CODE'],
              'codeDescriptionRequired' => true,
              'ECOAInquiryType' => $applicant->hasCoApplicant() ? 'Co-applicant' : 'Individual',
              'models' => [['identifier' => $this->getEquifaxModelId($scoreModel)]],
              'optionalFeatureCode' => [$this->getEquifaxFeatureCode($scoreModel)],
              'vendorIdentificationCode' => 'FI',
              'pdfComboIndicator' => 'N'
            ]
          ]
        ];
    }


    /**
     * Build Experian-specific request payload
     *
     * @param Applicant $applicant The applicant data
     * @param string $scoreModel FICO or VANTAGE (default: VANTAGE)
     * @return array The formatted Experian request
     */
    public function buildExperianRequest(Applicant $applicant, string $scoreModel = 'VANTAGE'): array
    {
        // Determine model indicator based on score model preference
        $modelIndicator = match (strtoupper($scoreModel)) {
            'FICO' => $_ENV['EXPERIAN_FICO_MODEL'] ?? 'AF', // FICO v8
            'VANTAGE' => $_ENV['EXPERIAN_VANTAGE_MODEL'] ?? 'V4', // VantageScore 4
            default => 'V4'
        };

        $payload = [
          'consumerPii' => [
            'primaryApplicant' => [
              'name' => [
                'firstName' => $applicant->firstName,
                'lastName' => $applicant->lastName,
              ],
              'dob' => [
                'dob' => $this->formatDateForExperian($applicant->dateOfBirth),
              ],
              'currentAddress' => [
                'line1' => strtoupper($applicant->address),
                'city' => strtoupper($applicant->city),
                'state' => strtoupper($applicant->state),
                'zipCode' => $applicant->zipCode,
              ]
            ],
          ],
          'requestor' => [
            'subscriberCode' => $_ENV['EXPERIAN_SUBSCRIBER_CODE'] ?? '',
          ],
          'addOns' => [
            'riskModels' => [
              'modelIndicator' => [$modelIndicator],
              'scorePercentile' => 'Y'
            ],
            'mla' => 'Y',
            'fraudShield' => 'N',
            'ofacmsg' => 'Y',
            'paymentHistory84' => 'Y',
            'outputType' => 'PARALLELPROFILE'
          ],
          'permissiblePurpose' => [
            'type' => '3F' // Application for credit - constant per Experian API
          ]
        ];

        // Add SSN only if provided
        if (!empty($applicant->ssn)) {
            $payload['consumerPii']['primaryApplicant']['ssn'] = [
              'ssn' => str_replace('-', '', $applicant->ssn)
            ];
        }

        // Add phone if available (not in standard Applicant model, but can be extended)
        // This would need to be added to the Applicant model or passed separately

        // Add co-applicant if present
        if ($applicant->hasCoApplicant()) {
            $coApp = $applicant->coApplicant;

            $payload['consumerPii']['secondaryApplicant'] = [
              'name' => [
                'firstName' => $coApp['first_name'],
                'lastName' => $coApp['last_name'],
              ],
              'dob' => [
                'dob' => $this->formatDateForExperian($coApp['dob']),
              ],
              'currentAddress' => [
                'line1' => strtoupper($coApp['address']),
                'city' => strtoupper($coApp['city']),
                'state' => strtoupper($coApp['state']),
                'zipCode' => $coApp['zip'],
              ]
            ];

            if (!empty($coApp['ssn'])) {
                $payload['consumerPii']['secondaryApplicant']['ssn'] = [
                  'ssn' => str_replace('-', '', $coApp['ssn'])
                ];
            }
        }

        return $payload;
    }

    public function buildTransUnionRequest(Applicant $applicant): array
    {
        return [
          'accountName' => $_ENV['TRANSUNION_ACCOUNT_NAME'] ?? '',
          'accountNumber' => $_ENV['TRANSUNION_ACCOUNT_NUMBER'] ?? '',
          'memberCode' => $_ENV['TRANSUNION_MEMBER_CODE'] ?? '',
          'subject' => [
            'name' => [
              'unparsedName' => $applicant->firstName . ' ' . $applicant->lastName,
            ],
            'ssn' => $applicant->ssn,
            'dateOfBirth' => $applicant->dateOfBirth,
            'address' => [
              'streetName' => $applicant->address,
              'city' => $applicant->city,
              'state' => $applicant->state,
              'zipCode' => $applicant->zipCode,
            ],
          ],
          'type' => 'identity',
          'showVantageScore' => true,
        ];
    }

    private function formatDateForEquifax(string $date): string
    {
        // Convert YYYY-MM-DD to MMDDYYYY
        $dateObj = \DateTime::createFromFormat('Y-m-d', $date);
        return $dateObj ? $dateObj->format('mdY') : '';
    }

    private function getEquifaxModelId(string $scoreModel): string
    {
        return match (strtoupper($scoreModel)) {
            'FICO' => '05483',
            'VANTAGE' => '05402',
            default => '05402'
        };
    }

    private function getEquifaxFeatureCode(string $scoreModel): string
    {
        return match (strtoupper($scoreModel)) {
            'FICO' => 'V', // EDAS & FICO Score based on Equifax Data
            'VANTAGE' => 'Z', // Enhanced Delinquency Alert System (EDAS)
            default => 'Z'
        };
    }

    /**
     * Format date for Experian (MMDDYYYY format)
     */
    private function formatDateForExperian(string $date): string
    {
        // Convert from YYYY-MM-DD to MMDDYYYY
        $dateObj = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$dateObj) {
            // Try other common formats
            $dateObj = \DateTime::createFromFormat('m/d/Y', $date) ?:
              \DateTime::createFromFormat('m-d-Y', $date);
        }

        return $dateObj ? $dateObj->format('mdY') : '';
    }

    /**
     * Get the appropriate Experian model indicator
     */
    public function getExperianModelIndicator(string $scoreModel): string
    {
        return match (strtoupper($scoreModel)) {
            'FICO' => $_ENV['EXPERIAN_FICO_MODEL'] ?? 'AF',
            'VANTAGE' => $_ENV['EXPERIAN_VANTAGE_MODEL'] ?? 'V4',
            default => 'V4'
        };
    }

    /**
     * Validate Experian request requirements
     *
     * @throws ValidationException if required, fields are missing
     */
    public function validateExperianRequest(Applicant $applicant): void
    {
        // ZIP code is required for Experian
        if (empty($applicant->zipCode)) {
            throw new ValidationException(
              'ZIP code is required for Experian credit pull'
            );
        }

        // Last name is required
        if (empty($applicant->lastName)) {
            throw new ValidationException(
              'Last name is required for Experian credit pull'
            );
        }

        // Either SSN or full address is required
        if (empty($applicant->ssn) && (empty($applicant->address) || empty($applicant->city) || empty($applicant->state))) {
            throw new ValidationException(
              'Either SSN or complete address (street, city, state) is required for Experian credit pull'
            );
        }
    }
}
