# PreQual Services Usage Guide

This guide covers how to use the PreQual (Pre-Qualification) services in the WildFire Automation system, both via dependency injection and through HTTP endpoints.

## Table of Contents
- [Overview](#overview)
- [Configuration](#configuration)
- [Using Services via Dependency Injection](#using-services-via-dependency-injection)
- [Using HTTP Endpoints](#using-http-endpoints)
- [Request/Response Examples](#requestresponse-examples)
- [Error Handling](#error-handling)
- [Testing](#testing)

## Overview

The PreQual system provides automated credit pre-qualification services for auto loans. It includes:
- Credit bureau integration (Equifax, Experian, TransUnion)
- Vehicle valuation services (NADA, KBB, JD Power)
- Risk scoring and lender matching
- Caching capabilities for bureau responses

## Configuration

### Environment Variables

Configure these in your environment or `env` file:

```bash
# Equifax Configuration
EQUIFAX_CLIENT_ID=your_client_id
EQUIFAX_CLIENT_SECRET=your_client_secret
EQUIFAX_TOKEN_ENDPOINT=https://api.equifax.com/oauth/token
EQUIFAX_REPORT_ENDPOINT=https://api.equifax.com/consumer-credit/v1/reports
EQUIFAX_MEMBER_NUMBER=your_member_number
EQUIFAX_SECURITY_CODE=your_security_code

# Experian Configuration
EXPERIAN_CLIENT_ID=your_client_id
EXPERIAN_CLIENT_SECRET=your_client_secret
EXPERIAN_USER=your_username
EXPERIAN_PASS=your_password
EXPERIAN_TOKEN_EP=https://us-api.experian.com/oauth2/v1/token
EXPERIAN_EP=https://us-api.experian.com/consumerservices/credit-profile/v2/credit-report
EXPERIAN_SUBSCRIBER_CODE=your_subscriber_code

# TransUnion Configuration
TU_ENDPOINT=https://api.transunion.com/credit-report
TU_MEMBER_CODE=your_member_code
TU_CERTIFICATE_PATH=/path/to/certificate.p12
TU_CERTIFICATE_PWD=certificate_password

# NADA Configuration
NADA_API_KEY=your_nada_key
NADA_ENDPOINT=https://api.nada.com/v1

```

## Using Services via Dependency Injection

### Bootstrap the DI Container

```php
// bootstrap.php
require __DIR__ . '/vendor/autoload.php';

use DI\ContainerBuilder;

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/config/di.php');
$container = $containerBuilder->build();
```

### 1. Direct Service Usage

```php
use WF\API\Automation\AutomationService;
use WF\API\Automation\Models\Applicant;
use WF\API\Automation\Models\Vehicle;

// Get the service from container
$automationService = $container->get(AutomationService::class);

// Create applicant data
$applicantData = [
    'monthly_income' => 5000,
    'monthly_debt' => 1500,
    'employment_type' => 'W2',
    'state' => 'TX',
    'first_name' => 'John',
    'last_name' => 'Doe',
    'ssn' => '123456789',
    'address' => '123 Main St',
    'city' => 'Dallas',
    'zip_code' => '75201',
    'date_of_birth' => '1985-01-15'
];

// Create vehicle data
$vehicleData = [
    'vin' => '1HGCM82633A123456',
    'year' => 2020,
    'make' => 'Honda',
    'model' => 'Accord',
    'mileage' => 25000,
    'loan_amount' => 20000,
    'condition' => 'good'
];

// Process pre-qualification
try {
    $result = $automationService->processPreQual([
        'applicant' => $applicantData,
        'vehicle' => $vehicleData,
        'preferred_bureau' => 'experian',
        'use_cache' => true
    ]);
    
    if ($result->isApproved()) {
        echo "Approved! Risk Tier: " . $result->getRiskTier() . "\n";
        echo "Matched Lenders: " . implode(', ', $result->getMatchedLenders()) . "\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

### 2. Using Individual Components

```php
use WF\API\Automation\Factories\BureauClientFactory;
use WF\API\Automation\Factories\CreditParserFactory;
use WF\API\Automation\Services\BureauCacheService;

// Get factories and services
$bureauFactory = $container->get(BureauClientFactory::class);
$parserFactory = $container->get(CreditParserFactory::class);
$cacheService = $container->get(BureauCacheService::class);

// Check available bureaus
$availableBureaus = $bureauFactory->getAvailableBureaus();
echo "Available bureaus: " . implode(', ', $availableBureaus) . "\n";

// Pull credit report directly
$bureau = 'experian';
$client = $bureauFactory->create($bureau);
$parser = $parserFactory->create($bureau);

$consumers = [[
    'firstName' => 'John',
    'lastName' => 'Doe',
    'ssn' => '123456789',
    'address' => '123 Main St',
    'city' => 'Dallas',
    'state' => 'TX',
    'zip' => '75201',
    'dob' => '1985-01-15'
]];

$rawResponse = $client->pullCreditReport($consumers);
$creditProfile = $parser->parse($rawResponse);

// Cache the result
$cacheService->set('123456789', $bureau, $creditProfile);
```

### 3. Vehicle Valuation

```php
use WF\API\Automation\Factories\ValuationProviderFactory;

$valuationFactory = $container->get(ValuationProviderFactory::class);

// Get specific provider
$nadaProvider = $valuationFactory->create('nada');
$valuation = $nadaProvider->getValuation(
    '1HGCM82633A123456', // VIN
    25000,               // Mileage
    '75201',            // Zip code
    'good'              // Condition
);

echo "Vehicle Value: $" . number_format($valuation['value'], 2) . "\n";

// Or use best available provider
$bestProvider = $valuationFactory->createBest();
$valuation = $bestProvider->getValuation($vin, $mileage, $zipCode);
```

## Using HTTP Endpoints

### Available Routes

```php
// From config/routes.php
POST /api/automation/prequal    // Modern pre-qualification API
POST /api/wildfire/prequal      // WildFire LOS integration
POST /api/wildfire/pull         // Direct bureau pull
POST /api/valuation/vin         // VIN-based valuation
POST /api/valuation/ymm         // Year/Make/Model valuation
GET  /api/health               // Health check
```

### Making HTTP Requests

All requests require authentication headers:

```http
X-Gravity-Auth-Token: your_auth_token
X-Gravity-User: user@gravitylending.com
Content-Type: application/json
```

### 1. Modern Pre-Qualification API

```bash
curl -X POST https://api.example.com/api/automation/prequal \
  -H "X-Gravity-Auth-Token: your_token" \
  -H "X-Gravity-User: user@gravitylending.com" \
  -H "Content-Type: application/json" \
  -d '{
    "applicant": {
      "monthly_income": 5000,
      "monthly_debt": 1500,
      "employment_type": "W2",
      "state": "TX",
      "first_name": "John",
      "last_name": "Doe",
      "ssn": "123456789",
      "address": "123 Main St",
      "city": "Dallas",
      "zip_code": "75201",
      "date_of_birth": "1985-01-15"
    },
    "vehicle": {
      "vin": "1HGCM82633A123456",
      "year": 2020,
      "make": "Honda",
      "model": "Accord",
      "mileage": 25000,
      "loan_amount": 20000,
      "condition": "good"
    },
    "preferred_bureau": "experian",
    "use_cache": true
  }'
```

Response:
```json
{
  "success": true,
  "data": {
    "approval_score": 0.825,
    "risk_tier": "A",
    "matched_lenders": ["Prime Auto Lender"],
    "is_complete": true,
    "missing_reason": null,
    "fico_score": 720,
    "ltv": 0.85,
    "dti": 0.3,
    "is_approved": true,
    "metadata": {
      "bureau_used": "experian",
      "processing_time": 2.345,
      "from_cache": false
    }
  },
  "error": ""
}
```

### 2. WildFire LOS Integration

```bash
curl -X POST https://api.example.com/api/wildfire/prequal \
  -H "X-Gravity-Auth-Token: your_token" \
  -H "X-Gravity-User: user@gravitylending.com" \
  -H "Content-Type: application/json" \
  -d '{
    "applicant_first_name": "John",
    "applicant_last_name": "Doe",
    "applicant_ssn": "123456789",
    "applicant_dob": "01/15/1985",
    "applicant_address": "123 Main St",
    "applicant_city": "Dallas",
    "applicant_state": "TX",
    "applicant_zip": "75201",
    "total_primary_income": "5000",
    "total_debt": "1500",
    "applicant_employer_employment_type": "EMPLOYED",
    "vehicle_vin": "1HGCM82633A123456",
    "vehicle_year": "2020",
    "vehicle_make": "Honda",
    "vehicle_model": "Accord",
    "vehicle_miles": "25000",
    "data_requested_amt": "20000",
    "bureau_primary_active_bureau": "experian"
  }'
```

### 3. Vehicle Valuation

```bash
# VIN-based valuation
curl -X POST https://api.example.com/api/valuation/vin \
  -H "X-Gravity-Auth-Token: your_token" \
  -H "X-Gravity-User: user@gravitylending.com" \
  -H "Content-Type: application/json" \
  -d '{
    "nada": {
      "VIN": "1HGCM82633A123456",
      "state": "TX",
      "mileage": "25000"
    }
  }'

# Year/Make/Model valuation
curl -X POST https://api.example.com/api/valuation/ymm \
  -H "X-Gravity-Auth-Token: your_token" \
  -H "X-Gravity-User: user@gravitylending.com" \
  -H "Content-Type: application/json" \
  -d '{
    "nada": {
      "year": "2020",
      "make": "Honda",
      "model": "Accord",
      "trim": "EX",
      "state": "TX",
      "mileage": "25000"
    }
  }'
```

## Request/Response Examples

### Co-Applicant Support

```json
{
  "applicant": {
    "monthly_income": 5000,
    "employment_type": "W2",
    "state": "TX",
    "first_name": "John",
    "last_name": "Doe",
    "ssn": "123456789",
    "address": "123 Main St",
    "city": "Dallas",
    "zip_code": "75201",
    "date_of_birth": "1985-01-15",
    "co_applicant": {
      "first_name": "Jane",
      "last_name": "Doe",
      "ssn": "987654321",
      "dob": "1987-03-20",
      "address": "123 Main St",
      "city": "Dallas",
      "state": "TX",
      "zip": "75201",
      "employment_type": "W2",
      "monthly_income": 4000
    }
  },
  "vehicle": {
    "vin": "1HGCM82633A123456",
    "year": 2020,
    "make": "Honda",
    "model": "Accord",
    "mileage": 25000,
    "loan_amount": 20000
  }
}
```

### Skip Bureau Pull (Use Provided Data)

```json
{
  "applicant": {...},
  "vehicle": {...},
  "skip_bureau_pull": true,
  "credit_profile": {
    "fico_score": 720,
    "bureau": "experian",
    "open_trade_count": 5,
    "auto_trade_count": 1,
    "derogatory_marks": 0,
    "bankruptcies": 0,
    "revolving_utilization": 0.25,
    "inquiries_6mo": 2,
    "estimated_monthly_debt": 1500
  }
}
```

### Skip Valuation (Use Provided Value)

```json
{
  "applicant": {...},
  "vehicle": {
    "vin": "1HGCM82633A123456",
    "year": 2020,
    "make": "Honda",
    "model": "Accord",
    "mileage": 25000,
    "loan_amount": 20000
  },
  "skip_valuation": true,
  "vehicle_valuation": {
    "value": 23500,
    "source": "manual"
  }
}
```

## Error Handling

### Common Error Responses

```json
{
  "success": false,
  "data": [],
  "error": "Validation error: Monthly income cannot be negative"
}
```

### Exception Types

- `ValidationException`: Invalid input data
- `BureauApiException`: Bureau communication errors
- `ValuationException`: Vehicle valuation errors
- `AutomationException`: General processing errors

### Error Handling Example

```php
use WF\API\Automation\Exceptions\ValidationException;
use WF\API\Automation\Exceptions\BureauApiException;

try {
    $result = $automationService->processPreQual($requestData);
} catch (ValidationException $e) {
    // Handle validation errors
    Log::warn("Validation failed: " . $e->getMessage());
    return ['success' => false, 'error' => $e->getMessage()];
} catch (BureauApiException $e) {
    // Handle bureau errors
    Log::error("Bureau API error: " . $e->getMessage());
    return ['success' => false, 'error' => 'Credit bureau unavailable'];
} catch (\Exception $e) {
    // Handle other errors
    Log::error("Unexpected error: " . $e->getMessage());
    return ['success' => false, 'error' => 'Internal server error'];
}
```

## Testing

### Unit Testing with DI

```php
use PHPUnit\Framework\TestCase;
use WF\API\Automation\AutomationService;

class PreQualTest extends TestCase
{
    private $container;
    private $automationService;
    
    protected function setUp(): void
    {
        // Create test container with mocked dependencies
        $this->container = require __DIR__ . '/test-bootstrap.php';
        $this->automationService = $this->container->get(AutomationService::class);
    }
    
    public function testSuccessfulPreQual()
    {
        $requestData = [
            'applicant' => [...],
            'vehicle' => [...],
            'skip_bureau_pull' => true,
            'credit_profile' => [
                'fico_score' => 720,
                'bureau' => 'test'
            ]
        ];
        
        $result = $this->automationService->processPreQual($requestData);
        
        $this->assertTrue($result->isApproved());
        $this->assertEquals('A', $result->getRiskTier());
    }
}
```

### Integration Testing Endpoints

```bash
# Health check
curl -X GET https://api.example.com/api/health \
  -H "X-Gravity-Auth-Token: your_token"

# Test with minimal data
curl -X POST https://api.example.com/api/automation/prequal \
  -H "X-Gravity-Auth-Token: your_token" \
  -H "X-Gravity-User: test@gravitylending.com" \
  -H "Content-Type: application/json" \
  -d '{
    "applicant": {
      "monthly_income": 5000,
      "employment_type": "W2",
      "state": "TX",
      "first_name": "Test",
      "last_name": "User",
      "ssn": "000000000",
      "address": "Test St",
      "city": "Dallas",
      "zip_code": "75201",
      "date_of_birth": "1990-01-01"
    },
    "vehicle": {
      "vin": "00000000000000000",
      "year": 2020,
      "make": "Test",
      "model": "Car",
      "mileage": 10000,
      "loan_amount": 10000
    },
    "skip_bureau_pull": true,
    "skip_valuation": true,
    "credit_profile": {
      "fico_score": 700,
      "bureau": "test"
    },
    "vehicle_valuation": {
      "value": 15000
    }
  }'
```

This completes the comprehensive usage guide for the PreQual services. The system supports both programmatic usage via dependency injection and HTTP API access, with extensive configuration options and error handling capabilities.